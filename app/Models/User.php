<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $current_team_id
 * @property Team|null $currentTeam
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property string|null $pending_email
 * @property Carbon|null $pending_email_expires_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_step',
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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Notifications point at their user without a foreign key, so they would be left behind.
        static::deleting(function (User $user) {
            $user->notifications()->delete();
            // Tokens point at their user without a foreign key (Sanctum's polymorphic design).
            $user->tokens()->delete();
        });
    }

    /**
     * Makes an API token that works in one team only. Like Sanctum's createToken(), the plain text is returned
     * once and only its hash is stored.
     *
     * @param  list<string>  $abilities
     */
    public function issueApiToken(Team $team, string $name, array $abilities, ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $plainText = $this->generateTokenString();

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'team_id' => $team->getKey(),
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainText);
    }

    /** Signing in asks for a code. A setup that was never confirmed does not count. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** A secret was made but its first code was never entered. */
    public function hasPendingTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
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

    /**
     * API tokens made by this user (this narrows the type of HasApiTokens::tokens(), which Sanctum configures to our model).
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
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
