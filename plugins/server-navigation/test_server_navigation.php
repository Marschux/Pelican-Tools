<?php

/**
 * Self-check for matching sidebar urls to what the admin hid. Run with: php test_server_navigation.php
 */

require __DIR__ . '/src/Navigation/NavigationUrl.php';

use Marschux\ServerNavigation\Navigation\NavigationUrl;

assert(NavigationUrl::slug('https://panel.test/server/84eb5e20/backups', '84eb5e20') === 'backups');
assert(NavigationUrl::slug('https://panel.test/server/84eb5e20/files/edit?x=1', '84eb5e20') === 'files');
assert(NavigationUrl::slug('/server/84eb5e20', '84eb5e20') === null);
assert(NavigationUrl::slug('https://panel.test/admin/servers/10/edit', '84eb5e20') === null);

assert(NavigationUrl::firstSegment('files/{path?}') === 'files');
assert(NavigationUrl::firstSegment('webhook') === 'webhook');

echo "ok\n";
