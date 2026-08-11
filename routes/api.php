<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CitationController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ThesisController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ThesisReportController;

// Rate limiting — a generous ceiling on every route below (mitigates
// scraping/abuse of public browse/search) plus a stricter ceiling on the auth
// endpoints (mitigates credential stuffing). Both are NAMED limiters defined in
// AppServiceProvider: inline throttles (`throttle:120,1`) key on the IP alone
// and would share a single counter when nested, so browsing would 429 the login
// route. Per-account brute-force protection lives in AuthController::login.
Route::middleware('throttle:api')->group(function () {

// Public routes
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login',    [AuthController::class, 'login']);
    });
});

// Public browsing
Route::get('/theses',                       [ThesisController::class, 'index']);
Route::get('/theses/serve/{token}', [ThesisController::class, 'servePdf']);
Route::get('/theses/{id}',                  [ThesisController::class, 'show']);
Route::get('/categories',                   [CategoryController::class, 'index']);
Route::get('/categories/{id}',              [CategoryController::class, 'show']);
Route::get('/theses/{thesisId}/citations',  [CitationController::class, 'index']);
Route::get('/theses/{thesisId}/citations/generate', [CitationController::class, 'generate']);

// Public search — logs if logged in
Route::get('/search', [SearchController::class, 'search']);

// Active academic term — rendered in the navbar, which guests see too, so the
// read is public. Only a Super Admin can change it (see the super_admin group).
Route::get('/settings/active-term', [SettingController::class, 'activeTerm']);

// Landing page: hero image + real headline stats + department cards, in one
// request. Public by definition — this is the page guests land on.
Route::get('/landing', [LandingController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Profile — any logged in user
    Route::get('/profile',        [UserController::class, 'profile']);
    Route::put('/profile',        [UserController::class, 'updateProfile']);
    Route::post('/profile/avatar',   [UserController::class, 'uploadAvatar']);
    Route::delete('/profile/avatar', [UserController::class, 'removeAvatar']);

    // User management — staff and above
    Route::middleware('role:staff|super_admin')->group(function () {
        // Viewing user accounts is gated the same way as deleting/resetting
        // them — a plain staff member with neither permission has no reason
        // to see this list.
        Route::middleware('permission:delete_accounts|reset_passwords')->group(function () {
            Route::get('/users',      [UserController::class, 'index']);
            Route::get('/users/{id}', [UserController::class, 'show']);
        });

        Route::get('/audit-logs',         [AuditLogController::class, 'index']);

        Route::middleware('permission:export_reports')->group(function () {
            // CSV, not PDF — the audit log is the one unbounded export here.
            Route::get('/audit-logs/export', [AuditLogController::class, 'exportCsv']);
            Route::get('/reports/export', [ReportController::class, 'exportPdf']);
        });
        
        Route::get('/audit-logs/{id}',    [AuditLogController::class, 'show']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/dashboard',      [ReportController::class, 'dashboard']);
            Route::get('/most-cited',     [ReportController::class, 'mostCited']);
            Route::get('/by-department',  [ReportController::class, 'byDepartment']);
            Route::get('/by-year',        [ReportController::class, 'byYear']);
            Route::get('/most-searched',  [ReportController::class, 'mostSearched']);
            Route::get('/users-online',   [ReportController::class, 'usersOnline']);
        });
    });

    // Super admin only
    Route::middleware('role:super_admin')->group(function () {
        // No POST /users. Accounts are not created by an admin any more —
        // everyone registers themselves with their school ID number, and a
        // Super Admin promotes an existing account instead. The route is gone
        // rather than merely hidden in the UI, so it cannot be called directly.
        Route::patch('/users/{id}/role',                [UserController::class, 'changeRole']);
        Route::put('/users/{id}',                       [UserController::class, 'update']);
        Route::patch('/users/{id}/activate',            [UserController::class, 'activate']);
        Route::patch('/users/{id}/deactivate',          [UserController::class, 'deactivate']);
        Route::get('/permissions',                      [UserController::class, 'permissionsList']);
        Route::post('/users/{id}/grant-permission',     [UserController::class, 'grantPermission']);
        Route::post('/users/{id}/revoke-permission',    [UserController::class, 'revokePermission']);

        // The navbar's "Active Term" — editable in place so it can be rolled
        // over each semester without a code change.
        Route::put('/settings/active-term',             [SettingController::class, 'updateActiveTerm']);

        // The landing page's hero image — uploadable so the image can be
        // swapped without touching the frontend repo.
        Route::post('/landing/hero',                    [LandingController::class, 'updateHero']);
        Route::delete('/landing/hero',                  [LandingController::class, 'resetHero']);

        // Which collections are featured on the landing page.
        Route::put('/landing/collections',              [LandingController::class, 'updateCollections']);
        Route::delete('/landing/collections',           [LandingController::class, 'resetCollections']);
    });

    // Deleting accounts — staff need the delete_accounts permission granted
    // by a Super Admin; Super Admin has it by default via PermissionSeeder.
    Route::middleware(['role:staff|super_admin', 'permission:delete_accounts'])->group(function () {
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
    });

    // Resetting another user's password — staff need the reset_passwords
    // permission granted by a Super Admin; Super Admin has it by default.
    Route::middleware(['role:staff|super_admin', 'permission:reset_passwords'])->group(function () {
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    });

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout',          [AuthController::class, 'logout']);
        Route::get('/me',               [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    // Thesis viewing
    Route::post('/theses/{id}/view-token',   [ThesisController::class, 'generateViewToken']);
    Route::get('/theses/serve/{token}',      [ThesisController::class, 'servePdf']);

    // Citations — log when user copies
    Route::post('/theses/{thesisId}/citations/log', [CitationController::class, 'logCitation']);

    // Reporting a thesis — any logged-in user
    Route::post('/theses/{thesisId}/report', [ThesisReportController::class, 'store']);

    // Favorites
    Route::get('/favorites',                  [FavoriteController::class, 'index']);
    Route::post('/favorites/{thesisId}',      [FavoriteController::class, 'store']);
    Route::delete('/favorites/{thesisId}',    [FavoriteController::class, 'destroy']);

    // Staff and above
    Route::middleware('role:staff|super_admin')->group(function () {
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{id}',          [CategoryController::class, 'update']);
        Route::post('/categories/{id}/cover-image', [CategoryController::class, 'uploadCoverImage']);
        Route::delete('/categories/{id}',       [CategoryController::class, 'destroy']);

        Route::post('/theses',                  [ThesisController::class, 'store']);
        Route::put('/theses/{id}',              [ThesisController::class, 'update']);
        Route::patch('/theses/{id}/archive',    [ThesisController::class, 'archive']);
        Route::patch('/theses/{id}/status',     [ThesisController::class, 'updateStatus']);
        Route::get('/theses/{id}/download',     [ThesisController::class, 'download']);

        // Replacing/restoring the underlying PDF file — see ThesisFilePurger
        // for how superseded versions get cleaned up.
        Route::post('/theses/{id}/file',                          [ThesisController::class, 'replaceFile']);
        Route::post('/theses/{id}/extract-metadata',              [ThesisController::class, 'extractMetadata']);
        Route::get('/theses/{id}/file-versions',                  [ThesisController::class, 'listFileVersions']);
        Route::get('/theses/{id}/file-preview',                   [ThesisController::class, 'previewFile']);
        Route::get('/theses/{id}/file-versions/{versionId}/preview', [ThesisController::class, 'previewFileVersion']);
        Route::post('/theses/{id}/file-versions/{versionId}/restore', [ThesisController::class, 'restoreFileVersion']);
        Route::delete('/theses/{id}/file-versions/{versionId}',   [ThesisController::class, 'deleteFileVersion']);

        Route::put('/theses/{thesisId}/citations/{citationId}', [CitationController::class, 'update']);
        Route::delete('/theses/{thesisId}/citations/{citationId}', [CitationController::class, 'destroy']);

        // Reviewing theses that users have flagged
        Route::get('/thesis-reports',                [ThesisReportController::class, 'index']);
        Route::patch('/thesis-reports/{id}/resolve',  [ThesisReportController::class, 'resolve']);
    });

    // Deleting documents — staff need the delete_documents permission
    // granted by a Super Admin; Super Admin has it by default.
    Route::middleware(['role:staff|super_admin', 'permission:delete_documents'])->group(function () {
        Route::delete('/theses/{id}', [ThesisController::class, 'destroy']);
    });

});

}); // end throttle:api
