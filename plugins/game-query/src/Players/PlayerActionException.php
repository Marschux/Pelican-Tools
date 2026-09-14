<?php

namespace Marschux\GameQuery\Players;

use RuntimeException;

/**
 * An action that failed for a reason the user can act on - wrong name, server stopped, file not
 * readable. Its message is already translated and is the only thing shown in the notification;
 * everything else gets a generic message and goes to the log instead.
 */
class PlayerActionException extends RuntimeException
{
    /** @param  array<string, string>  $replace */
    public static function make(string $key, array $replace = []): self
    {
        return new self(trans("game-query::query.players.messages.$key", $replace));
    }
}
