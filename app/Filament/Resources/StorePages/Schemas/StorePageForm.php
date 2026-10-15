<?php

namespace App\Filament\Resources\StorePages\Schemas;

use App\Filament\Resources\StorePages\StorePageResource;
use App\Models\StorePage;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class StorePageForm
{
    public static function configure(Schema $schema): Schema
    {
        $options = [];

        foreach (StorePage::PAGES as $slug => $title) {
            $options[$slug] = "{$title} (/pagina/{$slug})";
        }

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('slug')
                            ->label('Página')
                            ->options($options)
                            ->required()
                            ->native(false)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule) => $rule->where('store_id', Filament::getTenant()?->getKey()),
                            )
                            ->validationMessages(['unique' => 'Esta tienda ya tiene esa página.']),
                        TextInput::make('title')
                            ->label('Título')
                            ->required()
                            ->maxLength(120),
                        Textarea::make('internal_note')
                            ->label('Nota interna')
                            ->helperText('Solo la ve el personal; nunca se muestra en la tienda.')
                            ->maxLength(255)
                            ->rows(2)
                            ->columnSpanFull(),
                        RichEditor::make('body')
                            ->label('Contenido')
                            ->helperText('Los textos entre [CORCHETES] están pendientes de completar con el dueño.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['h2', 'h3'],
                                ['bulletList', 'orderedList', 'blockquote'],
                                ['undo', 'redo'],
                            ])
                            ->columnSpanFull(),
                        Toggle::make('is_published')
                            ->label('Publicada')
                            ->helperText('Solo las páginas publicadas se ven en la tienda y en su pie de página.')
                            ->visible(fn (): bool => StorePageResource::canPublish()),
                    ]),
            ]);
    }
}
