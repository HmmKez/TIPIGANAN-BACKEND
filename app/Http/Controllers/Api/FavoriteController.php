<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    // Get all favorites for the logged in user
    public function index(Request $request)
    {
        $favorites = Favorite::with('thesis.category')
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->get();

        return response()->json($favorites);
    }

    // Save a thesis to favorites
    public function store(Request $request, $thesisId)
    {
        $exists = Favorite::where('user_id', $request->user()->id)
            ->where('thesis_id', $thesisId)
            ->exists();

        if ($exists) {
            // "Bookmark", not "favorite" — the feature is called a bookmark
            // everywhere a user can read it, and these messages are the only
            // part of the API that says otherwise. The table, model and routes
            // deliberately keep the older name; renaming those would be a
            // migration and an endpoint change for no user-visible gain.
            return response()->json(['message' => 'Already bookmarked.'], 409);
        }

        $favorite = Favorite::create([
            'user_id'    => $request->user()->id,
            'thesis_id'  => $thesisId,
            'created_at' => now(),
        ]);

        return response()->json($favorite, 201);
    }

    // Remove a thesis from favorites
    public function destroy(Request $request, $thesisId)
    {
        Favorite::where('user_id', $request->user()->id)
            ->where('thesis_id', $thesisId)
            ->delete();

        return response()->json(['message' => 'Bookmark removed.']);
    }
}