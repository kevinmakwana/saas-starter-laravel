<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $current_team_id
 * @property Team|null $currentTeam
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property string|null $pending_email
 * @property Carbon|null $pending_email_expires_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** How long the link for a new email address works. */
    public const EMAIL_CHANGE_TTL_MINUTES = 60;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** The address waiting for confirmation, or null when there is none or its link has run out. */
    public function pendingEmail(): ?string
    {
        return $this->pending_email !== null && $this->pending_email_expires_at?->isFuture() === true
            ? $this->pending_email
            : null;
    }

    public function clearPendingEmail(): void
    {
        $this->forceFill(['pending_email' => null, 'pending_email_expires_at' => null])->save();
    }

    /** @return BelongsTo<Team, $this> */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    public function switchTeam(Team $team): bool
    {
        if (! $team->hasMember($this)) {
            return false;
        }

        $this->forceFill(['current_team_id' => $team->getKey()])->save();
        $this->setRelation('currentTeam', $team);

        return true;
    }

    public function roleIn(Team $team): ?Role
    {
        return $team->roleOf($this);
    }
}
