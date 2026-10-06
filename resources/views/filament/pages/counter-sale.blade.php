<x-filament-panels::page>
    @if ($lastNumber)
        <x-filament::section>
            <p class="text-sm">
                Venta registrada:
                <a href="{{ $lastUrl }}" class="font-bold underline">{{ $lastNumber }}</a>
                · Total {{ $lastTotal }}
            </p>
        </x-filament::section>
    @endif

    <form wire:submit="register" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" size="lg" icon="heroicon-o-check">
            Registrar venta
        </x-filament::button>
    </form>
</x-filament-panels::page>
