<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuditLogExportController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskExportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TeamOwnershipController;
use App\Http\Controllers\TwoFactorController;
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
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:email-change')->name('profile.update');
    Route::get('/profile/email/confirm', [EmailChangeController::class, 'confirm'])->middleware('signed')->name('profile.email.confirm');
    Route::post('/profile/export', DataExportController::class)->middleware('throttle:data-export')->name('profile.export');
    Route::post('/profile/two-factor', [TwoFactorController::class, 'store'])->middleware('password.confirm')->name('profile.two-factor.store');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('profile.two-factor.confirm');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('password.confirm')->name('profile.two-factor.recovery-codes');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'destroy'])->middleware('password.confirm')->name('profile.two-factor.destroy');
    Route::post('/profile/api-tokens', [ApiTokenController::class, 'store'])->middleware(['password.confirm', 'throttle:api-tokens'])->name('profile.api-tokens.store');
    Route::delete('/profile/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('profile.api-tokens.destroy');
    Route::delete('/profile/social-accounts/{account}', [SocialAccountController::class, 'destroy'])->name('profile.social-accounts.destroy');
    Route::delete('/profile/email', [EmailChangeController::class, 'cancel'])->name('profile.email.cancel');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/teams/create', [TeamController::class, 'create'])->name('teams.create');
    Route::post('/teams', [TeamController::class, 'store'])->middleware('throttle:team-create')->name('teams.store');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
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
        Route::post('/team/invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])->middleware('throttle:invitations')->name('teams.invitations.resend');
        Route::delete('/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
        Route::get('/projects/{project}/tasks/export', TaskExportController::class)->middleware(['feature:task_export', 'throttle:exports'])->name('projects.tasks.export');
        Route::resource('projects', ProjectController::class);
        Route::resource('projects.tasks', TaskController::class)->only(['store', 'update', 'destroy'])->scoped();
        Route::post('/projects/{project}/tasks/{task}/attachments', [AttachmentController::class, 'store'])->scopeBindings()->middleware('throttle:uploads')->name('attachments.store');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
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
