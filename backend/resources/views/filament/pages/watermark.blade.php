<x-filament-panels::page>
    <x-filament::section><form wire:submit="lookup" style="display:grid;gap:1rem">{{ $this->form }}<x-filament::button type="submit">Найти пользователя</x-filament::button></form></x-filament::section>
    @if ($searched)
        <x-filament::section heading="Результат поиска">
            @if ($result)<dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,16rem),1fr));gap:.75rem"><div><dt>Пользователь</dt><dd><x-filament::link :href="'/admin/users/'.$result['userId']">{{ $result['name'] }} · № {{ $result['userId'] }}</x-filament::link></dd></div><div><dt>Код</dt><dd>{{ $result['code'] }}</dd></div><div><dt>Статус</dt><dd>{{ $result['status'] }}</dd></div><div><dt>Начало</dt><dd>{{ $result['startedAt'] ?: '—' }}</dd></div><div><dt>Последний просмотр</dt><dd>{{ $result['lastUsedAt'] ?: '—' }}</dd></div><div><dt>Завершение</dt><dd>{{ $result['endedAt'] ?: '—' }}</dd></div></dl>@else<p>По этому коду ничего не найдено.</p>@endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
