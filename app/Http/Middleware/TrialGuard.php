<?php

namespace App\Http\Middleware;

use App\Models\UserClient;
use App\Models\UserClientStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 15-day trial: when a client's trial has ended, its logins (client + client staff) can't do anything
 * except sign in / out, read who they are and the trial status. Staff logins are never blocked.
 * m_client.trial_ends_at: NULL = full account · a date = trial until that date.
 */
class TrialGuard
{
    /** still allowed after the trial ends */
    private const OPEN = ['api/login', 'api/logout', 'api/me', 'api/trial', 'api/auth/*'];

    public static function trialOf($user): ?array
    {
        if (!($user instanceof UserClient || $user instanceof UserClientStaff)) return null;
        static $has = null;
        $has ??= Schema::hasColumn('m_client', 'trial_ends_at');
        if (!$has) return null;
        $ends = DB::table('m_client_account as A')->join('m_client as C', 'C.client_num', '=', 'A.client_num')
            ->where('A.id', (int) ($user->caid ?? 0))->value('C.trial_ends_at');
        if (!$ends) return null;
        $end = \Carbon\Carbon::parse($ends, 'Asia/Manila');
        $now = now('Asia/Manila');
        return [
            'trial'     => true,
            'ends_at'   => $end->toDateTimeString(),
            'days_left' => $end->isPast() ? 0 : (int) ceil($now->floatDiffInDays($end)),
            'expired'   => $end->isPast(),
        ];
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->is(...self::OPEN)) return $next($request);
        $user = $request->user('sanctum');
        if ($user && ($t = self::trialOf($user)) && $t['expired']) {
            return response()->json([
                'message'       => 'Your 15-day free trial has ended. Contact IGB Accounting to activate your account.',
                'trial_expired' => true,
                'ends_at'       => $t['ends_at'],
            ], 402);
        }
        return $next($request);
    }
}
