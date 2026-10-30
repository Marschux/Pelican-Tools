<?php

namespace Marschux\ModManager\Livewire;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Marschux\ModManager\Lists\ListDefinition;
use Marschux\ModManager\Lists\ListEditException;
use Marschux\ModManager\Lists\ListFile;
use Marschux\ModManager\Lists\MinecraftUuid;
use Marschux\ModManager\Lists\Presets;
use Marschux\ModManager\Models\AccessList;
use Throwable;

/**
 * Edits one access list file of a server - an admin list, ban list, whitelist or server.cfg.
 *
 * Its entries are edited as tags (settings as key and value), and whoever needs the file exactly
 * as it is switches to the text mode. Embedded by the Configs tab of the server management page
 * and by the lists page of games without mods; which file is the embedding page's choice.
 */
class AccessListEditor extends Component implements HasForms
{
    use InteractsWithForms;

    /** Far above any real list file, only there to refuse something absurd. */
    private const MAX_FILE_BYTES = 1048576;

    #[Locked]
    public Server $server;

    /** "<list id>:<index>", see AccessList::definitionsFor() */
    #[Locked]
    public string $file;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Whether the file existed when it was read - deciding whether saving creates it. */
    private bool $fileExisted = false;

    /** shown above the form: the file is not there yet, saving creates it */
    public bool $missing = false;

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileReadContent, $this->server), 403);

        $this->data = ['raw' => false];

        $this->read();
    }

    public function render(): View
    {
        return view('mod-manager::livewire.access-list-editor');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Toggle::make('raw')
                    ->label(trans('mod-manager::lists.page.raw'))
                    ->helperText(trans('mod-manager::lists.page.raw_hint'))
                    ->live()
                    ->inline(false),
                TagsInput::make('entries')
                    ->label(trans('mod-manager::lists.page.entries'))
                    ->helperText(fn () => Presets::hint($this->definition()?->hint))
                    ->placeholder(trans('mod-manager::lists.page.add'))
                    ->visible(fn (Get $get) => !$get('raw') && !$this->definition()?->isPairs())
                    ->columnSpanFull(),
                KeyValue::make('pairs')
                    ->label(trans('mod-manager::lists.page.settings'))
                    ->helperText(fn () => Presets::hint($this->definition()?->hint))
                    ->keyLabel(trans('mod-manager::lists.page.setting_key'))
                    ->valueLabel(trans('mod-manager::lists.page.setting_value'))
                    ->addActionLabel(trans('mod-manager::lists.page.add_setting'))
                    ->reorderable(false)
                    ->visible(fn (Get $get) => !$get('raw') && (bool) $this->definition()?->isPairs())
                    ->columnSpanFull(),
                Textarea::make('text')
                    ->helperText(fn () => Presets::hint($this->definition()?->hint))
                    ->label(trans('mod-manager::lists.page.text'))
                    ->rows(18)
                    ->visible(fn (Get $get) => (bool) $get('raw'))
                    ->columnSpanFull(),
            ]);
    }

    public function reload(): void
    {
        $this->read();

        Notification::make()->title(trans('mod-manager::lists.messages.reloaded'))->success()->send();
    }

    public function save(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileUpdate, $this->server), 403);
        abort_unless($this->fileExisted || user()?->can(SubuserPermission::FileCreate, $this->server), 403);

        $definition = $this->definition();

        if (!$definition) {
            return;
        }

        $state = $this->form->getState();

        try {
            $content = match (true) {
                (bool) ($state['raw'] ?? false) => $this->rawContent($definition, (string) ($state['text'] ?? '')),
                $definition->isPairs() => ListFile::buildPairs(
                    $this->readFile($definition->path) ?? '',
                    array_map(strval(...), $state['pairs'] ?? []),
                    $definition->pairTemplate(),
                ),
                default => $this->builtContent($definition, array_map(strval(...), $state['entries'] ?? [])),
            };
        } catch (ListEditException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        try {
            $this->files()->putContent($definition->path, $content);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()->title(trans('mod-manager::lists.messages.failed'))->danger()->send();

            return;
        }

        Activity::event('server:file.write')->property('file', $definition->path)->log();

        Notification::make()->title(trans('mod-manager::lists.messages.saved'))->success()->send();

        $this->read();
    }

    /** Text mode writes the file as typed; json at least has to parse, or the game cannot read it. */
    private function rawContent(ListDefinition $definition, string $text): string
    {
        if ($definition->isJson() && ListFile::jsonEntries($text) === null) {
            throw new ListEditException(trans('mod-manager::lists.messages.invalid_json'));
        }

        return $text;
    }

    /** @param  string[]  $entries */
    private function builtContent(ListDefinition $definition, array $entries): string
    {
        $current = $this->readFile($definition->path);

        if (!$definition->isJson()) {
            $template = $definition->lineTemplate();

            if ($template !== null) {
                $entries = array_map(fn (string $entry) => ListFile::wrapLine($template, trim($entry)), $entries);
            }

            return ListFile::buildLines($current ?? '', $entries);
        }

        $existing = ListFile::jsonEntries($current ?? '');

        if ($existing === null) {
            // never overwrite a file we could not read - whatever is in there would be lost
            throw new ListEditException(trans('mod-manager::lists.messages.unreadable'));
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

            throw new ListEditException(trans('mod-manager::lists.messages.mojang_unreachable'));
        }

        if ($name === null) {
            throw new ListEditException(trans('mod-manager::lists.messages.unknown_player', ['name' => $uuid]));
        }

        return $name;
    }

    private function lookup(string $name): string
    {
        try {
            $uuid = MinecraftUuid::lookup($name, 5);
        } catch (Throwable $exception) {
            report($exception);

            throw new ListEditException(trans('mod-manager::lists.messages.mojang_unreachable'));
        }

        if ($uuid === null) {
            throw new ListEditException(trans('mod-manager::lists.messages.unknown_player', ['name' => $name]));
        }

        return $uuid;
    }

    /** Reads the file and refills the form from it. */
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

        if ($definition->isPairs()) {
            $entries = [];
        }

        // a line list with a template shows the bare value, the shape around it stays in the file
        if (!$definition->isJson() && $definition->lineTemplate() !== null) {
            $entries = array_map(fn (string $entry) => ListFile::unwrapLine($definition->lineTemplate(), $entry), $entries);
        }

        $this->form->fill([
            'raw' => (bool) ($this->data['raw'] ?? false),
            'entries' => $entries,
            'pairs' => $definition->isPairs() ? ListFile::pairEntries($content ?? '', $definition->pairTemplate()) : [],
            'text' => $content ?? '',
        ]);

        $this->missing = !$this->fileExisted;
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

    /** Only a file configured for this server's egg - the key comes from the embedding page. */
    private function definition(): ?ListDefinition
    {
        return once(fn () => AccessList::definitionsFor($this->server)[$this->file] ?? null);
    }

    private function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }
}
