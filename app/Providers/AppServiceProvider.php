<?php

namespace App\Providers;

use App\Billing\BillingGateway;
use App\Billing\FakeGateway;
use App\Billing\StripeGateway;
use App\Models\Team;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Stripe events arrive on POST /webhooks/stripe only. Cashier's own /stripe/webhook route would be a second,
        // separately configured way in, and its payment page does not fit the app's content security policy.
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(Team::class);
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
    }

    /** Named limiters, applied with `throttle:<name>` on routes. */
    private function configureRateLimiting(): void
    {
        $user = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('registration', fn (Request $request) => Limit::perHour((int) config('throttle.registration_per_hour'))->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(5)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        RateLimiter::for('team-create', fn (Request $request) => Limit::perHour(10)->by($user($request)));
        RateLimiter::for('invitations', fn (Request $request) => [
            Limit::perMinute((int) config('throttle.invitations_per_minute'))->by($user($request)),
            Limit::perDay(50)->by($user($request)),
        ]);
        // Only a request for a new address is limited: it sends email and takes the password. Saving the name alone is free.
        RateLimiter::for('email-change', fn (Request $request) => strcasecmp((string) $request->input('email'), (string) $request->user()?->email) === 0
            ? Limit::none()
            : Limit::perHour(5)->by($user($request)));
        // Every attempt counts, wrong passwords included: this hands over everything the app knows about a person.
        RateLimiter::for('data-export', fn (Request $request) => Limit::perHour(3)->by($user($request)));
        // Six digits are guessable, so the code step allows few tries per account, however many addresses they come from.
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by('two-factor|'.($request->session()->get('login.id') ?? $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($user($request)));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($user($request)));
        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute(10)->by($user($request)));
        // Stripe retries and bursts events, so this is generous: it only stops floods.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
