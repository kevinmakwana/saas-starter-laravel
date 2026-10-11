<?php

namespace App\Providers;

use App\Billing\BillingGateway;
use App\Billing\FakeGateway;
use App\Billing\StripeGateway;
use App\Models\Team;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BillingGateway::class, fn () => config('billing.gateway') === 'stripe' && config('cashier.secret')
            ? new StripeGateway
            : new FakeGateway);
        $this->app->scoped(TenantContext::class);
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
