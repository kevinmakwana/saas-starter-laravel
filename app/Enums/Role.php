<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Owners and admins manage members, invitations and see the audit log. */
    public function canManageTeam(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** @return list<self> Roles that can be assigned through an invitation. */
    public static function invitable(): array
    {
        return [self::Admin, self::Member];
    }
}
