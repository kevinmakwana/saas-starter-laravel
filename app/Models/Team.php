<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\Role;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Billable;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use Billable, HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'owner_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['plan_ends_at' => 'datetime'];
    }

    /** The plan in force right now: a cancelled plan stays active until its period ends. */
    public function currentPlan(): Plan
    {
        $plan = Plan::tryFrom((string) $this->plan) ?? Plan::Free;

        if ($this->plan_ends_at !== null && $this->plan_ends_at->isPast()) {
            return Plan::Free;
        }

        return $plan;
    }

    public function onGracePeriod(): bool
    {
        return $this->plan_ends_at !== null && $this->plan_ends_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function roleOf(User $user): ?Role
    {
        $role = $this->users()->whereKey($user->getKey())->first()?->getRelationValue('pivot')?->role;

        return is_string($role) ? Role::tryFrom($role) : null;
    }

    public function addMember(User $user, Role $role): void
    {
        $this->users()->syncWithoutDetaching([$user->getKey() => ['role' => $role->value]]);
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /** Create a team owned by $owner and make it their current team. */
    public static function createFor(User $owner, string $name): self
    {
        return DB::transaction(function () use ($owner, $name) {
            $team = self::create(['name' => $name, 'owner_id' => $owner->getKey()]);
            $team->addMember($owner, Role::Owner);
            $owner->switchTeam($team);

            return $team;
        });
    }

    /** Remove a member and repoint their current team if it was this one. */
    public function removeMember(User $user): void
    {
        $this->users()->detach($user->getKey());

        if ($user->current_team_id === $this->getKey()) {
            $next = $user->teams()->first();
            $user->forceFill(['current_team_id' => $next?->getKey()])->save();
            $user->unsetRelation('currentTeam');
        }
    }

    public function transferOwnership(User $from, User $to): void
    {
        DB::transaction(function () use ($from, $to) {
            $this->users()->updateExistingPivot($to->getKey(), ['role' => Role::Owner->value]);
            $this->users()->updateExistingPivot($from->getKey(), ['role' => Role::Admin->value]);
            $this->forceFill(['owner_id' => $to->getKey()])->save();
        });
    }
}
