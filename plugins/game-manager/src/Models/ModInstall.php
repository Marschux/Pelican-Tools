<?php

namespace Marschux\GameManager\Models;

use Illuminate\Database\Eloquent\Model;
use Marschux\GameManager\Sources\Hexium;

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
 * @property int|null $size  bytes on disk
 * @property bool $adopted  put on the server from outside, then taken over
 */
class ModInstall extends Model
{
    protected $fillable = ['server_id', 'package', 'name', 'version', 'icon', 'dependency', 'requires', 'files', 'pinned', 'enabled', 'size', 'adopted'];

    protected $casts = [
        'dependency' => 'boolean',
        'pinned' => 'boolean',
        'enabled' => 'boolean',
        'adopted' => 'boolean',
        'requires' => 'array',
        'files' => 'array',
    ];

    /** What the package is, whichever site it came from: a Hexium package and its Thunderstore twin are one mod. */
    public function identity(): string
    {
        return Hexium::bare($this->package);
    }

    /** @return string[] what it requires, as identity() names them */
    public function requiredIdentities(): array
    {
        return array_values(array_unique(array_map([Hexium::class, 'bare'], $this->requires)));
    }
}
