<?php

namespace Marschux\GameQuery\Query;

/**
 * The result of a single server query.
 *
 * A schema fills what its protocol actually reports and leaves the rest null - null means
 * "this protocol cannot tell us", and the ui hides those fields instead of printing "unknown".
 */
readonly class QueryResult
{
    /**
     * @param  ?QueryPlayer[]  $playerList  null when the protocol has no player list at all
     * @param  ?array<string, string>  $rules  server cvars / plugin list, only fetched on demand
     */
    public function __construct(
        public string $hostname,
        public int $players,
        public int $maxPlayers,
        public ?string $map = null,
        public ?int $bots = null,
        public ?string $version = null,
        public ?string $gameMode = null,
        public ?bool $passworded = null,
        public ?bool $secure = null,
        public ?string $os = null,
        public ?string $icon = null,
        public ?array $playerList = null,
        public ?array $rules = null,
    ) {}

    /** Every field the ui can show, in display order. Drives the admin checkbox list. */
    public const FIELDS = [
        'hostname',
        'players',
        'map',
        'bots',
        'version',
        'gameMode',
        'passworded',
        'secure',
        'os',
        'icon',
    ];

    /** Human players, i.e. without the bots the server counts towards its player number. */
    public function humanPlayers(): int
    {
        return max(0, $this->players - ($this->bots ?? 0));
    }

    public function get(string $field): mixed
    {
        // 'players' is a formatted pair rather than a single property
        if ($field === 'players') {
            return $this->players . ' / ' . $this->maxPlayers;
        }

        return property_exists($this, $field) ? $this->$field : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'players' => $this->players,
            'max_players' => $this->maxPlayers,
            'map' => $this->map,
            'bots' => $this->bots,
            'version' => $this->version,
            'game_mode' => $this->gameMode,
            'passworded' => $this->passworded,
            'secure' => $this->secure,
            'os' => $this->os,
            'icon' => $this->icon,
            'players_list' => $this->playerList === null ? null : array_map(fn (QueryPlayer $p) => [
                'name' => $p->name,
                'score' => $p->score,
                'playtime' => $p->playtime,
                'id' => $p->id,
            ], $this->playerList),
            'rules' => $this->rules,
        ];
    }
}
