<?php

namespace Marschux\GameQuery\Filament\Server\Components;

use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A stat tile whose value is hidden behind a click, for values that should not sit in plain
 * sight on the page - namely the server's own connect password.
 */
class MaskedStatBlock extends Stat
{
    protected string $view = 'game-query::components.masked-stat-block';
}
