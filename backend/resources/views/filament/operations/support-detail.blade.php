<x-filament-panels::page>
    <div data-ui="admin.support.detail" class="support-detail" wire:key="support-saving-{{ $savingOpening }}" wire:ignore.self
        x-data="adminFieldSaving($wire, @js($this->savingConfig()))" x-on:submit.capture="submit($event)"
        x-on:support-refresh.window="requestLeave(() => $wire.discardAndRefreshHistory())">
        @include('filament.field-saving.status')
        @php($statusLabel = \App\Services\Support\SupportOperations::STATUSES[$ticket->status] ?? $ticket->status)
        @php($attachmentCount = $ticket->messages->sum(fn ($message) => $message->attachments->count()))
        <x-filament::section compact>
            <div class="support-summary">
                <div class="support-summary-main">
                    <p class="support-detail-label">Тема обращения</p>
                    <h2 data-ui="admin.support.subject">{{ $ticket->subject }}</h2>
                </div>
                <dl class="support-summary-meta">
                    <div><dt>Номер</dt><dd>{{ $ticket->public_number ?? '#'.$ticket->id }}</dd></div>
                    <div><dt>Email для ответа</dt><dd>{{ $ticket->contact_email ?? 'Не указан' }}</dd></div>
                    <div><dt>Статус</dt><dd><x-filament::badge :color="match ($ticket->status) { 'new' => 'warning', 'in_progress' => 'info', 'closed' => 'success', default => 'gray' }">{{ $statusLabel }}</x-filament::badge></dd></div>
                    @if(($cleanups ?? collect())->isNotEmpty())
                        @php($latestCleanup = $cleanups->last())
                        <div data-ui="admin.support.cleanup"><dt>Последняя очистка медиа</dt><dd>
                            <x-filament::badge :color="$latestCleanup->status === 'completed' ? 'success' : 'warning'">{{ match ($latestCleanup->status) { 'completed' => 'Завершена', 'pending' => 'В процессе', default => 'Не подтверждена' } }}</x-filament::badge>
                            @if($cleanups->whereIn('status', ['failed', 'blocked'])->isNotEmpty())<span>Есть незавершённое удаление. Подробности в истории.</span>@endif
                        </dd></div>
                    @endif
                </dl>
            </div>
        </x-filament::section>
        <div class="support-detail-layout">
            <div class="support-detail-conversation">
                <div class="support-thread-header">
                    <div>
                        <h2>Переписка</h2>
                        <p>Сообщений: {{ $ticket->messages->count() }} · Вложений: {{ $attachmentCount }}</p>
                    </div>
                    <x-filament::button x-on:click="$dispatch('support-refresh')" color="gray" size="sm">Обновить переписку</x-filament::button>
                </div>
                @include('filament.operations.support-history', ['ticket' => $ticket, 'events' => $events])
            </div>
            <aside class="support-detail-tools" aria-label="Работа с обращением">
                @error('operation')
                    <p role="alert">{{ $message }} Черновик ответа сохранён. Обновите историю и повторите действие.</p>
                @enderror
                <x-filament::section heading="Ответить" compact>
                    <form id="support-reply-form" wire:submit="sendReply" data-ui="admin.support.reply">{{ $this->replyForm }}</form>
                </x-filament::section>
                <x-filament::section heading="Статус и контакт" compact>
                    <form id="support-details-form" wire:submit="saveDetails" data-ui="admin.support.details-form">{{ $this->detailsForm }}</form>
                </x-filament::section>
            </aside>
        </div>
    </div>
    <style>
        .support-detail { display: grid; gap: 1.25rem; min-width: 0; overflow-wrap: anywhere; }
        .support-summary { display: grid; gap: 1rem; min-width: 0; }
        .support-summary-main { display: grid; gap: .25rem; min-width: 0; }
        .support-detail-label, .support-summary-meta dt, .support-thread-header p, .support-message-meta, .support-delivery { font-size: var(--text-xs); line-height: 1.5; opacity: .75; }
        .support-summary-main h2, .support-thread-header h2 { font-size: var(--text-base); font-weight: 600; line-height: 1.4; }
        .support-summary-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: 1rem; margin: 0; }
        .support-summary-meta > div { min-width: 0; }
        .support-summary-meta dd { margin: .25rem 0 0; overflow-wrap: anywhere; }
        .support-detail-layout { display: grid; gap: 1.25rem; min-width: 0; }
        .support-detail-conversation, .support-detail-tools { display: grid; gap: 1rem; min-width: 0; align-content: start; }
        .support-history, .support-message-list { display: grid; gap: 1rem; min-width: 0; }
        .support-thread-header { display: flex; align-items: end; justify-content: space-between; flex-wrap: wrap; gap: .75rem; }
        .support-thread-header > div { display: grid; gap: .125rem; }
        .support-message-content { display: grid; gap: 1rem; min-width: 0; }
        .support-message-header { display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: .5rem 1rem; }
        .support-message-identity { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; min-width: 0; }
        .support-message-body { white-space: pre-wrap; line-height: 1.55; max-width: 70ch; overflow-wrap: anywhere; }
        .support-attachments { display: grid; gap: .75rem; min-width: 0; }
        .support-attachments h3 { font-size: var(--text-sm); font-weight: 600; }
        .support-attachment-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: .75rem; }
        .support-attachment-link { display: grid; gap: .5rem; text-decoration: none; }
        .support-attachment-link img { display: block; width: 100%; height: 8rem; object-fit: contain; }
        .support-attachment-link span { text-decoration: underline; }
        .support-delivery { display: grid; gap: .25rem; }
        .support-event-list { display: grid; gap: .5rem; list-style: none; padding: 0; margin: 0; }
        .support-event-list li { display: grid; grid-template-columns: 8.5rem minmax(0, 1fr); gap: .5rem; }
        @media (max-width: 639px) {
            .support-event-list li { grid-template-columns: minmax(0, 1fr); gap: .125rem; }
        }
        @media (min-width: 1200px) {
            .support-detail-layout { grid-template-columns: minmax(0, 1fr) minmax(20rem, 22rem); align-items: start; }
            .support-detail-tools { position: sticky; top: 1rem; }
        }
    </style>
</x-filament-panels::page>
