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

    /** @return array<int|string, bool> keyed by feature name */
    public function all(Team $team): array
    {
        $plan = $team->currentPlan();

        $features = [];
        foreach (array_keys(config('billing.features')) as $feature) {
            $features[$feature] = $plan->includes((string) $feature);
        }

        return $features;
    }
}
