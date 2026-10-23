<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuditLogExportController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskExportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TeamOwnershipController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified', 'team'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/teams/create', [TeamController::class, 'create'])->name('teams.create');
    Route::post('/teams', [TeamController::class, 'store'])->middleware('throttle:team-create')->name('teams.store');
    Route::put('/current-team', [TeamController::class, 'switch'])->name('teams.switch');

    // Signed + expiring link from the invitation email; login redirects back here.
    Route::get('/invitations/{invitation}', [TeamInvitationController::class, 'accept'])
        ->middleware('signed')->name('invitations.accept');

    Route::middleware('team')->group(function () {
        Route::get('/team', [TeamController::class, 'show'])->name('teams.show');
        Route::patch('/team', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('/team', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::patch('/team/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('/team/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
        Route::post('/team/invitations', [TeamInvitationController::class, 'store'])->middleware('throttle:invitations')->name('teams.invitations.store');
        Route::delete('/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
        Route::get('/projects/{project}/tasks/export', TaskExportController::class)->middleware(['feature:task_export', 'throttle:exports'])->name('projects.tasks.export');
        Route::resource('projects', ProjectController::class);
        Route::resource('projects.tasks', TaskController::class)->only(['store', 'update', 'destroy'])->scoped();
        Route::get('/audit-log/export', AuditLogExportController::class)->middleware(['feature:audit_log', 'throttle:exports'])->name('audit-log.export');
        Route::get('/audit-log', [AuditLogController::class, 'index'])->middleware('feature:audit_log')->name('audit-log.index');
        Route::get('/billing', [BillingController::class, 'show'])->name('billing.show');
        Route::middleware('throttle:billing')->group(function () {
            Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
            Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
            Route::post('/billing/change', [BillingController::class, 'change'])->name('billing.change');
            Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        });
        Route::post('/team/ownership', [TeamOwnershipController::class, 'store'])->name('teams.ownership');
    });
});

Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.stripe');

require __DIR__.'/auth.php';
