<x-filament-panels::page>
    @php($this->getTableRecords())
    @if ($this->getErrorBag()->any())
        <div role="alert">@foreach ($this->getErrorBag()->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
