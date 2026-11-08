<x-filament-panels::page>
    <form wire:submit="save" wire:key="staff-saving-{{ $savingOpening }}" wire:ignore.self
        x-data="adminFieldSaving($wire, @js($this->savingConfig()))" x-on:submit.capture="submit($event)"
        style="display:grid;gap:1.5rem;max-width:40rem" data-ui="admin.profile.form">
        {{ $this->form }}
        <fieldset x-bind:disabled="!ordinaryDirty()" style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem;border:0;margin:0;padding:0;min-width:0">
            @if ($this->canUpdate())
                <x-filament::button type="submit" wire:target="save" data-ui="admin.profile.save">Сохранить</x-filament::button>
            @else
                <x-filament::button type="submit" disabled data-ui="admin.profile.save">Сохранить</x-filament::button>
            @endif
            <span x-show="ordinaryDirty()" x-cloak role="status">Есть несохранённые изменения</span>
        </fieldset>
        @include('filament.field-saving.status')
        @if (! $this->canUpdate())
            <p>Имя доступно только для просмотра.</p>
        @endif
    </form>
    <dl style="display:grid;gap:1rem;overflow-wrap:anywhere" data-ui="admin.profile.identity">
        <div><dt>Email</dt><dd>{{ $email ?: 'Не указан' }}</dd></div>
        <div><dt>Роль</dt><dd>{{ $roleLabel }}</dd></div>
    </dl>
</x-filament-panels::page>
