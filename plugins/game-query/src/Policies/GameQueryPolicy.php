<?php

namespace Marschux\GameQuery\Policies;

use App\Models\User;
use Marschux\GameQuery\Models\GameQuery;

/** Game queries are a panel-wide setting, so they follow the same rule as other admin data. */
class GameQueryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isRootAdmin() ?: null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, GameQuery $gameQuery): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isRootAdmin();
    }

    public function update(User $user, GameQuery $gameQuery): bool
    {
        return $user->isRootAdmin();
    }

    public function delete(User $user, GameQuery $gameQuery): bool
    {
        return $user->isRootAdmin();
    }
}
