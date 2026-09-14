<?php

namespace Marschux\AccessLists\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
use Marschux\AccessLists\Lists\ListFile;
use Marschux\AccessLists\Lists\MinecraftUuid;
use Marschux\AccessLists\Models\AccessList;
use Throwable;

/**
 * Edits the access lists configured for this server's egg - admin lists, ban lists, whitelists.
 *
 * Only files: no player list, no console commands. A list is picked from a select, its entries
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
            && AccessList::forServer($server)->isNotEmpty()
            && user()?->can(SubuserPermission::FileReadContent, $server);
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

        $this->form->fill([
            'list_id' => $this->lists()->first()?->id,
            'raw' => false,
        ]);

        $this->read();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('list_id')
                    ->label(trans('access-lists::lists.page.list'))
                    ->options(fn () => $this->lists()->pluck('name', 'id')->all())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->read()),
                Toggle::make('raw')
                    ->label(trans('access-lists::lists.page.raw'))
                    ->helperText(trans('access-lists::lists.page.raw_hint'))
                    ->live()
                    ->inline(false),
                TagsInput::make('entries')
                    ->label(trans('access-lists::lists.page.entries'))
                    ->placeholder(trans('access-lists::lists.page.add'))
                    ->visible(fn (Get $get) => !$get('raw'))
                    ->columnSpanFull(),
                Textarea::make('text')
                    ->label(trans('access-lists::lists.page.text'))
                    ->rows(18)
                    ->visible(fn (Get $get) => (bool) $get('raw'))
                    ->columnSpanFull(),
            ]);
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

        $list = $this->list();

        if (!$list) {
            return;
        }

        $state = $this->form->getState();

        try {
            $content = ($state['raw'] ?? false)
                ? $this->rawContent($list, (string) ($state['text'] ?? ''))
                : $this->builtContent($list, array_map(strval(...), $state['entries'] ?? []));
        } catch (ListEditException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        try {
            $this->files()->putContent($list->path, $content);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title(trans('access-lists::lists.messages.failed'))->danger()->send();

            return;
        }

        Activity::event('server:file.write')->property('file', $list->path)->log();

        Notification::make()->title(trans('access-lists::lists.messages.saved'))->success()->send();

        $this->read();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->server()), 403);
    }

    /** Text mode writes the file as typed; json at least has to parse, or the game cannot read it. */
    private function rawContent(AccessList $list, string $text): string
    {
        if ($list->isJson() && ListFile::jsonEntries($text) === null) {
            throw new ListEditException(trans('access-lists::lists.messages.invalid_json'));
        }

        return $text;
    }

    /** @param  string[]  $entries */
    private function builtContent(AccessList $list, array $entries): string
    {
        $current = $this->read(false);

        if (!$list->isJson()) {
            return ListFile::buildLines($current ?? '', $entries);
        }

        $existing = ListFile::jsonEntries($current ?? '');

        if ($existing === null) {
            // never overwrite a file we could not read - whatever is in there would be lost
            throw new ListEditException(trans('access-lists::lists.messages.unreadable'));
        }

        $offline = $list->needsUuid() && MinecraftUuid::isOfflineMode($this->read(false, 'server.properties'));

        return ListFile::encodeJson(ListFile::buildJson(
            $existing,
            $entries,
            (string) $list->display_field,
            fn (string $value) => $this->newEntry($list, $value, $offline),
        ));
    }

    /** @return array<string, mixed> */
    private function newEntry(AccessList $list, string $value, bool $offline): array
    {
        $replacements = [
            '{name}' => $value,
            '{ip}' => $value,
            '{now}' => date('Y-m-d H:i:s O'),
        ];

        if ($list->needsUuid()) {
            $replacements['{uuid}'] = $offline
                ? MinecraftUuid::offline($value)
                : $this->lookup($value);
        }

        return ListFile::fillTemplate($list->template(), $replacements);
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

    /**
     * Reads the current list file. With $fill the form is refilled from it, which is what the
     * select, the reload button and a successful save all want.
     */
    private function read(bool $fill = true, ?string $path = null): ?string
    {
        $list = $this->list();

        if (!$list && $path === null) {
            return null;
        }

        try {
            $content = (string) $this->files()->getContent($path ?? $list->path, self::MAX_FILE_BYTES);
            $existed = true;
        } catch (FileNotFoundException) {
            $content = null;
            $existed = false;
        } catch (Throwable $exception) {
            report($exception);

            $content = null;
            $existed = false;
        }

        if ($path !== null) {
            return $content;
        }

        $this->fileExisted = $existed;

        if ($fill) {
            $entries = $list->isJson()
                ? ListFile::displayValues(ListFile::jsonEntries($content ?? '') ?? [], (string) $list->display_field)
                : ListFile::lineEntries($content ?? '');

            $this->form->fill([
                'list_id' => $list->id,
                'raw' => (bool) ($this->data['raw'] ?? false),
                'entries' => $entries,
                'text' => $content ?? '',
            ]);

            if (!$existed) {
                Notification::make()->title(trans('access-lists::lists.messages.missing_file'))->warning()->send();
            }
        }

        return $content;
    }

    private function list(): ?AccessList
    {
        $id = $this->data['list_id'] ?? null;

        return $id === null ? null : $this->lists()->firstWhere('id', (int) $id);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AccessList> */
    private function lists()
    {
        return AccessList::forServer($this->server());
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
