<?php

namespace Marschux\AccessLists\Filament\Server\Pages;

use RuntimeException;

/**
 * A save that failed for a reason the user can act on - unknown player, invalid json, a file
 * that could not be parsed. Its message is already translated and is shown as-is.
 */
class ListEditException extends RuntimeException {}
