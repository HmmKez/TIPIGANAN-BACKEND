<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SchoolRoster;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * activation. An earlier staff or Super Admin account keeps its role.
 */
class AccountActivationController extends Controller
{
    /** Staff enter an ID number; show who the school's list says it belongs to. */
    public function lookup(Request $request)
    {
        $request->validate(['id_number' => 'required|digits:5'], [
            'id_number.digits' => 'The ID number must be exactly 5 digits.',
        ]);

        [$row, $existing] = $this->checkActivatable((string) $request->id_number, $request->user());

        // A name shown from the school's list leaves a trace of who looked.
        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'activation_lookup',
            'target_type' => 'user',
            'target_id'   => $existing?->id,
            'description' => "{$request->user()->display_name} looked up ID number {$row->id_number} to activate an account",
            'metadata'    => ['id_number' => $row->id_number],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'id_number'  => $row->id_number,
            'first_name' => $row->first_name,
            'last_name'  => $row->last_name,
            'full_name'  => SchoolRoster::fullName($row),
            'list_role'  => $row->role,
            // What the account will be, and what it would be for a different person.
            'role'           => self::roleFor($row, $existing),
            'role_if_new'    => SchoolRoster::accountRole($row->role),
            'earlier_account' => (bool) $existing,
            // Set when the earlier account's name differs: staff must say whether
            // this is the same person.
            'previous_name'  => self::previousName($row, $existing),
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
        [$row, $existing] = $this->checkActivatable((string) $request->id_number, $request->user());

        $previousName = self::previousName($row, $existing);
        if ($previousName) {
            $request->validate(['same_person' => 'required|boolean'], [
                'same_person.required' => "Choose whether this is the same person as the earlier account ({$previousName}).",
            ]);
        }
        $replaces = $previousName && ! $request->boolean('same_person') ? $existing : null;

        $request->validate([
            // An account still waiting gives its email up (see below), so only
            // accounts in use can block one. Staff could not see the others.
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')
                ->where('needs_activation', 0)->ignore($existing?->id)],
            'password' => ['required', 'string', 'confirmed', Password::default()],
        ], [
            'email.unique' => 'That email is already used by another account.',
        ]);

        try {
            $user = DB::transaction(function () use ($request, $row, $existing, $replaces) {
            User::where('email', $request->email)->where('needs_activation', true)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->update(['email' => null]);

            if ($replaces) {
                self::retire($replaces);
            }

            $fields = [
                'name'                  => SchoolRoster::fullName($row),
                'email'                 => $request->email,
                'password'              => Hash::make($request->password),
                'status'                => 'active',
                'needs_activation'      => false,
                'deactivated_by_roster' => false,
                // The person chose this password themselves.
                'must_change_password'  => false,
                // They are on the current list, so an old "graduated" or similar
                // mark from the earlier plan must not refuse them at sign-in.
                'directory_status'      => null,
                'directory_checked_at'  => null,
            ];

            if ($existing && ! $replaces) {
                // The same person: same ID, bookmarks and history kept; new email
                // and password; signed out everywhere.
                $role = self::roleFor($row, $existing);
                $existing->update($fields + ['role' => $role]);
                $existing->syncRoles([$role]);
                $existing->tokens()->delete();

                return $existing;
            }

            $user = User::create($fields + [
                'id_number' => $row->id_number,
                'role'      => SchoolRoster::accountRole($row->role),
            ]);
            $user->assignRole($user->role);

            return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two desks at the same moment: both passed the checks above, and the
            // database let only one through. Nothing was saved for this one.
            throw str_contains($e->getMessage(), 'email')
                ? ValidationException::withMessages(['email' => ['That email is already used by another account.']])
                : ValidationException::withMessages(['id_number' => ["ID number {$row->id_number} was just activated at another desk."]]);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'activate_account',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} activated the account for {$user->id_number} ({$user->name}) as {$user->role}"
                .($replaces ? ", a different person from the earlier account ({$replaces->name})" : ''),
            'metadata'    => array_filter([
                'id_number'        => $user->id_number,
                'earlier_account'  => (bool) $existing && ! $replaces,
                'replaced_user_id' => $replaces?->id,
            ], fn ($v) => $v !== null),
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message' => "Account activated for {$user->name} ({$user->id_number}). They can now sign in with their ID number and the password they chose.",
            'user'    => ['id' => $user->id, 'id_number' => $user->id_number, 'name' => $user->name, 'role' => $user->role],
        ], $existing && ! $replaces ? 200 : 201);
    }

    /**
     * The roster row for an ID number that may be activated, and its earlier
     * account if it has one. Refuses everything else with the reason.
     *
     * @return array{0: object, 1: ?User}
     */
    private function checkActivatable(string $idNumber, User $actor): array
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

        if ($existing && $actor->role !== 'super_admin') {
            // An earlier account keeps its role, and whoever is at the keyboard
            // sets its password. So activating a staff or Super Admin account
            // hands over those powers, and only a Super Admin may give them.
            if (in_array($existing->role, ['staff', 'super_admin'], true)) {
                throw ValidationException::withMessages([
                    'id_number' => ["ID number {$idNumber} has an earlier staff or Super Admin account, so only a Super Admin can activate it."],
                ]);
            }

            // Waiting accounts are not listed in User Management, so the Super
            // Admin decides here whether to turn this one back on.
            if ($existing->status === 'deactivated' && ! $existing->deactivated_by_roster) {
                throw ValidationException::withMessages([
                    'id_number' => ["The account for ID number {$idNumber} was deactivated by staff, so only a Super Admin can activate it."],
                ]);
            }
        }

        return [$row, $existing];
    }

    /** The earlier account's name, when it differs from the list's. */
    private static function previousName(object $row, ?User $existing): ?string
    {
        return $existing && filled($existing->name) && ! SchoolRoster::sameName($existing->name, SchoolRoster::fullName($row))
            ? $existing->name
            : null;
    }

    /** Students and teachers follow the list; staff and Super Admins keep their role. */
    private static function roleFor(object $row, ?User $existing): string
    {
        return $existing && in_array($existing->role, ['staff', 'super_admin'], true)
            ? $existing->role
            : SchoolRoster::accountRole($row->role);
    }

    /**
     * The ID number now belongs to someone else. The old account is kept, not
     * deleted, so its bookmarks, history and audit trail stay with the person
     * they belong to, but it gives up the ID number and all its powers and can
     * never sign in again.
     */
    private static function retire(User $old): void
    {
        $old->update([
            'id_number'             => null,
            'email'                 => null,
            'role'                  => 'student',
            'status'                => 'deactivated',
            'needs_activation'      => true,
            'deactivated_by_roster' => false,
        ]);
        $old->syncRoles(['student']);
        $old->syncPermissions([]);
        $old->tokens()->delete();
    }
}
