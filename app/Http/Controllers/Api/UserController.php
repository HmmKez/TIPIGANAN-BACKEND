<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;

class UserController extends Controller
{
    // Get all users — staff and above
    public function index(Request $request)
    {
        $users = User::with('roles', 'permissions')
            ->when($request->role, fn($q) =>
                $q->where('role', $request->role))
            ->when($request->status, fn($q) =>
                $q->where('status', $request->status))
            ->latest()
            ->paginate(15);

        return response()->json($users);
    }

    // Get single user
    public function show($id)
    {
        $user = User::with('roles', 'permissions')->findOrFail($id);
        return response()->json($user);
    }

    // A Super Admin changes an existing account's role.
    //
    // Replaces the old "create a staff account" form. Everyone at the school
    // registers themselves with their own ID number, so an admin inventing a
    // second account for a colleague who already has one only creates a
    // duplicate person — and required the admin to set (and then convey) a
    // password for someone else. Promoting the account they already use avoids
    // both, and keeps one account per ID number, which is the whole point of
    // keying on the school ID.
    //
    // Demotion runs through the same endpoint deliberately: a promotion you
    // cannot undo is worse than one you can, and an accidental Super Admin is
    // otherwise permanent.
    public function changeRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:student,teacher,staff,super_admin',
        ]);

        $user  = User::findOrFail($id);
        $actor = $request->user();
        $from  = $user->role;
        $to    = $request->role;

        // Changing your own role is refused rather than guarded: a Super Admin
        // demoting themselves would lose the very permission needed to undo it,
        // and self-promotion is meaningless since only a Super Admin can reach
        // this endpoint at all.
        if ($user->id === $actor->id) {
            return response()->json([
                'message' => 'You cannot change your own role. Ask another Super Admin to do it.',
            ], 403);
        }

        // Second line of defence against leaving nobody in charge.
        //
        // Note it is currently UNREACHABLE through the API, and that is fine:
        // reaching this endpoint requires the super_admin role, and the self-
        // change above is refused — so whenever the target is a Super Admin the
        // actor is a different Super Admin who survives, and one always remains.
        // The self-refusal is what actually guarantees the property. This stays
        // because that reasoning depends on the check above, and a future change
        // relaxing it would otherwise silently make it possible to strand the
        // system with no administrator at all.
        if ($from === 'super_admin' && $to !== 'super_admin') {
            $remaining = User::where('role', 'super_admin')->where('id', '!=', $user->id)->count();

            if ($remaining === 0) {
                return response()->json([
                    'message' => 'This is the only Super Admin. Promote another account first, or there would be nobody left who can manage the system.',
                ], 422);
            }
        }

        if ($from === $to) {
            return response()->json([
                'message' => 'That account already has this role.',
            ], 422);
        }

        $user->update(['role' => $to]);
        // Both have to move together: the `role` column is what the app reads,
        // while Spatie's tables are what the permission middleware checks.
        // Updating one without the other yields an account that looks promoted
        // but is refused at every gate, or vice versa.
        $user->syncRoles([$to]);

        AuditLog::create([
            'user_id'     => $actor->id,
            'action'      => 'change_user_role',
            'target_type' => 'user',
            'target_id'   => $user->id,
            // Records what actually changed. The old generic "updated account"
            // description could not tell a role change from an email edit,
            // which is exactly the distinction an auditor cares about.
            'description' => "{$actor->display_name} changed {$user->display_name}'s role from {$from} to {$to}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message' => "Role changed from {$from} to {$to}.",
            'user'    => $user->fresh(),
        ]);
    }

    // Super admin updates a user
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Role is deliberately NOT accepted here. It used to be, with no guards
        // at all — so this endpoint could demote the last Super Admin and lock
        // everyone out of administration, or let a Super Admin demote
        // themselves. Role changes now go through changeRole(), which carries
        // those checks; leaving a second unguarded path open would make them
        // decorative.
        $request->validate([
            'name'   => 'sometimes|string|max:255',
            'email'  => 'sometimes|email|unique:users,email,' . $id,
            'status' => 'sometimes|in:active,deactivated',
        ]);

        $user->update($request->only(['name', 'email', 'status']));

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_user',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} updated account for {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($user);
    }

    // Delete a user — super admin can delete anyone; staff granted
    // delete_accounts can only delete students/teachers, never a peer
    // staff account or a super admin.
    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $actor = $request->user();

        if ($user->id === $actor->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.'
            ], 403);
        }

        if ($actor->role === 'staff' && in_array($user->role, ['staff', 'super_admin'])) {
            return response()->json([
                'message' => 'Staff can only delete student or teacher accounts.'
            ], 403);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'delete_user',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} deleted account for {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    // Activate a user account
    public function activate(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update(['status' => 'active']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'activate_user',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} activated account for {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'User activated.']);
    }

    // Deactivate a user account
    public function deactivate(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.'
            ], 403);
        }

        $user->update(['status' => 'deactivated']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'deactivate_user',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} deactivated account for {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'User deactivated.']);
    }

    // Reset a user's password — super admin only
    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::default()],
        ]);

        $user = User::findOrFail($id);
        $user->update(['password' => Hash::make($request->password)]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'reset_password',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} reset password for {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    // List all grantable permissions — super admin only, powers the grant/revoke UI
    public function permissionsList()
    {
        return response()->json(Permission::all(['id', 'name']));
    }

    // Grant a specific permission to a staff member — super admin only
    public function grantPermission(Request $request, $id)
    {
        $request->validate([
            'permission' => 'required|string|exists:permissions,name',
        ]);

        $user = User::findOrFail($id);

        if (! in_array($user->role, ['staff', 'super_admin'])) {
            return response()->json([
                'message' => 'Permissions can only be granted to staff accounts.'
            ], 422);
        }

        $user->givePermissionTo($request->permission);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'grant_permission',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} granted '{$request->permission}' to {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message'     => "Permission '{$request->permission}' granted to {$user->display_name}.",
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    // Revoke a specific permission from a staff member — super admin only
    public function revokePermission(Request $request, $id)
    {
        $request->validate([
            'permission' => 'required|string|exists:permissions,name',
        ]);

        $user = User::findOrFail($id);
        $user->revokePermissionTo($request->permission);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'revoke_permission',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->display_name} revoked '{$request->permission}' from {$user->display_name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message'     => "Permission '{$request->permission}' revoked from {$user->display_name}.",
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    // Get user's own profile
    public function profile(Request $request)
    {
        return response()->json([
            'user'         => $request->user(),
            'roles'        => $request->user()->getRoleNames(),
            'permissions'  => $request->user()->getAllPermissions()->pluck('name'),
            'favorites'    => $request->user()->favorites()->with('thesis')->get(),
            'history'      => $request->user()->readingHistory()
                                ->with('thesis')
                                ->latest('viewed_at')
                                ->limit(10)
                                ->get(),
            // This user's own recent searches — safe for any role to see
            // about themselves, unlike the full audit log (staff/super_admin
            // only). Reads the structured term via AuditLog::searchQuery()
            // rather than re-parsing the display sentence here.
            'recent_searches' => AuditLog::where('user_id', $request->user()->id)
                                ->where('action', 'search')
                                ->latest('created_at')
                                ->limit(5)
                                ->get(['description', 'metadata', 'created_at'])
                                ->map(fn (AuditLog $log) => [
                                    'query'      => $log->searchQuery(),
                                    'created_at' => $log->created_at,
                                ])
                                ->filter(fn ($s) => $s['query'] !== null)
                                ->values(),
        ]);
    }

    // Update own profile
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $request->user()->id,
        ]);

        $request->user()->update($request->only(['name', 'email']));

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $request->user(),
        ]);
    }

    // Upload/replace own profile picture
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|max:2048',
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'user'    => $user,
        ]);
    }

    // Remove own profile picture, falling back to the initials avatar
    public function removeAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json([
            'message' => 'Profile picture removed.',
            'user'    => $user,
        ]);
    }
}