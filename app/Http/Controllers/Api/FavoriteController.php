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
            return response()->json(['message' => 'Already in favorites.'], 409);
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

        return response()->json(['message' => 'Removed from favorites.']);
    }
}