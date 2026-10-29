<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use App\Services\PlanLimits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;

class AttachmentPolicy
{
    public function __construct(private TenantContext $tenant) {}

    /** Anyone on the team may download and remove its files, like its tasks. */
    public function manage(User $user, Attachment $attachment): bool
    {
        return $this->tenant->id() === (int) $attachment->tenant_id;
    }

    /** Whether a file of $bytes still fits the team's plan. */
    public function upload(User $user, int $bytes): Response
    {
        $team = $this->tenant->current();

        return $team === null ? Response::deny() : app(PlanLimits::class)->checkStorage($team, $bytes);
    }
}
