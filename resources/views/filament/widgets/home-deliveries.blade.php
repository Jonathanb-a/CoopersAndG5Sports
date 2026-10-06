<x-filament-widgets::widget class="fi-wi-table">
    <x-filament::tabs label="Entregas a domicilio" class="mb-4">
        @foreach ($this->getTabs() as $key => $label)
            <x-filament::tabs.item
                :active="$activeTab === $key"
                wire:click="$set('activeTab', '{{ $key }}')"
            >
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-widgets::widget>
