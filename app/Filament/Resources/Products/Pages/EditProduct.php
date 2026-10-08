<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * products.cost está oculto en el modelo (no sale en arreglos ni JSON), y el formulario se llena con
     * esos arreglos: solo al owner se le pasa el costo, para que lo vea y lo pueda editar. El staff ni
     * siquiera lo recibe en el estado del formulario.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (auth()->user()?->role === UserRole::Owner) {
            $data['cost'] = $this->getRecord()->cost;
        }

        return $data;
    }
}
