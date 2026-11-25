<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ProjectController;
use App\Http\Middleware\SetTenant;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', SetTenant::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);

    Route::middleware('can:manage-tenant')->group(function () {
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
        Route::post('/invitations', [InvitationController::class, 'store']);
    });
});
