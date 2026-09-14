<?php

return [
    'list' => 'Access list|Access lists',
    'no_lists' => 'No access lists',

    'fields' => [
        'name' => 'Label',
        'name_placeholder' => 'e.g. Minecraft',
        'files' => 'Files',
        'add_file' => 'Add file',
        'label' => 'File label',
        'label_placeholder' => 'e.g. Admins',
        'path' => 'File path',
        'path_hint' => 'Path relative to the server directory, e.g. "savegames/adminlist.txt" or "whitelist.json".',
        'format' => 'Format',
        'key_field' => 'Key field',
        'key_field_hint' => 'Field that identifies an entry, e.g. "uuid" or "ip".',
        'display_field' => 'Display field',
        'display_field_hint' => 'Field shown and entered in the editor, e.g. "name".',
        'entry_template' => 'Template for new entries',
        'entry_template_hint' => 'JSON object for a new entry. Placeholders: {name}, {uuid}, {ip}, {now}. When the template contains {uuid}, the panel looks the uuid up (Mojang, or derived locally in offline mode).',
        'eggs' => 'Eggs',
    ],

    'formats' => [
        'lines' => 'Text lines',
        'json' => 'JSON',
    ],

    'page' => [
        'navigation' => 'Access lists',
        'list' => 'List',
        'entries' => 'Entries',
        'entry' => 'Entry',
        'add' => 'Add entry',
        'raw' => 'Edit as text',
        'raw_hint' => 'Edits the file as it is. Saving only checks whether json is valid.',
        'text' => 'File contents',
        'save' => 'Save',
        'reload' => 'Reload',
    ],

    'messages' => [
        'saved' => 'Saved',
        'reloaded' => 'Reloaded',
        'failed' => 'Saving failed',
        'unreadable' => 'The file is not valid json and was not overwritten',
        'invalid_json' => 'The text entered is not valid json',
        'unknown_player' => 'Player not found at Mojang: :name',
        'mojang_unreachable' => 'Mojang is not reachable',
        'no_lists' => 'No access list is configured for this egg',
        'missing_file' => 'The file does not exist yet - saving will create it',
    ],
];
