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
 * @property bool $pinned
 * @property bool $enabled
 */
class ModInstall extends Model
{
    protected $fillable = ['server_id', 'package', 'name', 'version', 'icon', 'dependency', 'requires', 'files', 'pinned', 'enabled'];

    protected $casts = [
        'dependency' => 'boolean',
        'pinned' => 'boolean',
        'enabled' => 'boolean',
        'requires' => 'array',
        'files' => 'array',
    ];
}
