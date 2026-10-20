<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'month',
            self::Yearly => 'year',
        };
    }

    /** The interval a Stripe price id bills, or null when it is not one of ours. */
    public static function fromStripePrice(?string $price): ?self
    {
        if ($price === null || $price === '') {
            return null;
        }

        foreach (Plan::cases() as $plan) {
            foreach (self::cases() as $interval) {
                if ($plan->stripePrice($interval) === $price) {
                    return $interval;
                }
            }
        }

        return null;
    }
}
