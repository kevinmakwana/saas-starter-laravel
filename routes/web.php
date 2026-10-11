<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TeamOwnershipController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'team'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/teams/create', [TeamController::class, 'create'])->name('teams.create');
    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::put('/current-team', [TeamController::class, 'switch'])->name('teams.switch');

    // Signed + expiring link from the invitation email; login redirects back here.
    Route::get('/invitations/{invitation}', [TeamInvitationController::class, 'accept'])
        ->middleware('signed')->name('invitations.accept');

    Route::middleware('team')->group(function () {
        Route::get('/team', [TeamController::class, 'show'])->name('teams.show');
        Route::patch('/team', [TeamController::class, 'update'])->name('teams.update');
        Route::patch('/team/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('/team/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
        Route::post('/team/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
        Route::post('/team/ownership', [TeamOwnershipController::class, 'store'])->name('teams.ownership');
    });
});

require __DIR__.'/auth.php';
