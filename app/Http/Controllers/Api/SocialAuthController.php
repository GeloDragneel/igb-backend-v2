<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserClient;
use App\Models\UserClientStaff;
use App\Models\UserStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

/**
 * Sign in with Google / Facebook (OAuth 2.0 on the server, Laravel HTTP client – no extra package).
 *
 *  SIGN IN   Login page → GET /auth/{provider}/url → Google/Facebook → GET /auth/{provider}/callback
 *            → linked FEMS login found → one-time code (60 s) → {FRONTEND}/login?social=CODE
 *            → Login page calls the normal POST /login with username "__social__" + password CODE
 *              (AuthController::login turns the code into the usual token + user, so "Keep me signed in" works the same)
 *  LINK      My profile → GET /auth/{provider}/link-url (signed in) → Google/Facebook → callback → saved in m_user_social
 *            → back to the page it started from ?social_linked=google
 *
 *  SIGN UP   (new client) Google/Facebook account not linked → {FRONTEND}/login?social_signup=TOKEN (15 min)
 *            → Login page asks business name + owner name → POST /auth/signup
 *            → new client (IGB-####) + MAIN business account + client login + link, status FEDERATED, 15-day trial
 *            → signed in → Settings in "welcome" mode to fill up Profile … Business Accounts
 *
 *  Only accounts that were LINKED can sign in. A Google / Facebook account is never matched by email
 *  (anyone can make a Facebook account with someone else's email).
 *  Secrets: GOOGLE_* / FACEBOOK_* in .env only.
 */
class SocialAuthController extends Controller
{
    public const USERNAME = '__social__';            // the "username" the Login page sends with a one-time code
    private const PROVIDERS = ['google', 'facebook'];
    private const TABLE = 'm_user_social';
    private const MODELS = ['Staff' => UserStaff::class, 'Client' => UserClient::class, 'ClientStaff' => UserClientStaff::class];

    /* ---------------- helpers ---------------- */

    /**
     * Google / Facebook keys: config/services.php when it has them, otherwise straight from .env
     * (so it works even when config/services.php was not edited)
     */
    private static function keys(string $p): array
    {
        $u = strtoupper($p);
        $c = (array) config("services.{$p}", []);
        $keys = [
            'client_id'     => $c['client_id'] ?? null ?: env("{$u}_CLIENT_ID"),
            'client_secret' => $c['client_secret'] ?? null ?: env("{$u}_CLIENT_SECRET"),
            'redirect'      => $c['redirect'] ?? null ?: (env("{$u}_REDIRECT_URI") ?: rtrim((string) config('app.url'), '/') . "/api/auth/{$p}/callback"),
        ];
        return $keys;
    }

    private static function frontUrl(): string
    {
        return rtrim((string) (config('services.frontend_url') ?: env('FRONTEND_URL') ?: config('app.url')), '/');
    }

    private function provider(string $p): string
    {
        $p = strtolower($p);
        abort_unless(in_array($p, self::PROVIDERS, true), 404, 'Unknown sign-in provider.');
        $k = self::keys($p);
        abort_unless($k['client_id'] && $k['client_secret'], 422,
            ucfirst($p) . ' sign-in is not set up yet. Add the ' . strtoupper($p) . '_CLIENT_ID and ' . strtoupper($p) . '_CLIENT_SECRET to .env.');
        return $p;
    }

    private const FB = 'v19.0';

    /** HTTP client (SOCIAL_SSL_VERIFY=false in .env only if your local PHP has no CA certificates – never on live) */
    private function http()
    {
        // same switch as S3: AWS_SSL_VERIFY=false on a local PC without CA certificates (cURL error 60)
        $verify = env('SOCIAL_SSL_VERIFY', env('AWS_SSL_VERIFY'));
        return Http::timeout(20)->acceptJson()->withOptions(['verify' => $verify === null ? true : filter_var($verify, FILTER_VALIDATE_BOOLEAN)]);
    }

    /** Google / Facebook sign-in page */
    private function providerUrl(string $p, string $state): string
    {
        $k = self::keys($p);
        if ($p === 'google') {
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $k['client_id'], 'redirect_uri' => $k['redirect'], 'response_type' => 'code',
                'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account', 'access_type' => 'online',
            ]);
        }
        return 'https://www.facebook.com/' . self::FB . '/dialog/oauth?' . http_build_query([
            'client_id' => $k['client_id'], 'redirect_uri' => $k['redirect'], 'response_type' => 'code',
            'scope' => 'email,public_profile', 'state' => $state,
        ]);
    }

    /** code from Google / Facebook → the person: [id, email, name, first_name, last_name, avatar] */
    private function providerUser(string $p, string $code): array
    {
        $k = self::keys($p);
        if ($p === 'google') {
            $tok = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code, 'client_id' => $k['client_id'], 'client_secret' => $k['client_secret'],
                'redirect_uri' => $k['redirect'], 'grant_type' => 'authorization_code',
            ]);
            if (!$tok->ok() || !$tok->json('access_token')) throw new \RuntimeException('Google token: ' . $tok->body());
            $u = $this->http()->withToken($tok->json('access_token'))->get('https://openidconnect.googleapis.com/v1/userinfo');
            if (!$u->ok() || !$u->json('sub')) throw new \RuntimeException('Google userinfo: ' . $u->body());
            return [
                'id' => (string) $u->json('sub'), 'email' => (string) ($u->json('email_verified') ? $u->json('email') : ''),
                'name' => (string) $u->json('name'), 'first_name' => (string) $u->json('given_name'),
                'last_name' => (string) $u->json('family_name'), 'avatar' => (string) $u->json('picture'),
            ];
        }
        $tok = $this->http()->get('https://graph.facebook.com/' . self::FB . '/oauth/access_token', [
            'client_id' => $k['client_id'], 'client_secret' => $k['client_secret'], 'redirect_uri' => $k['redirect'], 'code' => $code,
        ]);
        if (!$tok->ok() || !$tok->json('access_token')) throw new \RuntimeException('Facebook token: ' . $tok->body());
        $u = $this->http()->get('https://graph.facebook.com/' . self::FB . '/me', [
            'fields' => 'id,name,email,first_name,last_name,picture.type(large)', 'access_token' => $tok->json('access_token'),
        ]);
        if (!$u->ok() || !$u->json('id')) throw new \RuntimeException('Facebook me: ' . $u->body());
        return [
            'id' => (string) $u->json('id'), 'email' => (string) $u->json('email'), 'name' => (string) $u->json('name'),
            'first_name' => (string) $u->json('first_name'), 'last_name' => (string) $u->json('last_name'),
            'avatar' => (string) $u->json('picture.data.url'),
        ];
    }

    private function front(string $path, array $query = []): string
    {
        $base = self::frontUrl();
        return $base . $path . ($query ? '?' . http_build_query($query) : '');
    }

    private function typeOf($user): string
    {
        return match (true) {
            $user instanceof UserClient      => 'Client',
            $user instanceof UserStaff       => 'Staff',
            $user instanceof UserClientStaff => 'ClientStaff',
            default                          => '',
        };
    }

    /** the provider's sign-in page, with our own state (what to do when it comes back) */
    private function authUrl(string $p, array $state): string
    {
        $key = Str::random(40);
        Cache::put("social-state:{$key}", $state, now()->addMinutes(10));
        return $this->providerUrl($p, $key);
    }

    /** the same checks as the username login: active, web access, account still exists */
    private function activeUser(string $type, int $id)
    {
        $model = self::MODELS[$type] ?? null;
        if (!$model) return null;
        $q = $model::query()->whereKey($id)->where('is_active', 1);
        if ($type === 'Client') {
            $q->where('is_web', 1)->whereExists(fn ($e) => $e->select(DB::raw(1))->from('m_client_account as B')
                ->join('m_client as C', 'C.client_num', '=', 'B.client_num')->whereColumn('B.id', 'm_user_client.caid'));
        } elseif ($type === 'ClientStaff') {
            $q->where('is_web', 1)->whereExists(fn ($e) => $e->select(DB::raw(1))->from('m_client_account as B')
                ->whereColumn('B.id', 'm_user_client_staff.caid'));
        } else {
            $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('m_employee as B')->whereColumn('B.com_id', 'm_user_staff.comid'));
        }
        return $q->first();
    }

    /**
     * POST /login with username "__social__": the one-time code → [user, type]  (called by AuthController::login)
     * the code works once and only for 60 seconds
     */
    public static function takeCode(string $code): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{40}$/', $code)) return null;
        $hit = Cache::pull("social-code:{$code}");
        if (!$hit) return null;
        $user = (new self)->activeUser($hit['type'], (int) $hit['id']);
        return $user ? [$user, $hit['type']] : null;
    }

    /* ---------------- sign in ---------------- */

    /** GET /api/auth/{provider}/url   (public) → { url } of the Google / Facebook sign-in page */
    public function loginUrl(string $provider)
    {
        $p = $this->provider($provider);
        return response()->json(['url' => $this->authUrl($p, ['intent' => 'login'])]);
    }

    /** GET /api/auth/{provider}/callback   (public) — Google / Facebook send the browser back here */
    public function callback(Request $request, string $provider)
    {
        $p = strtolower($provider);
        if (!in_array($p, self::PROVIDERS, true)) return redirect()->away($this->front('/login', ['social_error' => 'provider']));
        self::keys($p);

        $state = Cache::pull('social-state:' . (string) $request->query('state'));
        if (!$state) return redirect()->away($this->front('/login', ['social_error' => 'expired']));
        $back = ($state['intent'] ?? '') === 'link' ? ($state['back'] ?? '/profile') : '/login';

        if ($request->filled('error')) {             // the user pressed Cancel on Google / Facebook
            return redirect()->away($this->front($back, ['social_error' => 'cancelled', 'provider' => $p]));
        }

        try {
            $s = $this->providerUser($p, (string) $request->query('code'));
        } catch (\Throwable $e) {
            Log::warning("Social sign-in ({$p}) failed: " . $e->getMessage());
            // APP_DEBUG=true (local): show why on the login page
            $why = config('app.debug') ? ['reason' => mb_substr($e->getMessage(), 0, 300)] : [];
            return redirect()->away($this->front($back, ['social_error' => 'failed', 'provider' => $p] + $why));
        }
        $pid = $s['id'];

        /* ----- LINK (from My profile) ----- */
        if ($state['intent'] === 'link') {
            $owner = DB::table(self::TABLE)->where('provider', $p)->where('provider_id', $pid)->first();
            if ($owner && !($owner->user_type === $state['type'] && (int) $owner->user_id === (int) $state['id'])) {
                return redirect()->away($this->front($back, ['social_error' => 'taken', 'provider' => $p]));
            }
            DB::table(self::TABLE)->updateOrInsert(
                ['provider' => $p, 'user_type' => $state['type'], 'user_id' => (int) $state['id']],
                ['provider_id' => $pid, 'email' => mb_substr($s['email'], 0, 150) ?: null,
                 'name' => mb_substr($s['name'], 0, 150) ?: null, 'avatar' => mb_substr($s['avatar'], 0, 500) ?: null,
                 'updated_at' => now(), 'created_at' => now()]
            );
            return redirect()->away($this->front($back, ['social_linked' => $p]));
        }

        /* ----- SIGN IN ----- */
        $link = DB::table(self::TABLE)->where('provider', $p)->where('provider_id', $pid)->first();
        if (!$link) {
            // not linked → offer to sign up as a new client (the Login page asks for the business name)
            $t = Str::random(40);
            Cache::put("social-signup:{$t}", [
                'provider' => $p, 'provider_id' => $pid, 'email' => $s['email'], 'name' => $s['name'], 'avatar' => $s['avatar'],
                'first_name' => $s['first_name'], 'last_name' => $s['last_name'],
            ], now()->addMinutes(15));
            return redirect()->away($this->front('/login', ['social_signup' => $t, 'provider' => $p]));
        }
        if (!$this->activeUser($link->user_type, (int) $link->user_id)) {
            return redirect()->away($this->front('/login', ['social_error' => 'inactive', 'provider' => $p]));
        }
        DB::table(self::TABLE)->where('id', $link->id)->update(['last_login_at' => now(), 'updated_at' => now()]);

        $code = Str::random(40);
        Cache::put("social-code:{$code}", ['type' => $link->user_type, 'id' => (int) $link->user_id, 'provider' => $p], now()->addSeconds(60));
        return redirect()->away($this->front('/login', ['social' => $code]));
    }

    /* ---------------- sign up (new client) ---------------- */

    private function signupData(string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token), 404, 'This sign-up link is not valid. Click Continue with Google / Facebook again.');
        $d = Cache::get("social-signup:{$token}");
        abort_unless($d, 410, 'This sign-up took too long. Click Continue with Google / Facebook again.');
        return $d;
    }

    /** GET /api/auth/signup/{token}   (public) → who is signing up */
    public function signupInfo(string $token)
    {
        $d = $this->signupData($token);
        $name = trim($d['name']);
        $first = $d['first_name'] ?: (str_contains($name, ' ') ? trim(substr($name, 0, strrpos($name, ' '))) : $name);
        $last = $d['last_name'] ?: (str_contains($name, ' ') ? trim(substr($name, strrpos($name, ' ') + 1)) : '');
        return response()->json([
            'provider' => $d['provider'], 'email' => $d['email'], 'name' => $name, 'avatar' => $d['avatar'],
            'first_name' => mb_strtoupper($first), 'last_name' => mb_strtoupper($last),
        ]);
    }

    private function cols(string $table, array $row): array
    {
        static $cache = [];
        $cache[$table] ??= array_flip(Schema::getColumnListing($table));
        return array_intersect_key($row, $cache[$table]);
    }

    /** client status of a Google / Facebook sign-up: FEDERATED (m_client_account_status.status_name), null when there is none */
    private function signupStatus(): ?int
    {
        $t = 'm_client_account_status';
        if (!Schema::hasTable($t)) return null;
        foreach (['status_name', 'name', 'description', 'status'] as $c) {
            if (!Schema::hasColumn($t, $c)) continue;
            $id = DB::table($t)->whereRaw("UPPER(TRIM({$c})) = 'FEDERATED'")->value('id');
            if ($id) return (int) $id;
        }
        return null;
    }

    /** a free username from the email: maria.santos@gmail.com → maria.santos, maria.santos2 … */
    private function freeUsername(string $email, string $fallback): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', strstr($email, '@', true) ?: $fallback)) ?: 'client';
        $base = substr($base, 0, 40);
        for ($i = 1; ; $i++) {
            $u = $i === 1 ? $base : $base . $i;
            $taken = collect(['m_user_client', 'm_user_client_staff', 'm_user_staff'])
                ->contains(fn ($t) => Schema::hasTable($t) && DB::table($t)->where('username', $u)->exists());
            if (!$taken) return $u;
        }
    }

    /**
     * POST /api/auth/signup   (public)  { token, trade_name, last_name, first_name, middle_name?, phone? }
     * → { code }  the Login page signs in with it, then opens Settings (welcome) to fill up the rest
     */
    public function signup(Request $request)
    {
        $d = $request->validate([
            'token'       => ['required', 'string', 'size:40'],
            'trade_name'  => ['required', 'string', 'max:255'],
            'last_name'   => ['required', 'string', 'max:100'],
            'first_name'  => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'phone'       => ['nullable', 'string', 'max:50'],
        ], ['trade_name.required' => 'Enter your business (trade) name.', 'last_name.required' => 'Enter your last name.', 'first_name.required' => 'Enter your first name.']);
        $info = $this->signupData($d['token']);
        abort_if(DB::table(self::TABLE)->where('provider', $info['provider'])->where('provider_id', $info['provider_id'])->exists(), 422,
            'This ' . ucfirst($info['provider']) . ' account is already linked. Sign in instead.');

        $up = fn ($v) => mb_strtoupper(trim((string) $v));
        $userId = DB::transaction(function () use ($d, $info, $up) {
            // client number: highest IGB-#### + 1 (same as Clients → Add client)
            $max = (int) DB::table('m_client')->where('client_num', 'like', 'IGB-%')->lockForUpdate()
                ->max(DB::raw('CAST(SUBSTRING(client_num, 5) AS UNSIGNED)'));
            $num = 'IGB-' . ($max + 1);
            $now = now();
            $email = $info['email'] ?: null;

            DB::table('m_client')->insert($this->cols('m_client', [
                'client_num' => $num, 'last_name' => $up($d['last_name']), 'first_name' => $up($d['first_name']),
                'middle_name' => $up($d['middle_name'] ?? ''), 'email' => $email, 'email_address' => $email,
                'phone_num' => $d['phone'] ?? null, 'mobile_num' => $d['phone'] ?? null,
                'status_id' => $this->signupStatus(), 'active' => 1, 'is_active' => 1,
                'remarks' => 'Signed up with ' . ucfirst($info['provider']), 'created_at' => $now, 'updated_at' => $now,
                'trial_ends_at' => now('Asia/Manila')->addDays(15)->endOfDay(),      // 15-day free trial
            ]));
            $caid = DB::table('m_client_account')->insertGetId($this->cols('m_client_account', [
                'client_num' => $num, 'trade_name' => $up($d['trade_name']), 'branch_type' => 'MAIN', 'branch_code' => '00000',
                'email_address' => $email, 'phone_num' => $d['phone'] ?? null, 'is_active' => 1,
                'status_id' => $this->signupStatus(), 'created_at' => $now, 'updated_at' => $now,
            ]));
            $ca = Schema::hasColumn('m_user_client', 'caid') ? 'caid' : 'ca_id';
            $uid = DB::table('m_user_client')->insertGetId($this->cols('m_user_client', [
                $ca => $caid, 'username' => $this->freeUsername((string) $email, $d['first_name']),
                'password' => Hash::make(Str::random(32)),        // set a real one later in Change password / Settings → Login
                'is_active' => 1, 'is_web' => 1, 'is_mobile' => 1, 'is_pos' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]));
            DB::table(self::TABLE)->insert([
                'provider' => $info['provider'], 'provider_id' => $info['provider_id'], 'email' => mb_substr((string) $email, 0, 150) ?: null,
                'name' => mb_substr($info['name'], 0, 150) ?: null, 'avatar' => mb_substr($info['avatar'], 0, 500) ?: null,
                'user_type' => 'Client', 'user_id' => $uid, 'last_login_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $uid;
        });
        Cache::forget("social-signup:{$d['token']}");

        $code = Str::random(40);
        Cache::put("social-code:{$code}", ['type' => 'Client', 'id' => (int) $userId, 'provider' => $info['provider']], now()->addSeconds(60));
        return response()->json(['code' => $code, 'message' => 'Account created.']);
    }

    /**
     * GET /api/onboarding   (signed in) — new client from Google / Facebook: what is still missing in Settings
     * → { show, name, provider, trial, steps: [{ key (Settings section), label, done }] }
     * show = signed up with Google / Facebook (m_user_social on this client login) and something is still missing
     */
    public function onboarding(Request $request)
    {
        $u = $request->user();
        $type = $this->typeOf($u);
        if (!in_array($type, ['Client', 'ClientStaff'], true)) return response()->json(['show' => false]);

        $acc = DB::table('m_client_account')->where('id', (int) ($u->caid ?? 0))->first();
        $client = $acc ? DB::table('m_client')->where('client_num', $acc->client_num)->first() : null;
        if (!$client) return response()->json(['show' => false]);

        // only clients who signed up with Google / Facebook (their MAIN login is linked)
        $logins = DB::table('m_user_client')->where(Schema::hasColumn('m_user_client', 'caid') ? 'caid' : 'ca_id', $acc->id)->pluck('id');
        $social = DB::table(self::TABLE)->where('user_type', 'Client')->whereIn('user_id', $logins)->orderBy('id')->first();
        if (!$social) return response()->json(['show' => false]);

        $c = (array) $client;
        $has = fn (string $k) => isset($c[$k]) && trim((string) $c[$k]) !== '' && (string) $c[$k] !== '0';
        $ids = Schema::hasTable('m_client_valid_id')
            ? DB::table('m_client_valid_id')->where('client_num', $client->client_num)->count()
            : 0;
        $steps = [
            ['key' => 'profile',        'label' => 'Profile (name, gender, birthday)',          'done' => $has('last_name') && $has('first_name') && $has('gender')],
            ['key' => 'contact',        'label' => 'Contact (mobile number)',                    'done' => $has('phone_num')],
            ['key' => 'address',        'label' => 'Address',                                    'done' => $has('street') && $has('province_id') && $has('city_id') && $has('brgy_id')],
            ['key' => 'ownership',      'label' => 'Business ownership type',                    'done' => $has('business_ownership_type_id')],
            ['key' => 'classification', 'label' => 'Classification & Compliance (BIR returns)',  'done' => $has('classification_id') && $has('compliance_ids')],
            ['key' => 'ids',            'label' => 'Valid IDs (BIR TIN)',                        'done' => $ids > 0],
            ['key' => 'accounts',       'label' => 'Business account (nature of business, RDO)', 'done' => !empty($acc->nob_id ?? null) && !empty($acc->rdo ?? null)],
        ];
        $left = count(array_filter($steps, fn ($x) => !$x['done']));
        return response()->json([
            'show'     => $left > 0,
            'name'     => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'business' => $acc->trade_name ?? '',
            'provider' => $social->provider,
            'trial'    => \App\Http\Middleware\TrialGuard::trialOf($u),
            'done'     => count($steps) - $left,
            'steps'    => $steps,
        ]);
    }

    /** GET /api/trial   (signed in) → { trial, ends_at, days_left, expired }  (trial false = full account) */
    public function trial(Request $request)
    {
        return response()->json(\App\Http\Middleware\TrialGuard::trialOf($request->user()) ?? ['trial' => false]);
    }

    /**
     * PUT /api/clients/{num}/trial   (staff only)  { action: activate | extend, days? }
     *   activate → full account (trial_ends_at = NULL) · extend → N more days from today (default 15)
     */
    public function setTrial(Request $request, string $num)
    {
        abort_unless($request->user() instanceof UserStaff, 403, 'Only IGB staff can do this.');
        $d = $request->validate(['action' => ['required', 'in:activate,extend'], 'days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        abort_unless(DB::table('m_client')->where('client_num', $num)->exists(), 404, 'Client not found.');
        $ends = $d['action'] === 'activate' ? null : now('Asia/Manila')->addDays($d['days'] ?? 15)->endOfDay();
        DB::table('m_client')->where('client_num', $num)->update(['trial_ends_at' => $ends, 'updated_at' => now()]);
        return response()->json(['message' => $ends ? "Trial extended until {$ends->format('M d, Y')}." : 'Account activated (no more trial).', 'trial_ends_at' => $ends?->toDateTimeString()]);
    }

    /* ---------------- My profile: link / unlink ---------------- */

    /** GET /api/auth/social   (signed in) → which providers are linked to this login */
    public function linked(Request $request)
    {
        $u = $request->user();
        $rows = DB::table(self::TABLE)->where('user_type', $this->typeOf($u))->where('user_id', $u->id)->get()->keyBy('provider');
        return response()->json(collect(self::PROVIDERS)->map(fn ($p) => [
            'provider'   => $p,
            'enabled'    => (bool) (self::keys($p)['client_id'] && self::keys($p)['client_secret']),
            'linked'     => isset($rows[$p]),
            'email'      => $rows[$p]->email ?? null,
            'name'       => $rows[$p]->name ?? null,
            'linked_at'  => isset($rows[$p]) ? (string) $rows[$p]->created_at : null,
            'last_login' => isset($rows[$p]) && $rows[$p]->last_login_at ? (string) $rows[$p]->last_login_at : null,
        ])->values());
    }

    /** GET /api/auth/{provider}/link-url   (signed in) → { url } to link this login */
    public function linkUrl(Request $request, string $provider)
    {
        $p = $this->provider($provider);
        $u = $request->user();
        $type = $this->typeOf($u);
        abort_unless($type, 403, 'This login type can\'t be linked.');
        // back to the page the link started from (My profile for staff, Settings for clients)
        $back = (string) $request->query('back', '/profile');
        if (!preg_match('#^/[A-Za-z0-9/_-]{0,80}$#', $back)) $back = '/profile';
        return response()->json(['url' => $this->authUrl($p, ['intent' => 'link', 'type' => $type, 'id' => (int) $u->id, 'back' => $back])]);
    }

    /** DELETE /api/auth/social/{provider}   (signed in) */
    public function unlink(Request $request, string $provider)
    {
        $p = strtolower($provider);
        abort_unless(in_array($p, self::PROVIDERS, true), 404, 'Unknown sign-in provider.');
        $u = $request->user();
        $type = $this->typeOf($u);
        // keep a way in: the username login needs a password
        $hasPassword = (string) $u->getRawOriginal('password') !== '';
        $others = DB::table(self::TABLE)->where('user_type', $type)->where('user_id', $u->id)->where('provider', '<>', $p)->count();
        abort_if(!$hasPassword && !$others, 422, 'Set a password first (My profile → Change password), so you can still sign in.');
        DB::table(self::TABLE)->where('provider', $p)->where('user_type', $type)->where('user_id', $u->id)->delete();
        return response()->json(['message' => ucfirst($p) . ' unlinked.']);
    }
}