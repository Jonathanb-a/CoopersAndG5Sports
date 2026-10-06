<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CounterSaleException;
use App\Services\OrderService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Venta de mostrador de la tienda actual: el personal captura las piezas, cobra y registra. Lo que
 * se ve aquí (precios, subtotales, total) es informativo; OrderService::placeCounterSale() vuelve a
 * leer precios y stock de la base de datos.
 */
class CounterSale extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Venta de mostrador';

    protected static ?string $title = 'Venta de mostrador';

    protected static ?string $slug = 'venta-de-mostrador';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.counter-sale';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Última venta registrada en esta pantalla, para mostrar su número. */
    public ?string $lastNumber = null;

    public ?string $lastUrl = null;

    public ?string $lastTotal = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Productos')
                    ->schema([
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addActionLabel('Agregar producto')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->columns(12)
                            ->validationMessages(['min' => 'Agrega al menos un producto a la venta.'])
                            ->schema([
                                Select::make('product_id')
                                    ->label('Producto')
                                    ->columnSpan(4)
                                    ->required()
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->products()
                                        ->where('name', 'like', "%{$search}%")
                                        ->orderBy('name')
                                        ->limit(20)
                                        ->get()
                                        ->mapWithKeys(fn (Product $product) => [$product->id => self::productLabel($product)])
                                        ->all())
                                    ->getOptionLabelUsing(fn ($value): ?string => ($product = $this->products()->find($value)) ? self::productLabel($product) : null)
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set) => $set('variant_id', null)),
                                Select::make('variant_id')
                                    ->label('Talla')
                                    ->columnSpan(3)
                                    ->required()
                                    ->native(false)
                                    ->placeholder('Elige la talla')
                                    ->options(fn (Get $get): array => $this->sizeOptions($get('product_id')))
                                    ->live(),
                                TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->columnSpan(1)
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->live(onBlur: true),
                                TextEntry::make('unit_price')
                                    ->label('Precio')
                                    ->columnSpan(2)
                                    ->state(fn (Get $get): string => ($price = $this->price($get('product_id'))) === null ? '—' : Number::currency($price)),
                                TextEntry::make('line_total')
                                    ->label('Subtotal')
                                    ->columnSpan(2)
                                    ->weight('bold')
                                    ->state(fn (Get $get): string => ($price = $this->price($get('product_id'))) === null
                                        ? '—'
                                        : Number::currency($price * max(0, (int) $get('quantity')))),
                            ]),
                    ]),
                Section::make('Cobro')
                    ->columns(2)
                    ->schema([
                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->options(PaymentMethod::class)
                            ->default(PaymentMethod::Cash->value)
                            ->required()
                            ->native(false)
                            ->selectablePlaceholder(false),
                        TextInput::make('discount')
                            ->label('Descuento')
                            ->helperText('Opcional. Monto en pesos sobre el total de la venta.')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$')
                            ->live(onBlur: true),
                        Textarea::make('note')
                            ->label('Nota')
                            ->helperText('Obligatoria si hay descuento: explica por qué se dio.')
                            ->columnSpanFull()
                            ->rows(2)
                            ->maxLength(500)
                            ->required(fn (Get $get): bool => (float) $get('discount') > 0)
                            ->validationMessages(['required' => 'Escribe una nota que explique el descuento.']),
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->state(fn (Get $get): string => Number::currency($this->subtotal($get('lines')))),
                        TextEntry::make('total')
                            ->label('Total a cobrar')
                            ->size('lg')
                            ->weight('bold')
                            ->state(fn (Get $get): string => Number::currency(max(0, $this->subtotal($get('lines')) - (float) $get('discount')))),
                    ]),
            ]);
    }

    public function register(): void
    {
        $data = $this->form->getState();

        try {
            $order = app(OrderService::class)->placeCounterSale(
                Filament::getTenant(),
                auth()->user(),
                // Solo talla y cantidad: el precio lo lee el servicio de la base de datos.
                array_map(fn (array $line) => ['variant_id' => $line['variant_id'], 'quantity' => $line['quantity']], array_values($data['lines'] ?? [])),
                $data['payment_method'] instanceof PaymentMethod ? $data['payment_method'] : PaymentMethod::from($data['payment_method']),
                $data['discount'] ?? null,
                $data['note'] ?? null,
            );
        } catch (CounterSaleException $exception) {
            Notification::make()->danger()->title('No se registró la venta')->body($exception->getMessage())->persistent()->send();

            return;
        }

        $this->remember($order);

        Notification::make()->success()->title("Venta registrada: {$order->number}")->body('Total: '.Number::currency((float) $order->total))->send();

        // Formulario limpio para la siguiente venta.
        $this->form->fill();
    }

    private function remember(Order $order): void
    {
        $this->lastNumber = $order->number;
        $this->lastTotal = Number::currency((float) $order->total);
        $this->lastUrl = OrderResource::getUrl('view', ['record' => $order]);
    }

    /** Productos activos de la tienda actual. */
    private function products()
    {
        return Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('store_id', Filament::getTenant()?->getKey())
            ->where('is_active', true);
    }

    private static function productLabel(Product $product): string
    {
        return filled($product->color) ? "{$product->name} · {$product->color}" : $product->name;
    }

    /**
     * Tallas con stock del producto: "M (quedan 3 piezas)".
     *
     * @return array<int, string>
     */
    private function sizeOptions(mixed $productId): array
    {
        if (blank($productId) || ! $this->products()->whereKey($productId)->exists()) {
            return [];
        }

        return ProductVariant::query()
            ->where('product_id', $productId)
            ->where('stock', '>', 0)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant) => [
                $variant->id => "{$variant->size} (".($variant->stock === 1 ? 'queda 1 pieza' : "quedan {$variant->stock} piezas").')',
            ])
            ->all();
    }

    private function price(mixed $productId): ?float
    {
        if (blank($productId)) {
            return null;
        }

        $price = $this->products()->whereKey($productId)->value('price');

        return $price === null ? null : (float) $price;
    }

    private function subtotal(mixed $lines): float
    {
        $subtotal = 0.0;

        foreach (is_array($lines) ? $lines : [] as $line) {
            $subtotal += ($this->price($line['product_id'] ?? null) ?? 0) * max(0, (int) ($line['quantity'] ?? 0));
        }

        return $subtotal;
    }
}
