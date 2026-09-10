<?php

namespace Marschux\GameQuery\Models;

use App\Models\Egg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $egg_id
 * @property int $game_query_id
 */
class EggGameQuery extends Model
{
    protected $table = 'egg_game_query';

    protected $fillable = [
        'egg_id',
        'game_query_id',
    ];

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    public function gameQuery(): BelongsTo
    {
        return $this->belongsTo(GameQuery::class);
    }
}
