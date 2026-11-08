@if ($deleteTarget !== null)
    <x-filament::section heading="Удаление тестового аккаунта">
        <form wire:submit="deleteTestAccount" style="display:grid;gap:1rem">
            <p>Аккаунт № {{ $deleteTarget }} будет удалён вместе с тестовыми данными. Аккаунты с защищёнными данными удалить нельзя.</p>
            <label for="delete-confirmation">Для подтверждения введите УДАЛИТЬ</label>
            <x-filament::input.wrapper><x-filament::input id="delete-confirmation" wire:model="deleteConfirmation" /></x-filament::input.wrapper>
            @error('deleteConfirmation') <p role="alert">{{ $message }}</p> @enderror
            <x-filament::actions>
                <x-filament::button type="submit" color="danger">Удалить аккаунт</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="cancelDeletion">Отмена</x-filament::button>
            </x-filament::actions>
        </form>
    </x-filament::section>
@endif
