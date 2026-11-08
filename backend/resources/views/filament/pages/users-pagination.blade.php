@if ($listing['meta']['last_page'] > 1)
    <nav aria-label="Страницы списка">
        <x-filament::actions>
        <x-filament::button color="gray" wire:click="$set('{{ $property }}', {{ max(1, $listing['meta']['current_page'] - 1) }})" :disabled="$listing['meta']['current_page'] <= 1">Назад</x-filament::button>
        <span>{{ $listing['meta']['current_page'] }} из {{ $listing['meta']['last_page'] }} · Всего {{ $listing['meta']['total'] }}</span>
        <x-filament::button color="gray" wire:click="$set('{{ $property }}', {{ $listing['meta']['current_page'] + 1 }})" :disabled="$listing['meta']['current_page'] >= $listing['meta']['last_page']">Далее</x-filament::button>
        </x-filament::actions>
    </nav>
@endif
