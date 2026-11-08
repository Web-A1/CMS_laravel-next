<div x-show="changed(@js($field))" x-cloak data-ui="field-saving.hint">
    <x-filament::button type="submit" size="sm" color="gray" x-bind:disabled="fullBusy || stopped || leaving">Сохранить</x-filament::button>
</div>
