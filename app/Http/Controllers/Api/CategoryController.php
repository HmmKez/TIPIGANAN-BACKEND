<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Support\SafeCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    // Versioned: the cached payload gained active/restricted counts. Bumping
    // the key means a deploy serves the new shape immediately instead of
    // handing the frontend 5 minutes of entries missing the new fields.
    private const CACHE_KEY = 'categories:index:v2';

    // Anyone can view categories including guests — this is queried on
    // nearly every page (browse, dashboard, upload form), so it's worth
    // caching. Category edits below clear it immediately; the thesis
    // count can lag up to 5 minutes if a thesis elsewhere changes category
    // (acceptable staleness for a display count, not worth invalidating
    // from ThesisController on every write).
    public function index()
    {
        // Three counts, because "how many theses are in this category" has
        // three different right answers depending on who is asking:
        //
        //   theses_count             every row, archived included — what STAFF
        //                            manage (Collection/Category Management).
        //   active_theses_count      what a guest may open.
        //   restricted_theses_count  additionally openable once logged in.
        //
        // A reader's visible total is active + (logged in ? restricted : 0),
        // mirroring ThesisController::index()'s $defaultStatuses exactly. This
        // used to be a bare withCount('theses'), so public UI counted archived
        // theses nobody could open: the Browse filter advertised "CAST 19" but
        // opening it returned 15, and the dashboard's tiles summed to 23 while
        // its own "Total Items" said 19. Counts are returned rather than
        // resolved server-side so the response stays identical for guests and
        // members and can keep sharing one cache entry.
        //
        // Cache the plain array form, not the Eloquent Collection — a
        // cached object is only as stable as the exact class shape it was
        // serialized from; a later model change (e.g. a new column) can
        // leave an old cached entry unable to unserialize correctly.
        $categories = SafeCache::remember(self::CACHE_KEY, 300, function () {
            return Category::withCount([
                'theses',
                'theses as active_theses_count' => fn ($q) => $q->where('status', 'active'),
                'theses as restricted_theses_count' => fn ($q) => $q->where('status', 'restricted'),
            ])->get()->toArray();
        });

        return response()->json($categories);
    }

    public function show($id)
    {
        $category = Category::with('theses')->findOrFail($id);
        return response()->json($category);
    }

    // Staff and above only
    public function store(Request $request)
    {
        // The code is UNIQUE and typed by a human. It used to be invented from
        // the name (`name.slice(0, 4)`), which collided — CABM-B and CABM-H both
        // became "CABM". Two collections cannot share an identifier.
        $validated = $request->validate([
            'name' => 'required|string|unique:categories,name',
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-]+$/', 'unique:categories,code'],
        ]);

        $category = Category::create([
            'name'       => $validated['name'],
            'code'       => strtoupper($validated['code']),
            'created_by' => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'create_category',
            'target_type' => 'category',
            'target_id'   => $category->id,
            'description' => "{$request->user()->display_name} created category {$category->name}",
            'ip_address'  => $request->ip(),
        ]);

        SafeCache::forget(self::CACHE_KEY);

        return response()->json($category, 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|unique:categories,name,' . $id,
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-]+$/', 'unique:categories,code,' . $id],
        ]);

        $category->update([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_category',
            'target_type' => 'category',
            'target_id'   => $category->id,
            'description' => "{$request->user()->display_name} updated category {$category->name}",
            'ip_address'  => $request->ip(),
        ]);

        SafeCache::forget(self::CACHE_KEY);

        return response()->json($category);
    }

    // Staff and above — the cover image shown on the browse/landing pages
    // for this category
    public function uploadCoverImage(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'cover_image' => 'required|image|max:2048',
        ]);

        if ($category->cover_image_path) {
            Storage::disk('public')->delete($category->cover_image_path);
        }

        $path = $request->file('cover_image')->store('category-covers', 'public');
        $category->update(['cover_image_path' => $path]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_category',
            'target_type' => 'category',
            'target_id'   => $category->id,
            'description' => "{$request->user()->display_name} updated the cover image for category {$category->name}",
            'ip_address'  => $request->ip(),
        ]);

        SafeCache::forget(self::CACHE_KEY);

        return response()->json($category);
    }

    public function destroy(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        if ($category->theses()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete a category that has thesis records.'
            ], 422);
        }

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'delete_category',
            'target_type' => 'category',
            'target_id'   => $category->id,
            'description' => "{$request->user()->display_name} deleted category {$category->name}",
            'ip_address'  => $request->ip(),
        ]);

        if ($category->cover_image_path) {
            Storage::disk('public')->delete($category->cover_image_path);
        }

        $category->delete();

        SafeCache::forget(self::CACHE_KEY);

        return response()->json(['message' => 'Category deleted successfully.']);
    }
}