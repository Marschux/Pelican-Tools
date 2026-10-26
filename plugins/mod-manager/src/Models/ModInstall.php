<?php

namespace Marschux\ModManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mod the plugin installed on a server.
 *
 * @property int $id
 * @property int $server_id
 * @property string $package
 * @property string $name
 * @property string $version
 * @property string|null $icon
 * @property bool $dependency
 * @property string[] $requires
 * @property string[] $files
 */
class ModInstall extends Model
{
    protected $fillable = ['server_id', 'package', 'name', 'version', 'icon', 'dependency', 'requires', 'files'];

    protected $casts = [
        'dependency' => 'boolean',
        'requires' => 'array',
        'files' => 'array',
    ];
}
