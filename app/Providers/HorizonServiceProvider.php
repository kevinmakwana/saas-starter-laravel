<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Long queue waits go to the same address as failed job alerts.
        if ($email = config('alerts.failed_jobs.mail')) {
            Horizon::routeMailNotificationsTo($email);
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null) {
            $allowed = array_map('strtolower', config('horizon.allowed_emails'));

            // Verified only: anyone can register with an address they do not own, and must not inherit its access.
            return $user !== null && $user->hasVerifiedEmail() && in_array(strtolower($user->email), $allowed, true);
        });
    }
}
