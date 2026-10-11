<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'owner_id'];

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
}
