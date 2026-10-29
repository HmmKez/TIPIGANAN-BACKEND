<?php

namespace App\Http\Middleware;

use App\Support\SchoolRoster;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With the CSV roster in use, only accounts on the school's list may use the
 * system - every role, staff and Super Admin included.
 *
 * Signing in already checks this (AuthController::login). This covers someone
 * who was already signed in when they left the list: their token is deleted
 * and the response is a 401, which the frontend treats as "signed out".
 *
 * Inert unless the driver is csv AND a list has been imported, so switching
 * the driver on before the first import cannot lock anybody out.
 */
class EnsureOnSchoolRoster
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // An account waiting to be activated at the library cannot keep an old
        // session going (accounts:require-activation also deletes its tokens).
        if ($user && $user->needs_activation) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your account needs to be activated at the library before you can sign in. Please visit the library with your school ID card.',
                'reason'  => 'not_activated',
            ], 401);
        }

        if ($user && SchoolRoster::isEnforced() && ! SchoolRoster::contains($user->id_number)) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your ID number is not on the school\'s list of current students and staff, so you have been signed out. Please visit the library if you think this is a mistake.',
                'reason'  => 'not_on_roster',
            ], 401);
        }

        return $next($request);
    }
}
