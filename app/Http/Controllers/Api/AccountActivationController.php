<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Activating an account at the library - the only way an account is made.
 *
 *  1. The person comes to the library desk with their school ID card.
 *  2. Staff enter the ID number; the system shows the name the school's list
 *     holds for it, and staff confirm it matches the card (lookup()).
 *  3. Staff turn the screen to the person, who types their own email and
 *     password (activate()). Staff never need to know the password.
 *
 * Only ID numbers on the imported school list can be activated. The role
 * comes from the list, except that staff and Super Admin rows become
 * teacher-level accounts: staff powers are given in User Management, never by
 * activation.
 */
class AccountActivationController extends Controller
{
    /** Staff enter an ID number; show who the school's list says it belongs to. */
    public function lookup(Request $request)
    {
        $request->validate(['id_number' => 'required|digits:5'], [
            'id_number.digits' => 'The ID number must be exactly 5 digits.',
        ]);

        [$row, $existing] = $this->checkActivatable((string) $request->id_number);

        return response()->json([
            'id_number'  => $row->id_number,
            'first_name' => $row->first_name,
            'last_name'  => $row->last_name,
            'full_name'  => trim($row->first_name.' '.$row->last_name),
            'list_role'  => $row->role,
            // What the account will be. An earlier account keeps its role.
            'role'       => $existing ? $existing->role : self::appRole($row->role),
            'earlier_account' => (bool) $existing,
        ]);
    }

    /** The person typed their email and password; create (or reactivate) the account. */
    public function activate(Request $request)
    {
        $request->validate([
            'id_number'      => 'required|digits:5',
            'name_confirmed' => 'accepted',
        ], [
            'id_number.digits'        => 'The ID number must be exactly 5 digits.',
            'name_confirmed.accepted' => 'Confirm that the name matches the person’s school ID card first.',
        ]);

        // Checked again here, not trusted from the lookup: nothing stops a
        // request skipping straight to this endpoint.
        [$row, $existing] = $this->checkActivatable((string) $request->id_number);

        $request->validate([
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($existing?->id)],
            'password' => ['required', 'string', 'confirmed', Password::default()],
        ], [
            'email.unique' => 'That email is already used by another account.',
        ]);

        $fields = [
            'name'                  => trim($row->first_name.' '.$row->last_name),
            'email'                 => $request->email,
            'password'              => Hash::make($request->password),
            'status'                => 'active',
            'needs_activation'      => false,
            'deactivated_by_roster' => false,
            // The person chose this password themselves.
            'must_change_password'  => false,
        ];

        if ($existing) {
            // An account from the old self-registration: same ID, bookmarks and
            // history kept; new email and password; signed out everywhere.
            $existing->update($fields);
            $existing->tokens()->delete();
            $user = $existing;
        } else {
            $user = User::create($fields + [
                'id_number' => $row->id_number,
                'role'      => self::appRole($row->role),
            ]);
            $user->assignRole($user->role);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'activate_account',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} activated the account for {$user->id_number} ({$user->name}) as {$user->role}",
            'metadata'    => ['id_number' => $user->id_number, 'earlier_account' => (bool) $existing],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message' => "Account activated for {$user->name} ({$user->id_number}). They can now sign in with their ID number and the password they chose.",
            'user'    => ['id' => $user->id, 'id_number' => $user->id_number, 'name' => $user->name, 'role' => $user->role],
        ], $existing ? 200 : 201);
    }

    /**
     * The roster row for an ID number that may be activated, and its earlier
     * account if it has one. Refuses everything else with the reason.
     *
     * @return array{0: object, 1: ?User}
     */
    private function checkActivatable(string $idNumber): array
    {
        if (! SchoolRoster::isLoaded()) {
            throw ValidationException::withMessages([
                'id_number' => ['No school list has been imported yet, so no account can be activated. A Super Admin imports it on this page.'],
            ]);
        }

        $row = SchoolRoster::find($idNumber);
        if (! $row) {
            throw ValidationException::withMessages([
                'id_number' => ["ID number {$idNumber} is not on the school’s list of current students, teachers, and staff, so it cannot be activated."],
            ]);
        }

        $existing = User::where('id_number', $idNumber)->first();
        if ($existing && ! $existing->needs_activation) {
            $message = $existing->status === 'active'
                ? "ID number {$idNumber} already has an active account. If they forgot their password, reset it in User Management."
                : "The account for ID number {$idNumber} was deactivated by staff. It can be activated again in User Management.";

            throw ValidationException::withMessages(['id_number' => [$message]]);
        }

        return [$row, $existing];
    }

    /** Activation never grants staff powers; those are given in User Management. */
    private static function appRole(string $listRole): string
    {
        return $listRole === 'student' ? 'student' : 'teacher';
    }
}
