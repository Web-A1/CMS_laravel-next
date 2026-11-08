@if (filled($previousSearch))
    <div data-ui="admin.previous-search">
        <x-filament::badge color="gray">
            Поиск: «{{ $previousSearch }}»
            <x-slot name="deleteButton" wire:click="clearPreviousSearch" label="Сбросить общий поиск"></x-slot>
        </x-filament::badge>
    </div>
@endif
