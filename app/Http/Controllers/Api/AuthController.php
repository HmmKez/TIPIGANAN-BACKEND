<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SafeCache;
use App\Support\SchoolDirectory\DirectoryPerson;
use App\Support\SchoolDirectory\DirectoryUnavailable;
use App\Support\SchoolDirectory\SchoolDirectory;
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

    /**
     * Tells the registration page which form to show.
     *
     * With directory verification switched off - the default, and the state
     * until the school's records are available - the page shows the original
     * single-step form. Only when verification is on does it split into
     * "check the ID first, then set up the account". Without this, the page
     * showed the two-step form unconditionally, making every registrant click
     * through a check that verified nothing.
     */
    public function registrationOptions(SchoolDirectory $directory)
    {
        return response()->json([
            'verification_required' => $directory->isEnabled(),
        ]);
    }

    /**
     * Step one of registration: confirm the ID number belongs to a real,
     * currently-enrolled student or a current member of faculty.
     *
     * Answers only enough to let the person continue - a first name and their
     * role - never the full record. See the throttling note below for why.
     */
    public function verifyIdNumber(Request $request, SchoolDirectory $directory)
    {
        $request->validate([
            'id_number' => 'required|digits:5',
        ], [
            'id_number.digits' => 'Your ID number must be exactly 5 digits.',
        ]);

        $idNumber = (string) $request->input('id_number');

        // This endpoint takes an ID number and reports whether it is real,
        // which is precisely the shape of thing somebody would run from 00000
        // to 99999 to harvest valid IDs and names.
        //
        // Note the difference from login: there, only FAILED attempts count,
        // because a legitimate user signing in repeatedly is normal. Here EVERY
        // attempt counts, because enumeration is made of *successful* lookups -
        // counting only failures would leave the attack completely unthrottled.
        $throttleKey = 'verify-id:'.$request->ip();
        $maxAttempts = (int) config('school.directory.verify_attempts_per_minute', 8);

        if (SafeCache::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = SafeCache::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'id_number' => ["Too many attempts. Please try again in {$seconds} seconds."],
            ])->status(429);
        }

        SafeCache::hit($throttleKey, 60);

        // Verification switched off: tell the frontend to fall back to the
        // single-step form rather than leaving it waiting on a check that will
        // never happen.
        if (! $directory->isEnabled()) {
            return response()->json(['verification_required' => false]);
        }

        if (User::where('id_number', $idNumber)->exists()) {
            throw ValidationException::withMessages([
                'id_number' => ['An account already exists for that ID number. Try signing in instead.'],
            ]);
        }

        $person = $this->lookUpOrRefuse($directory, $idNumber);

        AuditLog::create([
            'user_id'     => null,
            'action'      => 'verify_id_number',
            'target_type' => 'user',
            'target_id'   => null,
            'description' => "ID number {$idNumber} was checked against the school directory",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'verification_required' => true,
            'id_number'             => $idNumber,
            // First name only, never the full name. It keeps the "the system
            // recognised me" moment that makes the check visible, while making
            // the endpoint far less rewarding to anyone harvesting names.
            'first_name'            => $person->firstName(),
            'role'                  => $person->appRole(),
            'program'               => $person->program,
            'has_school_email'      => filled($person->email),
        ]);
    }

    /**
     * Look an ID up and turn every unusable outcome into the right refusal.
     *
     * The three failure modes are deliberately distinct, because they mean
     * genuinely different things to the person on the other side: "that number
     * isn't ours", "it was ours and no longer is", and "we can't tell you right
     * now". Collapsing them into one message would leave a graduate and a typo
     * indistinguishable to the library staff trying to help.
     */
    private function lookUpOrRefuse(SchoolDirectory $directory, string $idNumber): DirectoryPerson
    {
        try {
            $person = $directory->lookup($idNumber);
        } catch (DirectoryUnavailable $e) {
            report($e);

            // Refuse rather than admit an unverified account. Registration is
            // not time-critical - a person can come back in ten minutes - and
            // letting accounts through unchecked whenever the school's system
            // hiccups would defeat the entire point of verifying them.
            throw ValidationException::withMessages([
                'id_number' => ["We couldn't reach the school's records right now. Please try again shortly, or ask the library for help."],
            ])->status(503);
        }

        if (! $person) {
            throw ValidationException::withMessages([
                'id_number' => ['We could not find that ID number in the school records. Please check it and try again, or ask the library for help.'],
            ]);
        }

        if (! $person->isActive()) {
            throw ValidationException::withMessages([
                'id_number' => ['That ID number is no longer active in the school records. Please visit the library if you think this is a mistake.'],
            ]);
        }

        return $person;
    }

    public function register(Request $request, SchoolDirectory $directory)
    {
        $verifying = $directory->isEnabled();

        $request->validate([
            // The school's 5-digit student/teacher ID. `digits:5` keeps a
            // leading-zero ID like "00123" valid, which a numeric rule such as
            // between:10000,99999 would wrongly reject.
            'id_number' => 'required|digits:5|unique:users,id_number',
            'email'     => 'required|email|unique:users,email',
            'password'  => ['required', 'string', 'confirmed', Password::default()],
            // The role is only the registrant's to choose while verification is
            // off. Once it is on, the school decides - see below.
            'role'      => $verifying ? 'nullable' : 'required|in:student,teacher',
        ], [
            'id_number.digits' => 'Your ID number must be exactly 5 digits.',
            'id_number.unique' => 'An account already exists for that ID number.',
        ]);

        $idNumber = (string) $request->id_number;

        // Fields the school decides, not the registrant. Null when verification
        // is switched off, which leaves the previous behaviour untouched.
        $person = null;

        if ($verifying) {
            // Deliberately checked AGAIN here, even though verifyIdNumber()
            // already did. Nothing stops a client skipping step one and posting
            // straight to this endpoint with role=teacher, so the only answer
            // that can be trusted is the one this request fetches itself.
            $person = $this->lookUpOrRefuse($directory, $idNumber);
        }

        // The name is never collected from the registrant - it comes from the
        // school, and until it does, display falls back to the ID number
        // (User::display_name).
        $user = User::create([
            'id_number'            => $idNumber,
            'name'                 => $person?->fullName,
            'email'                => $request->email,
            'password'             => Hash::make($request->password),
            // The school's answer wins outright. A registrant claiming to be a
            // teacher gets whatever the records actually say they are.
            'role'                 => $person?->appRole() ?? $request->role,
            'status'               => 'active',
            'directory_status'     => $person?->status,
            'directory_checked_at' => $person ? now() : null,
            'directory_person_key' => $person?->personKey,
        ]);

        $user->assignRole($user->role);

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

        // Deliberately reads only what was recorded locally by the last
        // directory check - signing in NEVER calls the school's system. Putting
        // their server in the login path would mean that whenever it is slow or
        // down, nobody can sign in to TIPIGANAN at all, in exchange for
        // re-confirming an answer that changes at most once a semester. The
        // nightly reconciliation keeps this column current instead.
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