<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SafeCache;
use App\Support\SchoolRoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * How many failed logins an account tolerates per minute before it locks.
     */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Brute-force key for one account as seen from one IP, keyed on the ID
     * number now that it is the login credential. Scoping to account|ip rather
     * than ip alone is what stops one person fumbling their password from
     * locking out everyone else on a shared campus connection, while a
     * distributed attack on a single account is still counted together.
     */
    private function loginThrottleKey(Request $request): string
    {
        return 'login:'.Str::transliterate(
            Str::lower((string) $request->input('id_number')).'|'.$request->ip()
        );
    }

    // There is no self-registration. Accounts are activated at the library:
    // staff look the ID number up on the school's list, confirm the name
    // against the person's school ID card, and the person types their own
    // email and password (AccountActivationController). Self-registration let
    // anyone who knew someone's ID number and name create their account.

    public function login(Request $request)
    {
        $request->validate([
            'id_number' => 'required|digits:5',
            'password'  => 'required|string',
        ]);

        // Only FAILED attempts are counted (and the counter is cleared on success
        // below), so a legitimate user is never locked out by simply signing in
        // often — only by repeatedly getting the password wrong.
        $throttleKey = $this->loginThrottleKey($request);

        if (SafeCache::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = SafeCache::availableIn($throttleKey);

            // Keyed on id_number so the frontend shows the error against the
            // field the user actually typed into.
            throw ValidationException::withMessages([
                'id_number' => ["Too many failed login attempts. Please try again in {$seconds} seconds."],
            ])->status(429);
        }

        $user = User::where('id_number', (string) $request->id_number)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            SafeCache::hit($throttleKey, 60);

            // Deliberately the same message whether the ID exists or the
            // password is wrong - saying "no such ID" would let anyone probe
            // which student numbers have accounts.
            throw ValidationException::withMessages([
                'id_number' => ['The provided credentials are incorrect.'],
            ]);
        }

        SafeCache::clear($throttleKey);

        // An account from the old self-registration that has not been
        // activated at the library yet.
        if ($user->needs_activation) {
            return response()->json([
                'message' => 'Your account needs to be activated at the library before you can sign in. Please visit the library with your school ID card.',
                'reason'  => 'not_activated',
            ], 403);
        }

        if ($user->status === 'deactivated' && $user->deactivated_by_roster) {
            return response()->json([
                'message' => 'Your ID number is not on the school\'s list of current students and staff, so your account has been switched off. Please visit the library if you think this is a mistake.',
                'reason'  => 'not_on_roster',
            ], 403);
        }

        if ($user->status === 'deactivated') {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact the administrator.',
            ], 403);
        }

        // Deliberately reads only what was recorded locally by the last
        // directory check - signing in NEVER calls the school's system. Putting
        // their server in the login path would mean that whenever it is slow or
        // down, nobody can sign in to TIPIGANAN at all, in exchange for
        // re-confirming an answer that changes at most once a semester. The
        // nightly reconciliation keeps this column current instead.
        // CSV roster: only accounts on the school's list may sign in - every
        // role. Checked after the password so it reveals nothing to someone
        // guessing. Inert until a list has been imported.
        if (SchoolRoster::isEnforced() && ! SchoolRoster::contains($user->id_number)) {
            return response()->json([
                'message' => 'Your ID number is not on the school\'s list of current students and staff, so you cannot sign in. Please visit the library if you think this is a mistake.',
                'reason'  => 'not_on_roster',
            ], 403);
        }

        if ($user->isRevokedByDirectory()) {
            return response()->json([
                'message' => 'Your ID number is no longer active in the school records, so this account has been closed. Please visit the library if you think this is a mistake.',
            ], 403);
        }

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'login',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$user->display_name} logged in",
            'ip_address'  => $request->ip(),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'     => 'Login successful.',
            'user'        => $user,
            'token'       => $token,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function logout(Request $request)
    {
        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'logout',
            'target_type' => 'user',
            'target_id'   => $request->user()->id,
            'description' => "{$request->user()->display_name} logged out",
            'ip_address'  => $request->ip(),
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user'        => $request->user(),
            'roles'       => $request->user()->getRoleNames(),
            'permissions' => $request->user()->getAllPermissions()->pluck('name'),
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'string', 'confirmed', Password::default()],
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 403);
        }

        $request->user()->update([
            'password'             => Hash::make($request->password),
            // Their own password now - stop reminding them.
            'must_change_password' => false,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'change_password',
            'target_type' => 'user',
            'target_id'   => $request->user()->id,
            'description' => "{$request->user()->display_name} changed their password",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}