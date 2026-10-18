<?php

namespace App\Http\Controllers\Api;
use App\Models\UserClientStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TeamController extends SalesController
{
    // "1-4-15" → [1, 4, 15]
    private function rightsToArray(?string $s): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode('-', (string) $s)))));
    }

    // is this username used anywhere else?
    private function usernameTaken(string $username, ?int $ignoreStaffId = null): bool
    {
        $u = trim($username);
        if (UserClientStaff::where('username', $u)->when($ignoreStaffId, fn ($q) => $q->where('id', '<>', $ignoreStaffId))->exists()) return true;
        foreach (['m_user_client', 'users'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'username') && DB::table($table)->where('username', $u)->exists()) return true;
        }
        return false;
    }

    private function member(UserClientStaff $m): array
    {
        return [
            'id'            => $m->id,
            'username'      => $m->username,
            'fullname'      => $m->fullname,
            'employee_id'   => $m->employee_id,
            'is_active'     => (bool) $m->is_active,
            'is_web'        => (bool) $m->is_web,
            'is_scan_suite' => (bool) $m->is_scan_suite,
            'rights'        => $this->rightsToArray($m->access_rights),
        ];
    }

    private function mine(Request $request, int $id): UserClientStaff
    {
        return UserClientStaff::where('id', $id)->where('user_client_id', $this->addedByFor($request))->firstOrFail();
    }

    /* GET /api/team  → members, employees, module tree */
    public function index(Request $request)
    {
        $members = UserClientStaff::where('user_client_id', $this->addedByFor($request))
            ->orderBy('username')->get()->map(fn ($m) => $this->member($m));

        $employees = DB::table('m_employee')->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'last_name', 'first_name', 'middle_name'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => trim("{$e->last_name} {$e->first_name} " . ($e->middle_name ?? ''))]);

        $menu = Schema::hasTable('m_menu_data') ? DB::table('m_menu_data')->orderBy('id')->get(['id', 'root_name', 'module_name']) : collect();
        $modules = $menu->where('root_name', 0)->values()->map(fn ($p) => [
            'id'       => $p->id,
            'name'     => $p->module_name,
            'children' => $menu->where('root_name', $p->id)->values()->map(fn ($c) => ['id' => $c->id, 'name' => $c->module_name])->values(),
        ]);

        return response()->json(['members' => $members, 'employees' => $employees, 'modules' => $modules]);
    }

    private function rules(bool $creating): array
    {
        return [
            'employee_id'   => ['nullable', 'integer', 'exists:m_employee,id'],
            'fullname'      => ['nullable', 'string', 'max:50'],
            'username'      => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password'      => [$creating ? 'required' : 'nullable', 'string', 'min:4', 'max:50'],
            'is_active'     => ['boolean'],
            'is_web'        => ['boolean'],
            'is_scan_suite' => ['boolean'],
            'rights'        => ['array'],
            'rights.*'      => ['integer'],
        ];
    }

    private function fill(UserClientStaff $m, array $d, Request $request): void
    {
        $name = $d['fullname'] ?? '';
        if (!empty($d['employee_id'])) {
            $e = DB::table('m_employee')->where('id', $d['employee_id'])->first(['last_name', 'first_name', 'middle_name']);
            if ($e) $name = trim("{$e->last_name} {$e->first_name} " . ($e->middle_name ?? ''));
        }

        $m->employee_id   = $d['employee_id'] ?? null;
        $m->fullname      = mb_substr($name, 0, 50);
        $m->username      = trim($d['username']);
        $m->is_active     = !empty($d['is_active']) ? 1 : 0;
        $m->is_web        = !empty($d['is_web']) ? 1 : 0;
        $m->is_scan_suite = !empty($d['is_scan_suite']) ? 1 : 0;
        $m->access_rights = implode('-', array_values(array_unique(array_map('intval', $d['rights'] ?? []))));
        if (!empty($d['password'])) $m->password = Hash::make($d['password']);
        $m->updated_by = $this->addedByFor($request);
    }

    /* POST /api/team */
    public function store(Request $request)
    {
        $d = $request->validate($this->rules(true));
        abort_if($this->usernameTaken($d['username']), 422, 'Username already exists.');

        $m = new UserClientStaff();
        $m->caid = $this->caidFor($request);
        $m->user_client_id = $this->addedByFor($request);
        $m->added_by = $this->addedByFor($request);
        $this->fill($m, $d, $request);
        $m->save();

        return response()->json($this->member($m), 201);
    }

    /* PUT /api/team/{id} */
    public function update(Request $request, int $id)
    {
        $m = $this->mine($request, $id);
        $d = $request->validate($this->rules(false));
        abort_if($this->usernameTaken($d['username'], $m->id), 422, 'Username already exists.');

        $this->fill($m, $d, $request);
        $m->save();

        return response()->json($this->member($m));
    }

    /* DELETE /api/team/{id} */
    public function destroy(Request $request, int $id)
    {
        $this->mine($request, $id)->delete();
        return response()->json(['deleted' => true]);
    }
}