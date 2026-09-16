<?php

namespace Marschux\ServerNavigation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sidebar entry of one server: whether it shows directly or under "more", and where.
 *
 * @property int $server_id
 * @property string $slug
 * @property bool $visible
 * @property int $sort
 */
class NavigationEntry extends Model
{
    protected $table = 'server_hidden_navigation';

    protected $fillable = ['server_id', 'slug', 'visible', 'sort'];

    protected $casts = ['visible' => 'boolean', 'sort' => 'integer'];
}
