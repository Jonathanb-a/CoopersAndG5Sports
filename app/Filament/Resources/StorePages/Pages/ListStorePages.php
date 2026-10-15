<?php

namespace App\Filament\Resources\StorePages\Pages;

use App\Filament\Resources\StorePages\StorePageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStorePages extends ListRecords
{
    protected static string $resource = StorePageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
