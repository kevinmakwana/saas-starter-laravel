<?php

namespace App\Http\Controllers;

use App\Billing\BillingGateway;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Team;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     *
     * Teams cascade-delete with their owner, so an owner must hand over teams that still have
     * other members first. Teams the user owns alone are deleted, after ending their billing.
     */
    public function destroy(Request $request, BillingGateway $billing): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $owned = Team::where('owner_id', $user->getKey())->get();

        $shared = $owned->first(fn (Team $team) => $team->users()->whereKeyNot($user->getKey())->exists());
        if ($shared !== null) {
            throw ValidationException::withMessages([
                'account' => "You still own “{$shared->name}” with other members. Transfer ownership or remove them before deleting your account.",
            ]);
        }

        foreach ($owned as $team) {
            try {
                $billing->terminate($team);
            } catch (Throwable $e) {
                // Deleting the team would leave a subscription that keeps charging a customer nobody can reach.
                report($e);

                throw ValidationException::withMessages([
                    'account' => "We could not end the subscription of “{$team->name}”, so nothing was deleted. Please try again or contact support.",
                ]);
            }
        }

        Auth::logout();

        DB::transaction(function () use ($user, $owned) {
            $owned->each->delete();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
