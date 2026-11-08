<div data-ui="admin.support.history" class="support-history">
    @php
        $deliveryLabels = ['pending' => 'Ожидает отправки', 'sending' => 'Отправляется', 'sent' => 'Принято почтовым сервером', 'failed' => 'Не удалось отправить', 'delivery_unknown' => 'Результат отправки неизвестен'];
        $eventLabels = ['create' => 'Создано обращение', 'support_update' => 'Изменены данные обращения', 'support_reply' => 'Ответ подготовлен к отправке', 'support_retry' => 'Повторная отправка поставлена в очередь', 'inbound_reopen' => 'Получено новое сообщение пользователя', 'support_sent' => 'Почтовый сервер принял ответ', 'support_close' => 'Тикет закрыт, запрошено удаление медиа', 'support_media_deleted' => 'Удаление медиа подтверждено'];
        $statusLabels = \App\Services\Support\SupportOperations::STATUSES;
        $cleanupLabels = ['pending' => 'Медиа удаляются', 'completed' => 'Медиа удалены', 'failed' => 'Удаление медиа не подтверждено', 'blocked' => 'Удаление медиа не подтверждено'];
    @endphp

    <div data-ui="admin.support.messages" class="support-message-list">
        @forelse($ticket->messages as $message)
            <x-filament::section compact>
                <article data-ui="admin.support.message" class="support-message-content">
                    <header class="support-message-header">
                        <div class="support-message-identity">
                            <x-filament::badge :color="match ($message->direction) { 'inbound' => 'gray', 'outbound' => 'primary', default => 'warning' }">
                                {{ match ($message->direction) { 'inbound' => 'Сообщение пользователя', 'outbound' => 'Ответ поддержки', 'notice', 'system' => 'Служебное сообщение', default => 'Сообщение' } }}
                            </x-filament::badge>
                            <span class="support-message-meta">{{ $message->sender ?? 'Оператор' }}</span>
                        </div>
                        <time class="support-message-meta" datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d.m.Y H:i') }}</time>
                    </header>

                    @if(trim($message->body ?? '') !== '')
                        <div class="support-message-body">{{ $message->body }}</div>
                    @else
                        <p class="support-message-meta">Текст не указан.</p>
                    @endif

                    @if($message->attachments->isNotEmpty())
                        <section class="support-attachments" aria-label="Вложения к сообщению">
                            <h3>Вложения · {{ $message->attachments->count() }}</h3>
                            <div class="support-attachment-grid">
                                @foreach($message->attachments as $attachment)
                                    <x-filament::section compact>
                                        @if($attachment->media_deletion_id)
                                            @php($cleanup = ($cleanups ?? collect())->get($attachment->media_deletion_id))
                                            <p>{{ $cleanupLabels[$cleanup?->status ?? 'pending'] }}</p>
                                        @elseif($attachment->status === 'ready' && in_array($attachment->mime, ['audio/webm', 'audio/mp4'], true))
                                            <p>Голосовое обращение</p>
                                            <audio controls preload="metadata" aria-label="Голосовое обращение" src="/backend/api/v1/admin/support-tickets/{{ $ticket->id }}/attachments/{{ $attachment->id }}"></audio>
                                        @elseif($attachment->status === 'ready')
                                            <a class="support-attachment-link" href="/backend/api/v1/admin/support-tickets/{{ $ticket->id }}/attachments/{{ $attachment->id }}" target="_blank" rel="noopener" aria-label="Открыть скриншот {{ $loop->iteration }} в новой вкладке">
                                                <img src="/backend/api/v1/admin/support-tickets/{{ $ticket->id }}/attachments/{{ $attachment->id }}?thumbnail=1" alt="" loading="lazy">
                                                <span>Скриншот {{ $loop->iteration }}</span>
                                            </a>
                                        @else
                                            <p>Скриншот {{ $loop->iteration }}: {{ $attachment->status === 'pending' ? 'Обрабатывается' : 'Не удалось обработать' }}@if($attachment->error_code) · {{ $attachment->error_code }}@endif</p>
                                        @endif
                                    </x-filament::section>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($message->delivery_status)
                        <div class="support-delivery">
                            <p>Доставка: <x-filament::badge :color="match ($message->delivery_status) { 'sent' => 'success', 'failed', 'delivery_unknown' => 'danger', default => 'warning' }">{{ $deliveryLabels[$message->delivery_status] ?? 'Состояние уточняется' }}</x-filament::badge> · Попыток: {{ $message->attempts }}@if($message->error_code) · {{ $message->error_code }}@endif</p>
                            @if($message->delivery_status === 'sent')<p>SMTP принял письмо. Это не подтверждение прочтения.</p>@endif
                        </div>
                    @endif
                    @foreach($message->warnings ?? [] as $warning)<p>{{ $warning['message'] ?? 'Сообщение требует проверки.' }}</p>@endforeach
                </article>
            </x-filament::section>
        @empty
            <x-filament::section compact><p>Сообщений пока нет.</p></x-filament::section>
        @endforelse
    </div>

    <x-filament::section heading="История изменений" compact collapsible collapsed>
        @if($events->isEmpty())
            <p>Изменений пока нет.</p>
        @else
            <ol class="support-event-list">
                @foreach($events as $event)
                    <li>
                        <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('d.m.Y H:i') }}</time>
                        <span>{{ $eventLabels[$event->operation] ?? 'Действие поддержки' }}@if(isset($event->payload['from'], $event->payload['to']) && $event->payload['from'] !== $event->payload['to']) · {{ $statusLabels[$event->payload['from']] ?? 'Статус уточняется' }} → {{ $statusLabels[$event->payload['to']] ?? 'Статус уточняется' }}@endif</span>
                        @if($event->actor_id)<span>Сотрудник #{{ $event->actor_id }}</span>@endif
                        @if($event->operation === 'support_close' && isset($event->payload['deletion_id']))
                            @php($cleanup = ($cleanups ?? collect())->get($event->payload['deletion_id']))
                            <span>{{ $cleanupLabels[$cleanup?->status ?? 'pending'] }}@if($cleanup?->error_code) · {{ $cleanup->error_code }}@endif</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</div>
