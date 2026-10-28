<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Employee;
use App\Models\UserClient;
use App\Models\UserClientStaff;
use App\Models\UserStaff;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    /** m_user_type: IT OFFICER, CEO, SUPERVISOR, AUDIT SUPERVISOR always get the FEMS / STAFF switch */
    private const SWITCH_ALWAYS = [1, 2, 4, 10];

    /** POST /api/login  { "username": "...", "password": "..." } */
    public function login(Request $request)
    {
        // Google / Facebook: the Login page sends the one-time code from /auth/{provider}/callback as the password
        if ($request->input('username') === SocialAuthController::USERNAME) {
            $found = SocialAuthController::takeCode((string) $request->input('password'));
            if (!$found) return response()->json(['message' => 'That Google / Facebook sign-in expired or was already used. Try again.'], 422);
            [$user, $type] = $found;
            return response()->json([
                'token' => $user->createToken('web-' . $type)->plainTextToken,
                'user'  => $this->userPayload($user, $type),
            ]);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        foreach ($this->candidates($data['username']) as [$user, $type]) {
            if ($this->passwordMatches($user, $data['password'])) {
                $token = $user->createToken('web-' . $type)->plainTextToken;

                return response()->json([
                    'token' => $token,
                    'user'  => $this->userPayload($user, $type),
                ]);
            }
        }

        return response()->json(['message' => 'Wrong username or password.'], 422);
    }

    /** GET /api/me */
    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json($this->userPayload($user, $this->typeOf($user)));
    }

    /** POST /api/logout */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * POST /api/switch-branch  { "caid": 123 }
     *   Client      -> m_user_client
     *   Staff       -> m_user_staff (same user, branch on the token)
     *   ClientStaff -> m_user_client_staff
     */
    public function switchBranch(Request $request)
    {
        $data    = $request->validate(['caid' => ['required', 'integer']]);
        $current = $request->user();
        $type    = $this->typeOf($current);
        $caid    = (int) $data['caid'];

        $row = app(BranchController::class)->rowsFor($current)->firstWhere('caid', $caid);
        if (!$row) {
            return response()->json(['message' => "You don't have access to that client account."], 403);
        }

        $target = match ($type) {
            'Client' => UserClient::query()
                ->where('caid', $caid)
                ->where('is_active', 1)
                ->orderByRaw('username = ? DESC', [$current->username])
                ->orderBy('id')
                ->first(),

            'ClientStaff' => UserClientStaff::query()
                ->where('caid', $caid)
                ->where('is_active', 1)
                ->where(function ($q) use ($current) {
                    $q->where('user_client_id', $current->user_client_id)
                      ->orWhere('username', $current->username);
                })
                ->orderByRaw('user_client_id = ? DESC', [$current->user_client_id])
                ->orderBy('id')
                ->first(),

            'Staff'   => $current,
            default   => null,
        };

        if (!$target) {
            return response()->json(['message' => 'No active login for this branch.'], 404);
        }

        $current->currentAccessToken()->delete();
        $token = $target->createToken('web-' . $type, ['caid:' . $caid])->plainTextToken;

        $payload = $this->userPayload($target, $type);

        if ($type === 'Staff') {
            $account = ClientAccount::find($caid);
            $payload['caid']            = $caid;
            $payload['branch_code']     = $account->branch_code ?? '';
            $payload['business_name']   = $account->trade_name ?? '';
            $payload['employee_id']     = (int) $row->employee_id;
            $payload['branch_login_id'] = (int) $row->login_id;
        }

        $payload['master_user_id'] = (int) $row->master_user_id;

        return response()->json([
            'token'  => $token,
            'user'   => $payload,
            'branch' => $row,
        ]);
    }

    /**
     * GET /api/staff-access   sidebar FEMS / STAFF switch + which Staff pages this login can open (m_user_staff_access)
     *   Client       → FEMS only, no switch
     *   ClientStaff  → FEMS only, no switch (their FEMS pages = Team → Module List, see userPayload rights)
     *   Staff        → switch when clients are assigned to them, or when IT OFFICER / CEO / SUPERVISOR / AUDIT SUPERVISOR;
     *                  otherwise STAFF only, no switch
     * { switch: bool, only: 'fems'|'staff'|null, all: bool, paths: [...] }   no access set → all = every Staff page
     */
    public function staffAccess(Request $request)
    {
        $user = $request->user();
        $type = $this->typeOf($user);

        // Client and team members (ClientStaff) → FEMS only, no switch
        if ($type !== 'Staff') {
            return response()->json(['switch' => false, 'only' => 'fems', 'all' => false, 'paths' => []]);
        }
        $switch = true;
        if ($type === 'Staff') {
            $switch = $this->staffAlwaysSwitch($user) || $this->staffHasClients($user);
        }

        // the m_user_staff login whose User Access applies
        $staffId = null;
        if ($type === 'Staff') {
            $staffId = (int) $user->id;
        } else {
            $comId = null;
            try {
                $comId = $user->employee->com_id ?? null;
            } catch (\Throwable $e) {
                $comId = null;
            }
            $staffId = UserStaff::query()
                ->where('is_active', 1)
                ->where(function ($q) use ($comId, $user) {
                    if ($comId) $q->where('comid', $comId);
                    $q->orWhere('username', $user->username);
                })
                ->when($comId, fn ($q) => $q->orderByRaw('comid = ? DESC', [$comId]))
                ->value('id');
        }

        $paths = [];
        if ($staffId && Schema::hasTable('m_user_staff_access')) {
            $paths = DB::table('m_user_staff_access')->where('user_staff_id', $staffId)->pluck('path')->all();
            $paths = array_values(array_filter($paths, fn ($p) => $p !== '#none'));   // '#none' = saved with nothing ticked
        }

        // no access → every Staff page
        return response()->json([
            'switch' => $switch,
            'only'   => $switch ? null : 'staff',
            'all'    => !$paths,
            'paths'  => $paths,
        ]);
    }

    /** IT OFFICER (1), CEO (2), SUPERVISOR (4), AUDIT SUPERVISOR (10): m_user_staff.user_type_id or the employee's designation */
    private function staffAlwaysSwitch($user): bool
    {
        $types = [(int) ($user->user_type_id ?? 0)];
        $emp = Employee::where('com_id', $user->comid)->first();
        $types[] = (int) ($emp->designation_id ?? 0);
        return (bool) array_intersect($types, self::SWITCH_ALWAYS);
    }

    /** clients assigned to this staff (same list as the branch switcher) */
    private function staffHasClients($user): bool
    {
        try {
            return app(BranchController::class)->rowsFor($user)->isNotEmpty();
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    /* ---------------------------------------------------------------- */

    /** Active users with this username: Client, Staff, ClientStaff */
    private function candidates(string $username): array
    {
        $list = [];

        $client = UserClient::query()
            ->where('username', $username)
            ->where('is_active', 1)
            ->where('is_web', 1)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('m_client_account as B')
                  ->join('m_client as C', 'C.client_num', '=', 'B.client_num')
                  ->whereColumn('B.id', 'm_user_client.caid');
            })
            ->first();
        if ($client) $list[] = [$client, 'Client'];

        $staff = UserStaff::query()
            ->where('username', $username)
            ->where('is_active', 1)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('m_employee as B')
                  ->whereColumn('B.com_id', 'm_user_staff.comid');
            })
            ->first();
        if ($staff) $list[] = [$staff, 'Staff'];

        $clientStaff = UserClientStaff::query()
            ->where('username', $username)
            ->where('is_active', 1)
            ->where('is_web', 1)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('m_client_account as B')
                  ->whereColumn('B.id', 'm_user_client_staff.caid');
            })
            ->first();
        if ($clientStaff) $list[] = [$clientStaff, 'ClientStaff'];

        return $list;
    }

    /** Plain-text password check (no hashing) */
    // Hashed passwords only
    private function passwordMatches($user, string $plain): bool
    {
        $stored = (string) $user->getRawOriginal('password');
        if ($stored === '' || !str_starts_with($stored, '$2y$') && !str_starts_with($stored, '$argon')) {
            return false;
        }
        if (!\Illuminate\Support\Facades\Hash::check($plain, $stored)) {
            return false;
        }
        if (\Illuminate\Support\Facades\Hash::needsRehash($stored)) {
            $user->password = $plain;   // 'hashed' cast hashes it
            $user->saveQuietly();
        }
        return true;
    }

    private function typeOf($user): string
    {
        return match (true) {
            $user instanceof UserClient      => 'Client',
            $user instanceof UserStaff       => 'Staff',
            $user instanceof UserClientStaff => 'ClientStaff',
            default                          => 'Unknown',
        };
    }

    private function userPayload($user, string $type): array
    {
        $branchCode    = '';
        $businessName  = '';
        $displayName   = $user->username;
        $designationId = 0;

        $fullName = fn ($p) => $p
            ? trim(preg_replace('/\s+/', ' ', "{$p->last_name} {$p->first_name} {$p->middle_name}"))
            : '';

        if ($type === 'Client') {
            $account      = $user->clientAccount;
            $branchCode   = $account->branch_code ?? '';
            $businessName = $account->trade_name ?? '';
            $client       = $account ? Client::where('client_num', $account->client_num)->first() : null;
            $displayName  = $fullName($client) ?: $user->username;
        }

        if ($type === 'Staff') {
            $emp           = Employee::where('com_id', $user->comid)->first();
            $displayName   = $fullName($emp) ?: $user->username;
            $businessName  = 'IGB Accounting Consultancy';
            $designationId = (int) ($emp->designation_id ?? 0);
        }
        if ($type === 'ClientStaff') {
            $account       = $user->clientAccount;
            $emp           = $user->employee;
            $branchCode    = $account->branch_code ?? '';
            $businessName  = $account->trade_name ?? '';
            $displayName   = $fullName($emp) ?: ($user->fullname ?: $user->username);
            $designationId = (int) ($emp->designation_id ?? 0);
        }

        return [
            'id'             => $type === 'ClientStaff' ? (int) $user->user_client_id : $user->id,
            'login_id'       => $user->id,
            'type'           => $type,
            'designation_id' => $designationId,
            'caid'           => in_array($type, ['Client', 'ClientStaff']) ? (int) $user->caid : null,
            'branch_code'    => $branchCode,
            'business_name'  => $businessName,
            'name'           => $displayName,
            'username'       => $user->username,
            'role'           => $this->designationName($designationId, $type),
            // Team → Module List (m_user_client_staff.access_rights "1-4-15" → [1, 4, 15]); empty = every FEMS page
            'rights'         => $rights = ($type === 'ClientStaff' ? $this->clientStaffRights($user) : []),
            // the pages of those modules (m_menu_data.url) – the sidebar matches by page, so it works without "m:" codes
            'right_paths'    => $this->rightPaths($rights),
            // and their names ("Sales", "Charts of Account" … – the same names the sidebar shows)
            'right_names'    => $this->rightNames($rights),
        ];
    }

    /** m_menu_data ids → module names (lower case) */
    private function rightNames(array $ids): array
    {
        if (!$ids || !Schema::hasTable('m_menu_data')) return [];
        $name = collect(['module_name', 'name', 'menu_name'])->first(fn ($c) => Schema::hasColumn('m_menu_data', $c));
        if (!$name) return [];
        return DB::table('m_menu_data')->whereIn('id', $ids)->pluck($name)
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter(fn ($n) => $n !== '')->unique()->values()->all();
    }

    /** m_menu_data ids → their page urls ("/task/sales", "/settings" …) */
    private function rightPaths(array $ids): array
    {
        if (!$ids || !Schema::hasTable('m_menu_data')) return [];
        $url = collect(['url', 'link', 'path'])->first(fn ($c) => Schema::hasColumn('m_menu_data', $c));
        if (!$url) return [];
        return DB::table('m_menu_data')->whereIn('id', $ids)->pluck($url)
            ->map(fn ($u) => trim((string) $u))->filter(fn ($u) => $u !== '')->unique()->values()->all();
    }

    /** the m_menu_data ids ticked for this team member (sidebar: only these FEMS pages; none ticked → all) */
    private function clientStaffRights($user): array
    {
        if (!Schema::hasColumn('m_user_client_staff', 'access_rights')) return [];
        $raw = (string) ($user->getRawOriginal('access_rights') ?? $user->access_rights ?? '');
        return array_values(array_unique(array_filter(array_map('intval', explode('-', $raw)))));
    }

    /** Name from m_user_type for the designation; falls back to the user type */
    private function designationName(int $designationId, string $type): string
    {
        if ($designationId > 0) {
            $col = collect(['name', 'description', 'user_type', 'UserType'])
                ->first(fn ($c) => Schema::hasColumn('m_user_type', $c));

            if ($col) {
                $name = DB::table('m_user_type')->where('id', $designationId)->value($col);
                if ($name) return $name;
            }
        }

        return match ($type) {
            'Client'      => 'Client',
            'ClientStaff' => 'Client Staff',
            default       => 'Staff',
        };
    }
}