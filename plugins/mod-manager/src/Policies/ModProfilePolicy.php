<?php

namespace Marschux\ModManager\Policies;

use App\Models\User;
use Marschux\ModManager\Models\ModProfile;

/** Profiles are a panel-wide setting, so they follow the same rule as other admin data. */
class ModProfilePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isRootAdmin() ?: null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ModProfile $profile): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isRootAdmin();
    }

    public function update(User $user, ModProfile $profile): bool
    {
        return $user->isRootAdmin();
    }

    public function delete(User $user, ModProfile $profile): bool
    {
        return $user->isRootAdmin();
    }
}
