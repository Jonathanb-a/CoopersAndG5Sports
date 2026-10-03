<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Resources\Banners\BannerResource;
use App\Models\Banner;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;

    /** Un banner nuevo entra al final del carrusel; después se reordena arrastrando. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['position'] = (int) Banner::withoutGlobalScopes()
            ->where('store_id', Filament::getTenant()?->getKey())
            ->max('position') + 1;

        return $data;
    }
}
