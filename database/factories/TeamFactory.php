<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Team',
            'owner_id' => User::factory(),
        ];
    }

    /** Attach the owner as an Owner member and make this their current team. */
    public function configure(): static
    {
        return $this->afterCreating(function (Team $team): void {
            $owner = $team->owner;
            $team->addMember($owner, Role::Owner);
            if ($owner->current_team_id === null) {
                $owner->forceFill(['current_team_id' => $team->id])->save();
            }
        });
    }
}
