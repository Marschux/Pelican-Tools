<?php

namespace Marschux\AccessLists\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Marschux\AccessLists\Lists\ListDefinition;
use Marschux\AccessLists\Lists\ListFile;
use Marschux\AccessLists\Lists\MinecraftUuid;
use Marschux\AccessLists\Models\AccessList;
use Throwable;

/**
 * Edits the access list files configured for this server's egg - admin lists, ban lists,
 * whitelists.
 *
 * Only files: no player list, no console commands. A file is picked from the tabs, its entries
 * are edited as tags, and whoever needs the file exactly as it is switches to the text mode.
 */
class AccessListsPage extends Page implements HasForms
{
    use BlockAccessInConflict;
    use InteractsWithForms;

    protected string $view = 'access-lists::pages.access-lists';

    protected static string|BackedEnum|null $navigationIcon = 'tabler-list-check';

    protected static ?string $slug = 'access-lists';

    protected static ?int $navigationSort = 31;

    /** Far above any real list file, only there to refuse something absurd. */
    private const MAX_FILE_BYTES = 1048576;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Whether the file existed when it was read - deciding whether saving creates it. */
    private bool $fileExisted = false;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && user()?->can(SubuserPermission::FileReadContent, $server)
            && AccessList::definitionsFor($server) !== [];
    }

    public static function getNavigationLabel(): string
    {
        return trans('access-lists::lists.page.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->data = [
            'file' => array_key_first($this->definitions()),
            'raw' => false,
        ];

        $this->read();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Toggle::make('raw')
                    ->label(trans('access-lists::lists.page.raw'))
                    ->helperText(trans('access-lists::lists.page.raw_hint'))
                    ->live()
                    ->inline(false),
                TagsInput::make('entries')
                    ->label(trans('access-lists::lists.page.entries'))
                    ->helperText(fn () => $this->definition()?->hint)
                    ->placeholder(trans('access-lists::lists.page.add'))
                    ->visible(fn (Get $get) => !$get('raw'))
                    ->columnSpanFull(),
                Textarea::make('text')
                    ->helperText(fn () => $this->definition()?->hint)
                    ->label(trans('access-lists::lists.page.text'))
                    ->rows(18)
                    ->visible(fn (Get $get) => (bool) $get('raw'))
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<string, string> key => label, one tab per file */
    public function fileTabs(): array
    {
        return array_map(fn (ListDefinition $definition) => $definition->label, $this->definitions());
    }

    public function switchFile(string $key): void
    {
        // called straight from the browser, so only a file this server actually has
        if (!array_key_exists($key, $this->definitions())) {
            return;
        }

        $this->data['file'] = $key;

        $this->read();
    }

    public function reload(): void
    {
        $this->read();

        Notification::make()->title(trans('access-lists::lists.messages.reloaded'))->success()->send();
    }

    public function save(): void
    {
        $server = $this->server();

        abort_unless(user()?->can(SubuserPermission::FileUpdate, $server), 403);
        abort_unless($this->fileExisted || user()?->can(SubuserPermission::FileCreate, $server), 403);

        $definition = $this->definition();

        if (!$definition) {
            return;
        }

        $state = $this->form->getState();

        try {
            $content = ($state['raw'] ?? false)
                ? $this->rawContent($definition, (string) ($state['text'] ?? ''))
                : $this->builtContent($definition, array_map(strval(...), $state['entries'] ?? []));
        } catch (ListEditException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        try {
            $this->files()->putContent($definition->path, $content);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title(trans('access-lists::lists.messages.failed'))->danger()->send();

            return;
        }

        Activity::event('server:file.write')->property('file', $definition->path)->log();

        Notification::make()->title(trans('access-lists::lists.messages.saved'))->success()->send();

        $this->read();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->server()), 403);
    }

    /** Text mode writes the file as typed; json at least has to parse, or the game cannot read it. */
    private function rawContent(ListDefinition $definition, string $text): string
    {
        if ($definition->isJson() && ListFile::jsonEntries($text) === null) {
            throw new ListEditException(trans('access-lists::lists.messages.invalid_json'));
        }

        return $text;
    }

    /** @param  string[]  $entries */
    private function builtContent(ListDefinition $definition, array $entries): string
    {
        $current = $this->readFile($definition->path);

        if (!$definition->isJson()) {
            return ListFile::buildLines($current ?? '', $entries);
        }

        $existing = ListFile::jsonEntries($current ?? '');

        if ($existing === null) {
            // never overwrite a file we could not read - whatever is in there would be lost
            throw new ListEditException(trans('access-lists::lists.messages.unreadable'));
        }

        $offline = $definition->needsUuid() && MinecraftUuid::isOfflineMode($this->readFile('server.properties'));

        return ListFile::encodeJson(ListFile::buildJson(
            $existing,
            $entries,
            $definition->valueField(),
            fn (string $value) => $this->newEntry($definition, $value, $offline),
        ));
    }

    /** @return array<string, mixed> */
    private function newEntry(ListDefinition $definition, string $value, bool $offline): array
    {
        $replacements = [
            '{name}' => $value,
            '{ip}' => $value,
            '{now}' => date('Y-m-d H:i:s O'),
        ];

        if ($definition->needsUuid()) {
            $uuid = MinecraftUuid::fromInput($value);

            if ($uuid !== null) {
                // a uuid was typed: take it as it is, and store the account's name next to it -
                // an offline server has no account to ask, so the uuid stands in for the name
                $replacements['{uuid}'] = $uuid;
                $replacements['{name}'] = $offline ? $value : $this->nameFor($uuid);
            } else {
                $replacements['{uuid}'] = $offline ? MinecraftUuid::offline($value) : $this->lookup($value);
            }
        }

        return ListFile::fillTemplate($definition->templateArray(), $replacements);
    }

    private function nameFor(string $uuid): string
    {
        try {
            $name = MinecraftUuid::nameFor($uuid, 5);
        } catch (Throwable $exception) {
            report($exception);

            throw new ListEditException(trans('access-lists::lists.messages.mojang_unreachable'));
        }

        if ($name === null) {
            throw new ListEditException(trans('access-lists::lists.messages.unknown_player', ['name' => $uuid]));
        }

        return $name;
    }

    private function lookup(string $name): string
    {
        try {
            $uuid = MinecraftUuid::lookup($name, 5);
        } catch (Throwable $exception) {
            report($exception);

            throw new ListEditException(trans('access-lists::lists.messages.mojang_unreachable'));
        }

        if ($uuid === null) {
            throw new ListEditException(trans('access-lists::lists.messages.unknown_player', ['name' => $name]));
        }

        return $uuid;
    }

    /** Reads the selected file and refills the form from it. */
    private function read(): void
    {
        $definition = $this->definition();

        if (!$definition) {
            return;
        }

        $content = $this->readFile($definition->path);
        $this->fileExisted = $content !== null;

        $entries = $definition->isJson()
            ? ListFile::displayValues(ListFile::jsonEntries($content ?? '') ?? [], $definition->valueField())
            : ListFile::lineEntries($content ?? '');

        $this->form->fill([
            'file' => $definition->key,
            'raw' => (bool) ($this->data['raw'] ?? false),
            'entries' => $entries,
            'text' => $content ?? '',
        ]);

        if (!$this->fileExisted) {
            Notification::make()->title(trans('access-lists::lists.messages.missing_file'))->warning()->send();
        }
    }

    /** Null when the file does not exist yet, which is normal for a list nobody has used. */
    private function readFile(string $path): ?string
    {
        try {
            return (string) $this->files()->getContent($path, self::MAX_FILE_BYTES);
        } catch (FileNotFoundException) {
            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function definition(): ?ListDefinition
    {
        return $this->definitions()[$this->data['file'] ?? ''] ?? null;
    }

    /**
     * Only what was configured for this egg in the admin panel - an egg without an entry gets no
     * lists at all, even if the game's files are there.
     *
     * @return array<string, ListDefinition>
     */
    private function definitions(): array
    {
        return AccessList::definitionsFor($this->server());
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server());
    }
}
