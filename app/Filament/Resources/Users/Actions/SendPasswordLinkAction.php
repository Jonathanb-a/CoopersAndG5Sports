<?php

namespace App\Filament\Resources\Users\Actions;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PanelPasswordLink;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Password;

/**
 * El owner le manda a un empleado el enlace para definir su contraseña nueva (sin que nadie vea ni
 * teclee una contraseña). Solo el owner, solo a empleados (no a otros owners ni a sí mismo: para eso está
 * "Mi perfil"). Se vuelve a comprobar al ejecutar, aunque alguien llame la acción por Livewire.
 */
class SendPasswordLinkAction
{
    public static function make(): Action
    {
        return Action::make('sendPasswordLink')
            ->label('Restablecer contraseña')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->visible(fn (User $record): bool => self::allowed($record))
            ->requiresConfirmation()
            ->modalHeading('Restablecer contraseña')
            ->modalDescription(fn (User $record): string => "Le mandamos a {$record->email} un enlace para que elija su contraseña nueva. El enlace vence en ".(int) config('auth.passwords.users.expire').' minutos.')
            ->modalSubmitActionLabel('Enviar enlace')
            ->action(function (User $record): void {
                abort_unless(self::allowed($record), 403);

                $status = PanelPasswordLink::send(['email' => $record->email]);

                if ($status === Password::RESET_THROTTLED) {
                    Notification::make()->title('Ya se le mandó un enlace hace un momento')->body('Espera un minuto para mandar otro.')->warning()->send();

                    return;
                }

                Notification::make()->title('Enlace enviado')->body("Le llegará a {$record->email}.")->success()->send();
            });
    }

    public static function allowed(User $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->role === UserRole::Owner
            && $record->role === UserRole::Staff
            && ! $actor->is($record);
    }
}
