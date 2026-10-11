<?php

namespace App\Services;

use App\Models\Team;

/** Per-plan feature flags, defined in config/billing.php. */
class FeatureGate
{
    public function allows(Team $team, string $feature): bool
    {
        return $team->currentPlan()->includes($feature);
    }

    /** @return array<string, bool> */
    public function all(Team $team): array
    {
        $plan = $team->currentPlan();

        return collect(array_keys(config('billing.features')))
            ->mapWithKeys(fn (string $feature) => [$feature => $plan->includes($feature)])
            ->all();
    }
}
