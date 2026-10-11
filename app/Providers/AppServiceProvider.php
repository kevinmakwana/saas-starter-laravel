<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use App\Models\Team;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Billing\BillingGateway::class, fn () => config('billing.gateway') === 'stripe' && config('cashier.secret')
            ? new \App\Billing\StripeGateway
            : new \App\Billing\FakeGateway);
        $this->app->scoped(\App\Support\Tenancy\TenantContext::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(Team::class);
        Vite::prefetch(concurrency: 3);
    }
}
