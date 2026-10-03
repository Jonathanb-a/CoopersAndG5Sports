<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\UserRole;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditStoreProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Datos de la tienda';
    }

    public static function canView(Model $tenant): bool
    {
        return auth()->user()?->role === UserRole::Owner;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('whatsapp_phone')
                    ->label('WhatsApp')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('telegram_chat_id')
                    ->label('ID de chat de Telegram')
                    ->maxLength(255),
                Textarea::make('bank_info')
                    ->label('Datos bancarios')
                    ->rows(4),
                Textarea::make('about_text')
                    ->label('Quiénes somos')
                    ->helperText('Se muestra en el inicio de la tienda. Déjalo vacío para ocultar la sección.')
                    ->rows(6),
                Toggle::make('is_active')
                    ->label('Tienda activa'),
            ]);
    }
}
