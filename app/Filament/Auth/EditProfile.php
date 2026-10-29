<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * "Mi perfil" del panel: nombre, correo y contraseña de quien está dentro. Cambiar el correo o la
 * contraseña pide la contraseña actual (Filament). Al cambiar la contraseña, la sesión actual sigue y las
 * demás se cierran (AuthenticateSession del panel compara la huella de la contraseña).
 *
 * Va en /mi-perfil porque /profile ya es "Datos de la tienda" (EditStoreProfile).
 */
class EditProfile extends BaseEditProfile
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'mi-perfil';
    }

    public function mount(): void
    {
        parent::mount();

        // /mi-perfil no lleva tienda en la dirección, pero el menú del panel (selector de tienda) la necesita:
        // sin una, la plantilla de Filament truena. Se usa la tienda por defecto de quien entra.
        if (Filament::getTenant() === null && ($tenant = Filament::getUserDefaultTenant($this->getUser()))) {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = parent::handleRecordUpdate($record, $data);

        // Una sesión recordada ("mantenerme conectado") en otro dispositivo tampoco debe sobrevivir.
        if (filled($data['password'] ?? null)) {
            $record->forceFill(['remember_token' => Str::random(60)])->save();
        }

        return $record;
    }
}
