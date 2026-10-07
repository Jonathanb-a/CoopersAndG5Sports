<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\UserRole;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
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
                Section::make('Recoger en tienda')
                    ->description('Lo que ve el cliente al elegir «Recoger en tienda», en su confirmación y en su pedido. Si lo dejas vacío, se le dice que la dirección y el horario se confirman por WhatsApp.')
                    ->schema([
                        TextInput::make('pickup_address')
                            ->label('Dirección de la tienda')
                            ->maxLength(500),
                        TextInput::make('pickup_hours')
                            ->label('Horario para recoger')
                            ->placeholder('Lun a Sáb 10:00 a 19:00')
                            ->maxLength(255),
                        TextInput::make('pickup_map_url')
                            ->label('Enlace de Google Maps')
                            ->helperText('Opcional. Se muestra como «Ver en el mapa».')
                            ->url()
                            ->maxLength(500)
                            ->validationMessages(['url' => 'Pega el enlace completo, empezando con https://']),
                        Textarea::make('pickup_notes')
                            ->label('Indicaciones')
                            ->helperText('Opcional. Por ejemplo: «Local 4, junto a la farmacia».')
                            ->rows(2)
                            ->maxLength(1000),
                    ]),
                Section::make('Entrega local (Zihuatanejo)')
                    ->description('Siempre se ofrece en el checkout, junto con recoger en tienda.')
                    ->schema([
                        TextInput::make('local_shipping_cost')
                            ->label('Costo de entrega local')
                            ->helperText('Opcional. Vacío o 0 = «Gratis» (a convenir). Si pones un costo, se suma al total del pedido.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999999.99)
                            ->rule('decimal:0,2')
                            ->validationMessages([
                                'numeric' => 'El costo debe ser un número.',
                                'min' => 'El costo debe ser de 0 en adelante.',
                                'decimal' => 'El costo puede tener hasta 2 decimales.',
                            ])
                            ->prefix('$'),
                    ]),
                Textarea::make('about_text')
                    ->label('Quiénes somos')
                    ->helperText('Se muestra en el inicio de la tienda. Déjalo vacío para ocultar la sección.')
                    ->rows(6),
                Toggle::make('is_active')
                    ->label('Tienda activa'),
            ]);
    }
}
