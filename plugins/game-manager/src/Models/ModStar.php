<?php

namespace Marschux\GameManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A browse tile a user starred, for one game (mod profile).
 *
 * @property int $id
 * @property int $user_id
 * @property int $mod_profile_id
 * @property string $package
 * @property array<string, mixed> $item
 */
class ModStar extends Model
{
    protected $fillable = ['user_id', 'mod_profile_id', 'package', 'item'];

    protected $casts = ['item' => 'array'];
}
