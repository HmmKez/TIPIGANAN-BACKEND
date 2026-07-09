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
    private const CACHE_KEY = 'categories:index';

    // Anyone can view categories including guests — this is queried on
    // nearly every page (browse, dashboard, upload form), so it's worth
    // caching. Category edits below clear it immediately; the thesis
    // count can lag up to 5 minutes if a thesis elsewhere changes category
    // (acceptable staleness for a display count, not worth invalidating
    // from ThesisController on every write).
    public function index()
    {
        $categories = SafeCache::remember(self::CACHE_KEY, 300, function () {
            return Category::withCount('theses')->get();
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
        $request->validate([
            'name' => 'required|string|unique:categories,name',
        ]);

        $category = Category::create([
            'name'       => $request->name,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'create_category',
            'target_type' => 'category',
            'target_id'   => $category->id,
            'description' => "{$request->user()->name} created category {$category->name}",
            'ip_address'  => $request->ip(),
        ]);

        SafeCache::forget(self::CACHE_KEY);

        return response()->json($category, 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'required|string|unique:categories,name,' . $id,
        ]);

        $category->update([
            'name' => $request->name,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'update_category',
            'target_type' => 'category',
            'target_id'   => $category->id,
            'description' => "{$request->user()->name} updated category {$category->name}",
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
            'description' => "{$request->user()->name} updated the cover image for category {$category->name}",
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
            'description' => "{$request->user()->name} deleted category {$category->name}",
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