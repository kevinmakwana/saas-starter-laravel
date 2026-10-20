<?php

namespace App\Enums;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';

    public function label(): string
    {
        return (string) config("billing.plans.{$this->value}.name");
    }

    public function price(BillingInterval $interval = BillingInterval::Monthly): int
    {
        return $interval === BillingInterval::Yearly
            ? (int) config("billing.plans.{$this->value}.yearly_price")
            : (int) config("billing.plans.{$this->value}.price");
    }

    public function hasYearlyPrice(): bool
    {
        return config("billing.plans.{$this->value}.yearly_price") !== null;
    }

    public function stripePrice(BillingInterval $interval = BillingInterval::Monthly): ?string
    {
        $key = $interval === BillingInterval::Yearly ? 'stripe_price_yearly' : 'stripe_price';

        return config("billing.plans.{$this->value}.{$key}") ?: null;
    }

    /** @return int|null null = unlimited */
    public function limit(string $key): ?int
    {
        return config("billing.plans.{$this->value}.limits.{$key}");
    }

    public function includes(string $feature): bool
    {
        return in_array($this->value, config("billing.features.{$feature}", []), true);
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    public static function fromStripePrice(?string $price): ?self
    {
        if ($price === null || $price === '') {
            return null;
        }

        foreach (self::cases() as $plan) {
            foreach (BillingInterval::cases() as $interval) {
                if ($plan->stripePrice($interval) === $price) {
                    return $plan;
                }
            }
        }

        return null;
    }

    /** Cheapest plan that raises $key above $current usage, for upgrade prompts. */
    public function nextWithRoomFor(string $key, int $needed): ?self
    {
        foreach (self::cases() as $plan) {
            $limit = $plan->limit($key);
            if ($plan->rank() > $this->rank() && ($limit === null || $limit >= $needed)) {
                return $plan;
            }
        }

        return null;
    }
}
