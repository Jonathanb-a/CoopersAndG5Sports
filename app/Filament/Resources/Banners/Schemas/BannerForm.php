<?php

namespace App\Filament\Resources\Banners\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('image_path')
                    ->label('Imagen')
                    ->helperText('Recomendada: horizontal, de al menos 1600 × 700 px.')
                    ->image()
                    ->disk('public')
                    ->directory('banners')
                    ->visibility('public')
                    ->maxSize(4096)
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->label('Título')
                    ->helperText('Opcional. Se muestra sobre la imagen y como texto alternativo.')
                    ->maxLength(255),
                TextInput::make('link_url')
                    ->label('Enlace')
                    ->helperText('Opcional. Una dirección completa (https://…) o una ruta de la tienda (por ejemplo /categorias/calzado).')
                    ->maxLength(255)
                    ->rule('regex:/^(https?:\/\/|\/)/')
                    ->validationMessages(['regex' => 'El enlace debe empezar con https://, http:// o /.']),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
            ]);
    }
}
