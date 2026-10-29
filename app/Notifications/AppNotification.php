<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notification, shown in the header bell. It is stored, not sent: the emails the app already
 * sends are separate and unchanged. The text is not stored, only a `kind` and its `params`, so the screen
 * can word it (and translate it) when it is shown.
 */
abstract class AppNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{kind: string, params: array<string, string>, team_id: int|null, url: string} */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind(), 'params' => $this->params(), 'team_id' => $this->teamId(), 'url' => $this->url()];
    }

    abstract protected function kind(): string;

    /** @return array<string, string> */
    abstract protected function params(): array;

    /** The team this is about, so opening it can switch to that team. Null when the user may not belong to it any more. */
    abstract protected function teamId(): ?int;

    /** An internal path (never a full URL): where opening the notification leads. */
    abstract protected function url(): string;
}
