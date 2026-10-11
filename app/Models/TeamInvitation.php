<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * @property int $id
 * @property int $team_id
 * @property string $email
 * @property Role $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class TeamInvitation extends Model
{
    /** @var list<string> */
    protected $fillable = ['email', 'role', 'invited_by', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** Signed URL that stops working when the invitation expires. */
    public function acceptUrl(): string
    {
        return URL::temporarySignedRoute('invitations.accept', $this->expires_at, ['invitation' => $this->getKey()]);
    }
}
