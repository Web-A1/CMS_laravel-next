<div style="display: grid; gap: .75rem;">
    <p>Выбрано профилей: {{ count($preview['ids'] ?? []) }}.</p>
    <ul style="max-height: 12rem; overflow-y: auto;">
        @foreach ($preview['users'] ?? [] as $user)
            <li>ID {{ $user['id'] }} — {{ $user['name'] ?: 'Без имени' }} · {{ $user['email'] }}</li>
        @endforeach
    </ul>
    <p>Профили и принадлежащие им данные будут удалены без возможности восстановления:
        заказы, доступы, учебная история, отзывы и другие личные связи. Журналы действий сохранятся.</p>
    <p>Связанных записей: {{ array_sum($preview['counts'] ?? []) - ($preview['counts']['users'] ?? 0) }}.</p>
    <p>Очистка связанных файлов продолжится в фоне после удаления. Общие файлы и учебные материалы сохранятся.</p>
</div>
