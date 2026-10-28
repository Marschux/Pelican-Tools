<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Filament\Facades\Filament;
use Illuminate\Http\Client\Events\ResponseReceived;
use Livewire\Livewire;

$calls = [];
Illuminate\Support\Facades\Event::listen(ResponseReceived::class, function ($e) use (&$calls) {
    $calls[] = sprintf('%6.0f ms %s %s', ($e->response->transferStats?->getTransferTime() ?? 0) * 1000, $e->request->method(), strtok($e->request->url(), '?'));
});

$server = App\Models\Server::where('uuid_short', $argv[1] ?? 'ab740b72')->first();
$user = App\Models\User::where('username', 'admin')->first();
Filament::setCurrentPanel(Filament::getPanel('server'));
auth()->login($user);
Filament::setTenant($server);

$t = microtime(true);
$test = Livewire::actingAs($user)->test(Marschux\ModManager\Filament\Server\Pages\ModsPage::class);
printf("mount+render: %.0f ms\n", (microtime(true) - $t) * 1000);
echo implode("\n", $calls), "\n";

$calls = [];
$t = microtime(true);
$test->call('setTab', 'installed');
printf("tab installed: %.0f ms\n", (microtime(true) - $t) * 1000);
echo implode("\n", $calls), "\n";
