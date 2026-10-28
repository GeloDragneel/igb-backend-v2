<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * My profile → Change password   (any login: Staff · Client · Client staff – the row you're logged in with)
 *   PUT /api/me/password   { current_password, password, password_confirmation }
 *   the current password must be right · new one at least 8 characters, different, typed twice
 *   other sessions of this login are signed out; this one stays signed in
 */
class AccountPasswordController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        // 6 tries a minute per login (done here: the route 'throttle' middleware needs getAuthIdentifier(), which UserStaff doesn't have)
        $key = 'pw-change:' . $user->getMorphClass() . ':' . $user->getKey();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            throw ValidationException::withMessages(['current_password' => 'Too many tries. Wait ' . RateLimiter::availableIn($key) . ' seconds and try again.']);
        }
        RateLimiter::hit($key, 60);

        $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password'         => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'Type your current password.',
            'password.required'         => 'Type a new password.',
            'password.min'              => 'The new password needs at least 8 characters.',
            'password.confirmed'        => 'The new passwords don\'t match.',
            'password.different'        => 'The new password must be different from the current one.',
        ]);

        // old password check – same rule as the Login page (hashed passwords only)
        $stored = (string) $user->getRawOriginal('password');
        $ok = false;
        if ($stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon'))) {
            try { $ok = Hash::check((string) $request->input('current_password'), $stored); } catch (\Throwable $e) { $ok = false; }
        }
        if (!$ok) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is wrong.']);
        }

        // saved straight to the login's own row (no model events – UserStaff isn't a full auth model)
        DB::table($user->getTable())->where($user->getKeyName(), $user->getKey())
            ->update(['password' => Hash::make((string) $request->input('password'))]);

        // sign out this login everywhere else (Sanctum tokens of this row, except the one in use)
        $current = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey())
            ->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        RateLimiter::clear($key);
        return response()->json(['message' => 'Password changed.']);
    }
}