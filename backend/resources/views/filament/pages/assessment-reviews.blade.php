<x-filament-panels::page>
    @include('filament.tables.previous-search', ['previousSearch' => $search])
    <x-filament::section>
        <div style="overflow-x:auto;max-width:100%"><table style="width:100%;font-size:0.875rem" data-ui="admin.assessmentReviews.queue">
            <thead><tr>@include('filament.tables.plain-header', ['tableKey' => 'queue'])<th></th></tr></thead>
            <tbody>@forelse($rows as $row)
                <tr><td style="padding:12px;vertical-align:top">{{ $row['student']['name'] }}<br><span style="opacity:0.75">{{ $row['student']['email'] }}</span></td><td style="padding:12px;vertical-align:top">{{ $row['context']['course']['title'] }}<br>{{ $row['context']['lesson']['title'] }}</td><td style="padding:12px;vertical-align:top">{{ $row['reviewedCount'] }} / {{ $row['manualQuestionCount'] }}</td><td style="padding:12px;vertical-align:top">{{ $row['finalizedAt'] ? 'Проверена' : 'Ожидает проверки' }}</td><td style="padding:12px;vertical-align:top"><x-filament::link :href="\App\Filament\Pages\AssessmentReviewDetail::getUrl(['attempt' => $row['id']])">Открыть</x-filament::link></td></tr>
            @empty<tr><td colspan="5" style="padding:12px;vertical-align:top">Работ по выбранным условиям нет.</td></tr>@endforelse</tbody>
        </table></div>
        <div style="margin-top:16px;display:flex;align-items:center;gap:12px">
            @if($currentPage > 1)<x-filament::button color="gray" wire:click="goToPage({{ $currentPage - 1 }})">Назад</x-filament::button>@endif
            <span>Страница {{ $currentPage }} из {{ $lastPage }} · Всего {{ $total }}</span>
            @if($currentPage < $lastPage)<x-filament::button color="gray" wire:click="goToPage({{ $currentPage + 1 }})">Далее</x-filament::button>@endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
