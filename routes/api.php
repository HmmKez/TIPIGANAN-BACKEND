<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CitationController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ThesisController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ReportController;

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// Public browsing
Route::get('/theses',                       [ThesisController::class, 'index']);
Route::get('/theses/{id}',                  [ThesisController::class, 'show']);
Route::get('/categories',                   [CategoryController::class, 'index']);
Route::get('/categories/{id}',              [CategoryController::class, 'show']);
Route::get('/theses/{thesisId}/citations',  [CitationController::class, 'index']);
Route::get('/theses/{thesisId}/citations/generate', [CitationController::class, 'generate']);

// Public search — logs if logged in
Route::get('/search', [SearchController::class, 'search']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Profile — any logged in user
    Route::get('/profile',        [UserController::class, 'profile']);
    Route::put('/profile',        [UserController::class, 'updateProfile']);

    // User management — staff and above
    Route::middleware('role:staff|super_admin')->group(function () {
        Route::get('/users',              [UserController::class, 'index']);
        Route::get('/users/{id}',         [UserController::class, 'show']);
        Route::get('/audit-logs',         [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{id}',    [AuditLogController::class, 'show']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/dashboard',      [ReportController::class, 'dashboard']);
            Route::get('/most-cited',     [ReportController::class, 'mostCited']);
            Route::get('/by-department',  [ReportController::class, 'byDepartment']);
            Route::get('/by-year',        [ReportController::class, 'byYear']);
            Route::get('/most-searched',  [ReportController::class, 'mostSearched']);
            Route::get('/most-active',    [ReportController::class, 'mostActiveUsers']);
            Route::get('/peak-hours',     [ReportController::class, 'peakHours']);
        });
    });

    // Super admin only
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/users',                           [UserController::class, 'store']);
        Route::put('/users/{id}',                       [UserController::class, 'update']);
        Route::delete('/users/{id}',                    [UserController::class, 'destroy']);
        Route::patch('/users/{id}/activate',            [UserController::class, 'activate']);
        Route::patch('/users/{id}/deactivate',          [UserController::class, 'deactivate']);
        Route::post('/users/{id}/reset-password',       [UserController::class, 'resetPassword']);
        Route::post('/users/{id}/grant-permission',     [UserController::class, 'grantPermission']);
        Route::post('/users/{id}/revoke-permission',    [UserController::class, 'revokePermission']);
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

    // Favorites
    Route::get('/favorites',                  [FavoriteController::class, 'index']);
    Route::post('/favorites/{thesisId}',      [FavoriteController::class, 'store']);
    Route::delete('/favorites/{thesisId}',    [FavoriteController::class, 'destroy']);

    // Staff and above
    Route::middleware('role:staff|super_admin')->group(function () {
        Route::post('/categories',              [CategoryController::class, 'store']);
        Route::put('/categories/{id}',          [CategoryController::class, 'update']);
        Route::delete('/categories/{id}',       [CategoryController::class, 'destroy']);

        Route::post('/theses',                  [ThesisController::class, 'store']);
        Route::put('/theses/{id}',              [ThesisController::class, 'update']);
        Route::patch('/theses/{id}/archive',    [ThesisController::class, 'archive']);
        Route::get('/theses/{id}/download',     [ThesisController::class, 'download']);

        Route::post('/theses/{thesisId}/citations',          [CitationController::class, 'store']);
        Route::put('/theses/{thesisId}/citations/{citationId}', [CitationController::class, 'update']);
        Route::delete('/theses/{thesisId}/citations/{citationId}', [CitationController::class, 'destroy']);
    });

    // Super admin only
    Route::middleware('role:super_admin')->group(function () {
        Route::delete('/theses/{id}', [ThesisController::class, 'destroy']);
    });

});