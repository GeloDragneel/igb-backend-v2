<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Shared by HRManagmentController and ProfileController:
 *   me()            the employee of the logged-in login
 *   loginName()     who is logged in (for "approved by")
 *   photoUrl()      2-hour S3 link of an employee photo
 *   storePhoto()    data URL → S3 Employee_Profile/ (old photo deleted)
 *   employeeRules() validation of the m_employee profile fields
 */
trait IdentifiesStaff
{
    /**
     * the employee of the logged-in user, first match wins:
     *   0) m_user_staff.comid = m_employee.com_id  ← how AuthController links a staff login
     *   1) a column of the login that points to m_employee (employee_id, emp_id, empid, m_employee_id, staff_id …)
     *   2) a column of m_employee that points to the login (user_staff_id, m_user_staff_id, user_id, login_id …)
     *   3) the ONE employee with the same name as the login
     */
    private function me(Request $request): ?int
    {
        return $this->employeeOfLogin($request->user());
    }

    private function employeeOfLogin($u): ?int
    {
        if (!$u) return null;
        $attrs = method_exists($u, 'getAttributes') ? $u->getAttributes() : (array) $u;

        // 0) staff login: m_user_staff.comid = m_employee.com_id (same as AuthController)
        $comid = $attrs['comid'] ?? null;
        if ($comid !== null && $comid !== '') {
            $found = (int) DB::table('m_employee')->where('com_id', $comid)->value('id');
            if ($found > 0) return $found;
        }

        // 1) login → employee
        $cols = array_keys($attrs);
        usort($cols, fn ($a, $b) => (int) (strtolower($b) === 'employee_id') <=> (int) (strtolower($a) === 'employee_id'));
        foreach ($cols as $col) {
            if (!preg_match('/^(m_)?(employee|emp|staff)_?(id|no|num)$|^empid$|^employeeid$/i', $col)) continue;
            $v = $attrs[$col];
            if ($v === null || $v === '' || !is_numeric($v) || (int) $v <= 0) continue;
            if (DB::table('m_employee')->where('id', (int) $v)->exists()) return (int) $v;
        }

        // 2) employee → login
        $empCols = Schema::getColumnListing('m_employee');
        foreach ($empCols as $col) {
            if (!preg_match('/^(m_)?(user_staff|userstaff|user|login|account)_?id$/i', $col)) continue;
            $found = (int) DB::table('m_employee')->where($col, $u->getKey() ?? ($attrs['id'] ?? 0))->value('id');
            if ($found > 0) return $found;
        }

        // 3) same name
        return $this->employeeByLoginName($u);
    }

    /** for the "not linked" message: the columns of the login (names only) */
    private function loginColumns($u): array
    {
        $attrs = method_exists($u, 'getAttributes') ? $u->getAttributes() : (array) $u;
        return array_values(array_filter(array_keys($attrs), fn ($k) => !preg_match('/password|token|secret|remember/i', $k)));
    }

    /** UPPER, no accents / punctuation, single spaces ("Cuñado, Grace" → "CUNADO GRACE") */
    private function normName(?string $v): string
    {
        $v = mb_strtoupper(trim((string) $v));
        $v = strtr($v, ['Ñ' => 'N', 'É' => 'E', 'Á' => 'A', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ', $v)));
    }

    /** the full name of a login (first + middle + last, or its name column) */
    private function loginFullName($u): string
    {
        $a = method_exists($u, 'getAttributes') ? $u->getAttributes() : (array) $u;
        $get = fn (array $keys) => collect($keys)->map(fn ($k) => trim((string) ($a[$k] ?? '')))->first(fn ($v) => $v !== '') ?? '';
        $first = $get(['first_name', 'firstname', 'fname', 'given_name']);
        $last = $get(['last_name', 'lastname', 'lname', 'surname']);
        $mid = $get(['middle_name', 'middlename', 'mname']);
        if ($first !== '' || $last !== '') return trim("{$last}, {$first} {$mid}", ' ,');
        $n = $get(['full_name', 'fullname', 'name', 'display_name', 'staff_name', 'employee_name', 'emp_name']);
        if ($n !== '') return $n;
        // any other column with "name" in it (not the username)
        foreach ($a as $k => $v) {
            if (is_string($v) && trim($v) !== '' && preg_match('/name/i', $k) && !preg_match('/user_?name|login|branch|business|trade/i', $k)) return trim($v);
        }
        return '';
    }

    /** a login not linked to an employee: the ONE employee with the same name (else null) */
    private function employeeByLoginName($u): ?int
    {
        $full = $this->normName($this->loginFullName($u));
        if ($full === '') return null;
        $words = array_values(array_filter(explode(' ', $full)));
        $hits = Employee::query()->get(['id', 'last_name', 'first_name', 'middle_name'])->filter(function ($e) use ($full, $words) {
            $l = $this->normName($e->last_name);
            $f = $this->normName($e->first_name);
            $m = $this->normName($e->middle_name);
            if ($l === '' || $f === '') return false;
            foreach (["{$l} {$f}", "{$l} {$f} {$m}", "{$f} {$l}", "{$f} {$m} {$l}"] as $try) {
                if (trim(preg_replace('/\s+/', ' ', $try)) === $full) return true;
            }
            // every word of the employee's last + first name is in the login's name
            $need = array_filter(explode(' ', "{$l} {$f}"));
            return !array_diff($need, $words);
        });
        return $hits->count() === 1 ? (int) $hits->first()->id : null;
    }

    /** who is logged in, for "approved by" (full name, else username) */
    private function loginName(Request $request): ?string
    {
        $u = $request->user();
        if (!$u) return null;
        $n = $this->loginFullName($u);
        return mb_substr($n !== '' ? $n : (string) ($u->username ?? $u->email ?? "User #{$u->id}"), 0, 150);
    }

    /** a 2-hour link to the photo on S3 (null when none or S3 not reachable) */
    private function photoUrl(?string $key): ?string
    {
        if (!$key) return null;
        try {
            return Storage::disk('s3')->temporaryUrl($key, now()->addHours(2));
        } catch (\Throwable) {
            return null;
        }
    }

    /** data URL → S3 Employee_Profile/{id}-{hash}.{ext}, the old photo is deleted · returns the S3 key */
    private function storePhoto(Employee $e, string $data): string
    {
        $id = $e->id;
        $disk = Storage::disk('s3');
        $old = $e->photo_path ?? null;
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/', (string) $data, $m)) abort(422, 'Upload a JPG, PNG or WEBP image.');
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 3 * 1024 * 1024) abort(422, 'The photo must be an image of at most 3 MB.');
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $key = 'Employee_Profile' . '/' . $id . '-' . substr(md5($bin), 0, 8) . '.' . $ext;
        $disk->put($key, $bin, ['ContentType' => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)]);
        if ($old && $old !== $key) $disk->delete($old);
        Employee::query()->whereKey($id)->update(['photo_path' => $key]); // photo_path is not in the model's $fillable
        return $key;
    }

    private function employeeRules(): array
    {
        $int = ['nullable', 'integer'];
        $str = fn ($n) => ['nullable', 'string', "max:{$n}"];
        return [
            'last_name' => ['required', 'string', 'max:100'], 'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => $str(100), 'suffix' => $str(20), 'birthdate' => ['nullable', 'date'], 'gender' => $str(20),
            'phone_num' => $str(50), 'tell_num' => $str(50), 'email_address' => ['nullable', 'email', 'max:150'], 'street' => $str(255),
            'birth_place_id' => $int, 'brgy_id' => $int, 'city_id' => $int, 'province_id' => $int,
            'civ_stat_id' => $int, 'religion_id' => $int, 'citizenship_id' => $int, 'designation_id' => $int,
            'biometric_num' => $str(50), 'date_contact' => ['nullable', 'date'], 'date_contact_exp' => ['nullable', 'date', 'after_or_equal:date_contact'],
            'employment_stat_id' => $int, 'company_caid' => $int, 'shift_id' => ['nullable', 'integer', 'exists:m_shift,id'], 'remarks' => $str(255),
            'phic_no' => $str(50), 'hdmf_no' => $str(50), 'sss_no' => $str(50), 'tin' => $str(50), 'mccb_acc' => $str(50),
            'allowance' => ['nullable', 'numeric', 'min:0'], 'is_active' => ['boolean'],
        ];
    }
}