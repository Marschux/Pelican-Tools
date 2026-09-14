<?php

namespace Marschux\AccessLists\Policies;

use App\Models\User;
use Marschux\AccessLists\Models\AccessList;

/** Access lists are a panel-wide setting, so they follow the same rule as other admin data. */
class AccessListPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isRootAdmin() ?: null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AccessList $accessList): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isRootAdmin();
    }

    public function update(User $user, AccessList $accessList): bool
    {
        return $user->isRootAdmin();
    }

    public function delete(User $user, AccessList $accessList): bool
    {
        return $user->isRootAdmin();
    }
}
