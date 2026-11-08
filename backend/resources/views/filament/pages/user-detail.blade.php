<x-filament-panels::page>
    <div wire:key="user-saving-{{ $savingOpening }}" wire:ignore.self style="display: contents"
        x-data="adminFieldSaving($wire, @js($this->savingConfig()))"
        x-on:submit.capture="submit($event)"
        x-on:user-saving-tab.window="requestLeave(() => $wire.openSavingTab($event.detail.tab))"
        x-on:user-saving-reload.window="requestLeave(() => $wire.reloadEditor())">
    <form id="user-ordinary" wire:submit="saveOrdinary" hidden></form>
    <form id="user-new-comment" wire:submit="newComment" hidden></form>
    @include('filament.field-saving.status')
    @if ($errors->any())
        <div role="alert">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
    @endif

    <x-filament::section :heading="$detail['name'] ?: 'Пользователь № '.$userId" compact>
        {{ $summary }}
        @if ($canImpersonate || $canDelete)
            <x-slot name="footer">
                <x-filament::actions>
                    @if ($canImpersonate)<x-filament::button color="gray" wire:click="impersonate({{ $userId }})">Открыть ЛК пользователя</x-filament::button>@endif
                    @if ($canDelete)<x-filament::button color="danger" wire:click="prepareDeletion({{ $userId }})">Удалить тестовый аккаунт</x-filament::button>@endif
                </x-filament::actions>
            </x-slot>
        @endif
    </x-filament::section>

    <x-filament::tabs label="Разделы карточки">
        @foreach ($tabs as $key => $label)
            @php($tabHandler = '$dispatch(\'user-saving-tab\', { tab: '.\Illuminate\Support\Js::from($key)->toHtml().' })')
            <x-filament::tabs.item :active="$tab === $key" x-on:click="{{ $tabHandler }}">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @if ($tab === 'profile')
        <x-filament::section heading="Анкета">
            <form id="user-profile" wire:submit="saveProfile">{{ $this->form }}</form>
            @if ($canEdit)
                <x-slot name="footer">
                    <x-filament::actions>
                        <x-filament::button type="submit" form-id="user-profile" form="saveProfile">Сохранить анкету</x-filament::button>
                        <x-filament::button color="gray" x-on:click="$dispatch('user-saving-reload')">Обновить данные</x-filament::button>
                    </x-filament::actions>
                </x-slot>
            @endif
        </x-filament::section>
        @if ($canAssign || $canEdit){{ $management }}@endif
        <x-filament::section heading="Согласия при регистрации" compact>{{ $consents }}</x-filament::section>
    @elseif ($tab === 'legacy')
        <x-filament::section heading="Данные старого сайта">
            {{ $legacyInfo }}
            <x-slot name="footer"><x-filament::link :href="'/admin/legacy-users/'.$detail['legacy_profile']['source_member_id']">Открыть импортированную историю № {{ $detail['legacy_profile']['source_member_id'] }}</x-filament::link></x-slot>
        </x-filament::section>
    @elseif ($tab === 'orders')
        <x-filament::section heading="Заказы">
            @if ($orders['data']){{ $ordersInfo }}@else<p>Заказов нет.</p>@endif
            <x-slot name="footer">@include('filament.pages.users-pagination', ['listing' => $orders, 'property' => 'ordersPage'])</x-slot>
        </x-filament::section>
    @elseif ($tab === 'access')
        <x-filament::section heading="Доступы к урокам">
            @if ($access['data']){{ $accessInfo }}@else<p>Доступов нет.</p>@endif
            <x-slot name="footer">@include('filament.pages.users-pagination', ['listing' => $access, 'property' => 'accessPage'])</x-slot>
        </x-filament::section>
        <x-filament::section heading="Покрытие курсов">
            @if ($courses['data']){{ $coursesInfo }}@else<p>Курсы не найдены.</p>@endif
            <x-slot name="footer">@include('filament.pages.users-pagination', ['listing' => $courses, 'property' => 'coursesPage'])</x-slot>
        </x-filament::section>
    @elseif ($tab === 'events')
        <x-filament::section heading="История изменений">
            @if ($events['data']){{ $eventsInfo }}@else<p>Событий нет.</p>@endif
            <x-slot name="footer">@include('filament.pages.users-pagination', ['listing' => $events, 'property' => 'eventsPage'])</x-slot>
        </x-filament::section>
    @endif

    @if ($canEdit)
        <x-filament::section heading="Комментарий" compact>
            <form id="user-comment" wire:submit="addComment">{{ $this->commentForm }}</form>
            <x-slot name="footer">
                <x-filament::actions>
                    <x-filament::button type="submit" form-id="user-comment" form="addComment">Добавить комментарий</x-filament::button>
                    <x-filament::button type="submit" color="gray" form-id="user-new-comment" form="newComment">Новый комментарий</x-filament::button>
                </x-filament::actions>
            </x-slot>
        </x-filament::section>
    @endif
    @include('filament.pages.users-delete')
    </div>
</x-filament-panels::page>
