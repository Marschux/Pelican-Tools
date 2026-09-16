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
    /** @param ?QueryPlayer[] $playerList null when the protocol has no player list at all */
    public string $hostname;

    public function __construct(
        string $hostname,
        public int $players,
        public int $maxPlayers,
        public ?string $map = null,
        public ?int $bots = null,
        public ?string $version = null,
        public ?string $gameMode = null,
        public ?bool $passworded = null,
        public ?bool $secure = null,
        public ?string $icon = null,
        public ?string $world = null,
        public ?string $seed = null,
        public ?string $password = null,
        public ?int $reservedSlots = null,
        public ?bool $whitelist = null,
        public ?array $playerList = null,
    ) {
        $this->hostname = self::plain($hostname);
    }

    /**
     * Strips the markup games put in their server name.
     *
     * Valheim wraps the name in unity rich text ("<color=lime>Name</color>" - the egg builds
     * that from its COLOR variable), Minecraft uses section colour codes. Neither renders
     * anywhere in the panel, so both would just show up as literal noise.
     */
    public static function plain(string $value): string
    {
        $value = preg_replace('/<\/?(?:color|b|i|u|s|size|material|quad|align|alpha|cspace|font|indent|line-height|lowercase|uppercase|smallcaps|margin|mark|mspace|noparse|nobr|page|pos|space|sprite|strikethrough|style|sub|sup|underline|voffset|width)(?:=[^>]*)?>/i', '', $value) ?? $value;
        // minecraft section colour codes, written as a codepoint so the source stays ascii
        $value = preg_replace('/\x{00a7}./u', '', $value) ?? $value;

        return trim($value);
    }

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
        'world',
        'seed',
        'whitelist',
        'password',
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

    /**
     * Returns a copy with the given properties replaced - readonly properties cannot be set
     * on a clone, and every property here happens to share its name with its constructor
     * parameter, so the current state doubles as the named-argument list.
     */
    private function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
    }

    public function withWorld(?string $world): self
    {
        return $this->with(['world' => $world]);
    }

    public function withSeed(?string $seed): self
    {
        return $this->with(['seed' => $seed]);
    }

    public function withGameMode(?string $gameMode): self
    {
        return $this->with(['gameMode' => $gameMode]);
    }

    public function withWhitelist(?bool $whitelist): self
    {
        return $this->with(['whitelist' => $whitelist]);
    }

    /** password is attached by GameQuery, not by a schema - no protocol ever reports it. */
    public function withPassword(?string $password): self
    {
        return $this->with(['password' => $password]);
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
            'icon' => $this->icon,
            'world' => $this->world,
            'seed' => $this->seed,
            'password' => $this->password,
            'reserved_slots' => $this->reservedSlots,
            'whitelist' => $this->whitelist,
            'players_list' => $this->playerList === null ? null : array_map(fn (QueryPlayer $p) => [
                'name' => $p->name,
                'score' => $p->score,
                'playtime' => $p->playtime,
                'id' => $p->id,
            ], $this->playerList),
        ];
    }
}
