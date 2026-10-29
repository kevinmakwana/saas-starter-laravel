<?php

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

// Bearer-token API (see docs/api.md). Reading needs `projects:read`, changing things `projects:write`.
Route::prefix('v1')->middleware(['auth:sanctum', 'token.team', 'throttle:api'])->group(function () {
    Route::middleware('abilities:projects:read')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::get('/projects/{project}/tasks', [TaskController::class, 'index'])->scopeBindings();
    });

    Route::middleware('abilities:projects:write')->group(function () {
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
        Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
        Route::patch('/projects/{project}/tasks/{task}', [TaskController::class, 'update'])->scopeBindings();
        Route::delete('/projects/{project}/tasks/{task}', [TaskController::class, 'destroy'])->scopeBindings();
    });
});
