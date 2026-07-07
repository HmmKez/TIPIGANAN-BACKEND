<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role'     => 'required|in:student,teacher',
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
            'user_id'     => $user->id,
            'action'      => 'register',
            'target_type' => 'user',
            'target_id'   => $user->id,
            'description' => "{$user->name} registered as {$user->role}",
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
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

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
            'description' => "{$user->name} logged in",
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
            'description' => "{$request->user()->name} logged out",
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
            'password'         => 'required|string|min:8|confirmed',
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
            'description' => "{$request->user()->name} changed their password",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}