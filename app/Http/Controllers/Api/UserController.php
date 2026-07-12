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

    // Super admin creates a staff account
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', 'string', Password::default()],
            'role'     => 'required|in:staff,super_admin',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
            'status'   => 'active',
        ]);

        $user->assignRole($request->role);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'create_user',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->name} created account for {$user->name} as {$user->role}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($user, 201);
    }

    // Super admin updates a user
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name'   => 'sometimes|string|max:255',
            'email'  => 'sometimes|email|unique:users,email,' . $id,
            'status' => 'sometimes|in:active,deactivated',
            'role'   => 'sometimes|in:student,teacher,staff,super_admin',
        ]);

        $user->update($request->only(['name', 'email', 'status', 'role']));

        if ($request->has('role')) {
            $user->syncRoles([$request->role]);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_user',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$request->user()->name} updated account for {$user->name}",
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
            'description' => "{$request->user()->name} deleted account for {$user->name}",
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
            'description' => "{$request->user()->name} activated account for {$user->name}",
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
            'description' => "{$request->user()->name} deactivated account for {$user->name}",
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
            'description' => "{$request->user()->name} reset password for {$user->name}",
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
            'description' => "{$request->user()->name} granted '{$request->permission}' to {$user->name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message'     => "Permission '{$request->permission}' granted to {$user->name}.",
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
            'description' => "{$request->user()->name} revoked '{$request->permission}' from {$user->name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message'     => "Permission '{$request->permission}' revoked from {$user->name}.",
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
            // only). description is "{name} searched for: {query}"; extract
            // just the query for display.
            'recent_searches' => AuditLog::where('user_id', $request->user()->id)
                                ->where('action', 'search')
                                ->latest('created_at')
                                ->limit(5)
                                ->get(['description', 'created_at'])
                                ->map(fn ($log) => [
                                    'query'      => preg_replace('/^.*searched for: /', '', $log->description),
                                    'created_at' => $log->created_at,
                                ]),
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