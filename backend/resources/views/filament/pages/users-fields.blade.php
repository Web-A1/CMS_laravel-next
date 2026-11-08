<dl style="display:grid;gap:.5rem">
    @foreach ((array) $fields as $field => $value)
        <div style="border-left:1px solid #d1d5db;padding-left:.75rem"><dt style="font-weight:500">{{ \App\Support\Admin\UsersDisplay::label((string) $field) }}</dt><dd style="overflow-wrap:anywhere;white-space:pre-wrap">@if (is_array($value) || is_object($value)) @include('filament.pages.users-fields', ['fields' => (array) $value]) @elseif (is_bool($value)){{ $value ? 'Да' : 'Нет' }}@else{{ $value === null || $value === '' ? '—' : $value }}@endif</dd></div>
    @endforeach
</dl>
