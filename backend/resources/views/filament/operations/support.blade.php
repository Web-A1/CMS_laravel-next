<x-filament-panels::page>
    @php($health = $this->supportHealth())
    @php($problems = $this->mailProblems())
    <x-filament::section heading="Почта поддержки" compact collapsible
        :collapsed="! $health['error_code'] && ! $health['delivery_problems'] && ! $health['quarantine'] && $problems->isEmpty()"
        :description="'Отправка писем: '.$health['smtp_label'].' · Приём писем: '.$health['imap_label']"
        data-ui="admin.support.health">
        <p>Последняя успешная проверка почты: {{ $health['last_success'] ?? 'ещё не выполнялась' }}</p>
        <p>Писем в очереди: {{ $health['backlog'] }} · Проблемы с отправкой: {{ $health['delivery_problems'] }} · Письма, требующие проверки: {{ $health['quarantine'] }}</p>
        @if($health['error_code'])<p>{{ $health['error_code'] }}</p>@endif
        @foreach($problems as $receipt)
            <p>#{{ $receipt->id }} · UID {{ $receipt->uidvalidity }}/{{ $receipt->uid }} · {{ $receipt->reason ?? 'Ожидает проверки перед приёмом' }}
                @if($receipt->original_key && ! $receipt->media_deletion_id)
                    <a href="/backend/api/v1/admin/support-mail-receipts/{{ $receipt->id }}/original">Скачать оригинал письма</a>
                @endif
            </p>
        @endforeach
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
