<?php

namespace Marschux\ServerResources\Providers;

use App\Models\Node;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Marschux\ServerResources\Resources;

class ServerResourcesPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(plugin_path('server-resources', 'lang'), 'server-resources');
        $this->loadViewsFrom(plugin_path('server-resources', 'resources/views'), 'server-resources');

        // Wings calls this once on every start, authenticated as its node
        Event::listen(RequestHandled::class, function (RequestHandled $event) {
            $node = $event->request->attributes->get('node');

            if ($node instanceof Node && $event->response->isSuccessful() && $event->request->is('api/remote/servers/reset')) {
                Resources::recordNodeStart($node->id);
            }
        });
    }
}
