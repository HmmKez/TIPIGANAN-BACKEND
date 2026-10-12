<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Thesis;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    // Get all favorites for the logged in user
    //
    // A bookmark outlives the item's visibility. If staff archive an item, the
    // bookmark row is KEPT, but the item is sent as unavailable (no title, no
    // details) because this reader may no longer see it - Thesis::
    // visibleStatusesFor is the rule, as everywhere else. When staff restore
    // it, the same row shows the item again with nothing for the reader to
    // redo. Before this, the list sent the full archived record (title,
    // authors, cover) to readers, and opening it then deleted the bookmark.
    public function index(Request $request)
    {
        $visible = Thesis::visibleStatusesFor($request->user());

        $favorites = Favorite::with(['thesis' => fn ($q) => $q->withTrashed()->with('category')])
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->get()
            ->each(function (Favorite $favorite) use ($visible) {
                $thesis = $favorite->thesis;

                if ($thesis && !$thesis->trashed() && in_array($thesis->status, $visible, true)) {
                    $favorite->setAttribute('available', true);
                    return;
                }

                // 'removed' = deleted by a super admin; 'unavailable' = still
                // in the repository but not visible to this reader (archived).
                $favorite->setAttribute('available', false);
                $favorite->setAttribute('unavailable_reason', (!$thesis || $thesis->trashed()) ? 'removed' : 'unavailable');
                $favorite->setRelation('thesis', null);
            });

        return response()->json($favorites);
    }

    // Save a thesis to favorites
    public function store(Request $request, $thesisId)
    {
        // Only something this reader can actually see. Otherwise the endpoint
        // would accept any id at all, including archived work and ids that do
        // not exist.
        if (!Thesis::whereIn('status', Thesis::visibleStatusesFor($request->user()))->whereKey($thesisId)->exists()) {
            return response()->json(['message' => 'That item is not available.'], 404);
        }

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