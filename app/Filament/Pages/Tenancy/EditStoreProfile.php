<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\UserRole;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditStoreProfile extends EditTenantProfile
{
    /** Interruptor del formulario => columna de costo que controla. */
    private const SHIPPING_TOGGLES = [
        'offers_local_delivery' => 'local_shipping_cost',
        'offers_national_shipping' => 'national_shipping_cost',
    ];

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
                Section::make('Envíos')
                    ->description('Lo que se ofrece en el checkout de esta tienda. Recoger en tienda siempre está disponible.')
                    ->schema([
                        Toggle::make('offers_local_delivery')
                            ->label('Ofrecer entrega a domicilio en Zihuatanejo')
                            ->live(),
                        $this->shippingCostInput('local_shipping_cost', 'offers_local_delivery')
                            ->label('Costo de entrega local'),
                        Toggle::make('offers_national_shipping')
                            ->label('Ofrecer envíos a todo el estado/país')
                            ->helperText('Apagado, el checkout lo muestra como «Próximamente».')
                            ->live(),
                        $this->shippingCostInput('national_shipping_cost', 'offers_national_shipping')
                            ->label('Costo de envío nacional'),
                    ]),
                Textarea::make('about_text')
                    ->label('Quiénes somos')
                    ->helperText('Se muestra en el inicio de la tienda. Déjalo vacío para ocultar la sección.')
                    ->rows(6),
                Toggle::make('is_active')
                    ->label('Tienda activa'),
            ]);
    }

    /** Los interruptores no son columnas: un método se ofrece cuando su costo no es null. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (self::SHIPPING_TOGGLES as $toggle => $cost) {
            $data[$toggle] = ($data[$cost] ?? null) !== null;
        }

        return $data;
    }

    /** Interruptor apagado = costo null (método no ofrecido). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (self::SHIPPING_TOGGLES as $toggle => $cost) {
            if (! ($data[$toggle] ?? false)) {
                $data[$cost] = null;
            }

            unset($data[$toggle]);
        }

        return $data;
    }

    private function shippingCostInput(string $name, string $toggle): TextInput
    {
        return TextInput::make($name)
            ->helperText('Con 0 se ofrece gratis.')
            ->visible(fn (Get $get): bool => (bool) $get($toggle))
            ->required()
            ->numeric()
            ->minValue(0)
            ->maxValue(99999999.99)
            ->rule('decimal:0,2')
            ->validationMessages([
                'required' => 'Escribe el costo (0 = gratis) o apaga el interruptor.',
                'numeric' => 'El costo debe ser un número.',
                'min' => 'El costo debe ser de 0 en adelante.',
                'decimal' => 'El costo puede tener hasta 2 decimales.',
            ])
            ->prefix('$');
    }
}
