<?php

namespace App\Notifications;

/** Sent to a member whom someone else removed from a team. */
class MemberRemoved extends AppNotification
{
    public function __construct(private string $teamName) {}

    protected function kind(): string
    {
        return 'member.removed';
    }

    protected function params(): array
    {
        return ['team' => $this->teamName];
    }

    protected function teamId(): ?int
    {
        return null;
    }

    protected function url(): string
    {
        return '/dashboard';
    }
}
