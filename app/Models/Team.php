<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\Role;
use App\Mail\OverLimitMail;
use App\Services\PlanLimits;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Billable;
use Laravel\Cashier\SubscriptionItem;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string $plan
 * @property Carbon|null $plan_ends_at
 * @property Carbon|null $payment_failed_at
 * @property Carbon|null $payment_grace_ends_at
 * @property string|null $stripe_id
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use Billable, HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'owner_id'];

    protected static function booted(): void
    {
        // Cashier's tables have no foreign key to teams, so they would be left behind as orphans.
        // Whatever changed the plan (Stripe, the portal, a cancelled period ending in a webhook), the owner hears about it.
        static::updated(function (Team $team) {
            if (! $team->wasChanged('plan') || $team->owner === null) {
                return;
            }

            $overages = app(PlanLimits::class)->overages($team);
            if ($overages !== []) {
                Mail::to($team->owner)->send(new OverLimitMail($team, $overages));
            }
        });

        static::deleting(function (Team $team) {
            SubscriptionItem::whereIn('subscription_id', $team->subscriptions()->pluck('id'))->delete();
            $team->subscriptions()->delete();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plan_ends_at' => 'datetime',
            'payment_failed_at' => 'datetime',
            'payment_grace_ends_at' => 'datetime',
        ];
    }

    /** The plan in force right now: a cancelled plan stays active until its period ends. */
    public function currentPlan(): Plan
    {
        $plan = Plan::tryFrom((string) $this->plan) ?? Plan::Free;

        if ($this->plan_ends_at !== null && $this->plan_ends_at->isPast()) {
            return Plan::Free;
        }

        // A failed payment that was not fixed within the grace period costs the paid plan.
        if ($this->paymentOverdue()) {
            return Plan::Free;
        }

        return $plan;
    }

    /** A payment failed and the team is still inside its grace period. */
    public function inPaymentGrace(): bool
    {
        return $this->payment_failed_at !== null && $this->payment_grace_ends_at?->isFuture() === true;
    }

    /** A payment failed and the grace period is over. */
    public function paymentOverdue(): bool
    {
        return $this->payment_failed_at !== null && $this->payment_grace_ends_at?->isPast() === true;
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
