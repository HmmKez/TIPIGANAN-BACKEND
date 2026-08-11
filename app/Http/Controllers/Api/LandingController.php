<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ReadingHistory;
use App\Models\Setting;
use App\Models\Thesis;
use App\Models\User;
use App\Support\SafeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LandingController extends Controller
{
    private const CACHE_KEY = 'landing:payload';
    private const TTL       = 300;

    /**
     * Everything the public landing page renders, in one request: the hero
     * image, the headline statistics, and the department cards.
     *
     * These numbers used to be hardcoded marketing figures in LandingPage.jsx
     * ("2,052+ items", "1,847 users") that bore no relation to the database.
     * They are now counted for real.
     */
    public function index()
    {
        $data = SafeCache::remember(self::CACHE_KEY, self::TTL, function () {
            // Only ACTIVE theses are counted. Archived ones are gone from the
            // public collection, and restricted ones can't be opened by the
            // guests this page is written for — advertising either would be
            // promising visitors items they cannot actually read.
            $activeTheses = Thesis::where('status', 'active')->count();

            // Which collections a Super Admin chose to feature. Null (setting
            // absent) means "all of them" — an empty array genuinely means
            // "none", so the two must not be conflated.
            $featuredIds = $this->featuredIds();

            $query = Category::query()
                ->withCount(['theses as active_theses_count' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name');

            if (is_array($featuredIds)) {
                $query->whereIn('id', $featuredIds);
            }

            // ->all() matters: this payload gets SERIALIZED into the cache. A
            // Collection left in here comes back from Redis as a
            // __PHP_Incomplete_Class and JSON-encodes to garbage
            // ({"__PHP_Incomplete_Class_Name": "..."}) on every cache HIT,
            // while the cache-miss path looks perfectly fine — so it only
            // breaks on the second request. Cache plain arrays and scalars,
            // never framework objects. (SafeCache guards against this, but only
            // at the top level; here it would be nested inside an array.)
            $collections = $query->get(['id', 'code', 'name', 'cover_image_path'])
                ->map(fn ($c) => [
                    'id'               => $c->id,
                    'code'             => $c->code,
                    'name'             => $c->name,
                    'cover_image_path' => $c->cover_image_path,
                    'total'            => $c->active_theses_count,
                ])
                ->values()
                ->all();

            return [
                'hero_image_path' => Setting::get(Setting::LANDING_HERO_IMAGE),
                'stats' => [
                    'total_theses' => $activeTheses,
                    // Counts EVERY collection in the repository, not just the
                    // featured ones — it is a fact about the archive, and the
                    // grid below it is a curated selection, not the whole truth.
                    'total_categories' => Category::count(),
                    'total_users'      => User::count(),
                    // There is no view_count column — a "view" is a row in
                    // reading_history (already deduplicated to one row per
                    // user+thesis per 30-minute session).
                    'total_views'      => ReadingHistory::count(),
                ],
                'collections' => $collections,
            ];
        });

        return response()->json($data);
    }

    /**
     * The featured category IDs, or null for "all of them".
     */
    private function featuredIds(): ?array
    {
        $raw = Setting::get(Setting::LANDING_CATEGORIES);

        if ($raw === null) {
            return null;
        }

        $ids = json_decode($raw, true);

        // A corrupt or hand-edited value must not blank the landing page — fall
        // back to showing everything rather than nothing.
        return is_array($ids) ? array_map('intval', $ids) : null;
    }

    /**
     * Super Admin only. Chooses which collections are featured on the landing
     * page. Sending every ID and sending none are both meaningful, so the
     * "reset to all" case is a separate endpoint rather than an empty payload.
     */
    public function updateCollections(Request $request)
    {
        $validated = $request->validate([
            'category_ids'   => 'present|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['category_ids'])));

        Setting::set(Setting::LANDING_CATEGORIES, json_encode($ids), $request->user()->id);
        SafeCache::forget(self::CACHE_KEY);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_landing_collections',
            'target_type' => 'setting',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} changed which collections are featured on the landing page (" . count($ids) . ' shown)',
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Featured collections updated.']);
    }

    /**
     * Super Admin only. Clears the selection so every collection shows again.
     */
    public function resetCollections(Request $request)
    {
        Setting::forget(Setting::LANDING_CATEGORIES);
        SafeCache::forget(self::CACHE_KEY);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'reset_landing_collections',
            'target_type' => 'setting',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} reset the landing page to feature every collection",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'All collections are shown again.']);
    }

    /**
     * Super Admin only (gated by `role:super_admin` on the route). Lets the
     * landing page's hero image be swapped from the UI instead of being a file
     * committed to the frontend repo.
     */
    public function updateHero(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $previous = Setting::get(Setting::LANDING_HERO_IMAGE);

        $path = $request->file('image')->store('landing', 'public');

        Setting::set(Setting::LANDING_HERO_IMAGE, $path, $request->user()->id);
        Setting::forgetLandingHero();
        SafeCache::forget(self::CACHE_KEY);

        // Only delete the old file AFTER the new one is committed, so a failed
        // upload can never leave the page with no hero at all.
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_landing_hero',
            'target_type' => 'setting',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} changed the landing page hero image",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message'         => 'Landing page image updated.',
            'hero_image_path' => $path,
        ]);
    }

    /**
     * Revert to the image shipped with the frontend. Clearing the setting is
     * enough — the frontend falls back to its bundled default when the path is
     * null, so there is no "default" file to restore here.
     */
    public function resetHero(Request $request)
    {
        $previous = Setting::get(Setting::LANDING_HERO_IMAGE);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        Setting::forget(Setting::LANDING_HERO_IMAGE);
        Setting::forgetLandingHero();
        SafeCache::forget(self::CACHE_KEY);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'reset_landing_hero',
            'target_type' => 'setting',
            'target_id'   => null,
            'description' => "{$request->user()->display_name} reset the landing page hero image to the default",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message'         => 'Landing page image reset to the default.',
            'hero_image_path' => null,
        ]);
    }
}
