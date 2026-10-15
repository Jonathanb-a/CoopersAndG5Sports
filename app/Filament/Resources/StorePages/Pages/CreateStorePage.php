<?php

namespace App\Filament\Resources\StorePages\Pages;

use App\Filament\Resources\StorePages\StorePageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStorePage extends CreateRecord
{
    protected static string $resource = StorePageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return StorePageResource::guardPublication($data);
    }
}
