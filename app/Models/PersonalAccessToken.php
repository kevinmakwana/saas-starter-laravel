<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A token for the API. It belongs to a user, works inside one team only, and is either read-only or read and write.
 *
 * @property int $team_id
 * @property Team $team
 */
class PersonalAccessToken extends SanctumToken
{
    public const READ = 'projects:read';

    public const WRITE = 'projects:write';

    /** @var list<string> */
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'team_id'];

    /** @return list<string> the abilities of an access level: "read" or "write" (which includes reading) */
    public static function abilitiesFor(string $access): array
    {
        return $access === 'write' ? [self::READ, self::WRITE] : [self::READ];
    }

    public function access(): string
    {
        return $this->can(self::WRITE) ? 'write' : 'read';
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
