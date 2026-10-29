<?php

namespace App\Notifications;

/** Sent to a team's owner and admins when someone accepts an invitation. */
class MemberJoined extends AppNotification
{
    public function __construct(private int $teamId, private string $teamName, private string $memberName) {}

    protected function kind(): string
    {
        return 'member.joined';
    }

    protected function params(): array
    {
        return ['name' => $this->memberName, 'team' => $this->teamName];
    }

    protected function teamId(): ?int
    {
        return $this->teamId;
    }

    protected function url(): string
    {
        return '/team';
    }
}
