<?php

namespace App\Notifications;

/** Sent to the members of a team its owner deleted. */
class TeamDeleted extends AppNotification
{
    public function __construct(private string $teamName, private string $deletedBy) {}

    protected function kind(): string
    {
        return 'team.deleted';
    }

    protected function params(): array
    {
        return ['team' => $this->teamName, 'by' => $this->deletedBy];
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
