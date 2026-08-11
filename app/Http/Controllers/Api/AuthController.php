<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SafeCache;
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

    public function register(Request $request)
    {
        $request->validate([
            // The school's 5-digit student/teacher ID. `digits:5` keeps a
            // leading-zero ID like "00123" valid, which a numeric rule such as
            // between:10000,99999 would wrongly reject.
            'id_number' => 'required|digits:5|unique:users,id_number',
            'email'     => 'required|email|unique:users,email',
            'password'  => ['required', 'string', 'confirmed', Password::default()],
            'role'      => 'required|in:student,teacher',
        ], [
            'id_number.digits' => 'Your ID number must be exactly 5 digits.',
            'id_number.unique' => 'An account already exists for that ID number.',
        ]);

        // No name is collected. It comes from the school's API, keyed on the ID
        // number; until then display falls back to the ID (User::display_name).
        $user = User::create([
            'id_number' => (string) $request->id_number,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => $request->role,
            'status'    => 'active',
        ]);

        $user->assignRole($request->role);

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'register',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$user->display_name} registered as {$user->role}",
            'ip_address'  => $request->ip(),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'     => 'Registration successful.',
            'user'        => $user,
            'token'       => $token,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 201);
    }

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

        if ($user->status === 'deactivated') {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact the administrator.',
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
            'password' => Hash::make($request->password),
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