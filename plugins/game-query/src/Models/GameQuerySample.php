<?php

namespace Marschux\GameQuery\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property int $players
 * @property int $max_players
 * @property ?int $bots
 * @property \Carbon\Carbon $created_at
 */
class GameQuerySample extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'server_id',
        'players',
        'max_players',
        'bots',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
