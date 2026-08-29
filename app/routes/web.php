<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceImageController;
use App\Http\Controllers\WorkspaceMediaRecoveryController;
use App\Http\Controllers\WorkspaceStyleDemoController;
use App\Http\Controllers\WorkspaceVideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::get('/up', HealthController::class)->name('health');
Route::post('/locale', LocaleController::class)->name('locale.update');

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', fn () => view('profile'))->name('profile');

    Route::middleware('two-factor')->group(function (): void {
        Route::get('/home', fn () => redirect()->route('dashboard'));
        Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
        Route::get('/workspace/terminal', [WorkspaceController::class, 'terminal'])->name('workspace.terminal');
        Route::get('/workspace/runtime-status', [WorkspaceController::class, 'runtimeStatus'])->name('workspace.runtime-status');
        Route::post('/workspace/auth-mode', [WorkspaceController::class, 'selectAuthMode'])->name('workspace.auth-mode');
        Route::get('/workspace/sessions', [WorkspaceController::class, 'sessions'])->name('workspace.sessions.index');
        Route::post('/workspace/session', [WorkspaceController::class, 'selectSession'])->name('workspace.sessions.select');
        Route::delete('/workspace/sessions/{sessionId}', [WorkspaceController::class, 'destroySession'])
            ->whereUuid('sessionId')
            ->name('workspace.sessions.destroy');
        Route::post('/workspace/media', [WorkspaceController::class, 'uploadMedia'])->name('workspace.media.store');
        Route::post('/workspace/images', [WorkspaceController::class, 'uploadImage'])->name('workspace.images.store');
        Route::post('/workspace/projects', [WorkspaceController::class, 'storeProject'])->name('workspace.projects.store');
        Route::put('/workspace/projects/{project}', [WorkspaceController::class, 'updateProject'])->name('workspace.projects.update');
        Route::delete('/workspace/projects/{project}', [WorkspaceController::class, 'destroyProject'])->name('workspace.projects.destroy');
        Route::post('/workspace/projects/{project}/select', [WorkspaceController::class, 'selectProject'])->name('workspace.projects.select');
        Route::post('/workspace/start', [WorkspaceController::class, 'start'])->name('workspace.start');
        Route::post('/workspace/stop', [WorkspaceController::class, 'stop'])->name('workspace.stop');
        Route::delete('/workspace/reservations/{reservation}', [WorkspaceController::class, 'abandon'])->name('workspace.reservations.abandon');
        Route::get('/workspace/images', [WorkspaceImageController::class, 'index'])->name('workspace.images.index');
        Route::get('/workspace/videos', [WorkspaceVideoController::class, 'index'])->name('workspace.videos.index');
        Route::post('/workspace/images/trash', [WorkspaceImageController::class, 'bulkTrash'])->name('workspace.images.bulk-trash');
        Route::post('/workspace/videos/trash', [WorkspaceVideoController::class, 'bulkTrash'])->name('workspace.videos.bulk-trash');
        Route::get('/workspace/recovery', [WorkspaceMediaRecoveryController::class, 'index'])->name('workspace.recovery.index');
        Route::post('/workspace/recovery/actions', [WorkspaceMediaRecoveryController::class, 'update'])->name('workspace.recovery.update');
        Route::get('/workspace/recovery/media/{item}', [WorkspaceMediaRecoveryController::class, 'show'])
            ->where('item', '[A-Za-z0-9_-]+')
            ->name('workspace.recovery.media.show');
        Route::get('/workspace/styles/{skill}/demo', WorkspaceStyleDemoController::class)
            ->where('skill', '[a-z0-9][a-z0-9-]{0,63}')
            ->name('workspace.styles.demo');
        Route::get('/workspace/videos/legacy/{video}', [WorkspaceVideoController::class, 'showLegacy'])->where('video', '.*')->name('workspace.videos.legacy.show');
        Route::patch('/workspace/videos/legacy/{video}', [WorkspaceVideoController::class, 'updateLegacy'])->where('video', '.*')->name('workspace.videos.legacy.update');
        Route::delete('/workspace/videos/legacy/{video}', [WorkspaceVideoController::class, 'destroyLegacy'])->where('video', '.*')->name('workspace.videos.legacy.destroy');
        Route::get('/workspace/images/legacy/{image}', [WorkspaceImageController::class, 'showLegacy'])->where('image', '.*')->name('workspace.images.legacy.show');
        Route::patch('/workspace/images/legacy/{image}', [WorkspaceImageController::class, 'updateLegacy'])->where('image', '.*')->name('workspace.images.legacy.update');
        Route::delete('/workspace/images/legacy/{image}', [WorkspaceImageController::class, 'destroyLegacy'])->where('image', '.*')->name('workspace.images.legacy.destroy');
        Route::get('/workspace/projects/{project}/images/{image}', [WorkspaceImageController::class, 'show'])->where('image', '.*')->name('workspace.images.show');
        Route::patch('/workspace/projects/{project}/images/{image}', [WorkspaceImageController::class, 'update'])->where('image', '.*')->name('workspace.images.update');
        Route::delete('/workspace/projects/{project}/images/{image}', [WorkspaceImageController::class, 'destroy'])->where('image', '.*')->name('workspace.images.destroy');
        Route::get('/workspace/projects/{project}/videos/{video}', [WorkspaceVideoController::class, 'show'])->where('video', '.*')->name('workspace.videos.show');
        Route::patch('/workspace/projects/{project}/videos/{video}', [WorkspaceVideoController::class, 'update'])->where('video', '.*')->name('workspace.videos.update');
        Route::delete('/workspace/projects/{project}/videos/{video}', [WorkspaceVideoController::class, 'destroy'])->where('video', '.*')->name('workspace.videos.destroy');
        Route::get('/reservations/nodes', [ReservationController::class, 'nodes'])->name('reservations.nodes');
        Route::get('/reservations/availability', [ReservationController::class, 'availability'])->name('reservations.availability');
        Route::resource('reservations', ReservationController::class)->only(['index', 'create', 'store', 'destroy']);
        Route::post('/reservations/{reservation}/extend', [ReservationController::class, 'extend'])->name('reservations.extend');
    });
});

Route::get('/internal/terminal-authorize', [WorkspaceController::class, 'authorizeTerminal'])
    ->middleware(['auth', 'two-factor'])
    ->name('internal.terminal-authorize');

Route::any('/internal/{path?}', fn () => abort(404))->where('path', '.*');
