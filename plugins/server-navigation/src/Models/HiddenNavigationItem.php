<?php

namespace Marschux\ServerNavigation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $server_id
 * @property string $slug
 */
class HiddenNavigationItem extends Model
{
    protected $table = 'server_hidden_navigation';

    protected $fillable = ['server_id', 'slug'];
}
