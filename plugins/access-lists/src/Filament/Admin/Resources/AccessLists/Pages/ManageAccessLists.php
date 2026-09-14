<?php

namespace Marschux\AccessLists\Filament\Admin\Resources\AccessLists\Pages;

use Filament\Resources\Pages\ManageRecords;
use Marschux\AccessLists\Filament\Admin\Resources\AccessLists\AccessListResource;

class ManageAccessLists extends ManageRecords
{
    protected static string $resource = AccessListResource::class;
}
