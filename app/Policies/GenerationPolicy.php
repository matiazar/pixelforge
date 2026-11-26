<?php

namespace App\Policies;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    /**
     * Only the owner, only failed ones. A local failure is final only after
     * reconciliation, which the dispatcher does before failing it.
     */
    public function retry(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id
            && $generation->status === GenerationStatus::Failed;
    }
}
