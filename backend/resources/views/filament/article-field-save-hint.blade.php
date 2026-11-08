<div x-show="changed(@js($field))" x-cloak data-ui="article-editor.field-save">
    <x-filament::button type="submit" size="sm" color="gray" x-bind:disabled="fullBusy || stopped">Сохранить</x-filament::button>
</div>
