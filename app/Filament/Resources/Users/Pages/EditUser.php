<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\SendPasswordLinkAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendPasswordLinkAction::make(),
        ];
    }
}
