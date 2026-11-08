<x-filament-panels::page>
    <div wire:key="assessment-saving-{{ $savingOpening }}" wire:ignore.self style="display: contents"
        x-data="adminFieldSaving($wire, @js($this->savingConfig()))" x-on:submit.capture="submit($event)"
        x-on:assessment-refresh.window="requestLeave(() => $wire.loadEditor())">
    <form id="assessment-ordinary" wire:submit="saveOrdinary" hidden></form>
    @include('filament.field-saving.status')
    <x-filament::section :heading="$detail['attempt']['student']['name']">
        <p>{{ $detail['attempt']['context']['subject']['title'] }} / {{ $detail['attempt']['context']['course']['title'] }} / {{ $detail['attempt']['context']['lesson']['title'] }}</p>
        <p>Проверено {{ $detail['attempt']['reviewedCount'] }} из {{ $detail['attempt']['manualQuestionCount'] }} · {{ $detail['attempt']['status'] }}</p>
        @if($detail['finalScore'])<p>Итог: {{ number_format(($detail['finalScore']['overall']['percentBps'] ?? 0) / 100, 2, ',', ' ') }}%</p>@endif
        @if($detail['failureReason'])<p>{{ $detail['failureReason'] }}</p>@endif
    </x-filament::section>
    @if($errors->any())<div role="alert" style="color:var(--danger-600,#dc2626)">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @foreach($detail['items'] as $item)
        @php($editable = $canReview && !$detail['attempt']['finalizedAt'] && $detail['attempt']['status'] === 'pending_review')
        @php($itemDirty = 'changed('.\Illuminate\Support\Js::from('drafts.'.$item['id'].'.scorePercent')->toHtml().') || changed('.\Illuminate\Support\Js::from('drafts.'.$item['id'].'.comment')->toHtml().')')
        <x-filament::section :heading="$item['question']['prompt']" wire:key="review-{{ $item['id'] }}" data-ui="admin.assessmentReviews.detail.item">
            <h3 style="font-weight:600">Ответ студента</h3>
            @include('filament.pages.assessment-review-snapshot', ['snapshot' => $item['responseSnapshot']])
            <details style="margin-top:16px;margin-bottom:16px"><summary>Критерии и эталон ответа</summary>@include('filament.pages.assessment-review-snapshot', ['snapshot' => $item['rubricSnapshot']])</details>
            @if($editable)
                <form wire:submit="saveItem({{ $item['id'] }})" style="display:grid;gap:16px"
                    x-show="!$wire.submissions[{{ $item['id'] }}].saved || {{ $itemDirty }}">
                    <label style="display:block">Оценка, %
                        <x-filament::input.wrapper><x-filament::input type="number" min="0" max="100" step="0.01" wire:model="drafts.{{ $item['id'] }}.scorePercent" required /></x-filament::input.wrapper>
                    </label>
                    @include('filament.field-saving.hint', ['field' => 'drafts.'.$item['id'].'.scorePercent'])
                    <label style="display:block">Комментарий
                        <textarea wire:model="drafts.{{ $item['id'] }}.comment" maxlength="2000" rows="3" style="width:100%;border-radius:8px;border:1px solid var(--gray-400,#9ca3af);padding:12px;vertical-align:top;color:inherit;background:transparent"></textarea>
                    </label>
                    @include('filament.field-saving.hint', ['field' => 'drafts.'.$item['id'].'.comment'])
                    <x-filament::button type="submit" wire:loading.attr="disabled">Сохранить оценку</x-filament::button>
                </form>
                <div x-show="$wire.submissions[{{ $item['id'] }}].saved && !({{ $itemDirty }})" x-cloak>
                    <p style="margin-top:16px">Оценка: {{ $item['scoreBps'] === null ? '—' : number_format($item['scoreBps'] / 100, 2, ',', ' ').'%' }} · {{ $item['reviewer']['name'] ?? '' }}</p>
                    @if($item['comment'])<p style="white-space:pre-wrap">{{ $item['comment'] }}</p>@endif
                    <x-filament::button color="gray" wire:click="editItem({{ $item['id'] }})">Изменить оценку</x-filament::button>
                </div>
            @else
                <p style="margin-top:16px">Оценка: {{ $item['scoreBps'] === null ? '—' : number_format($item['scoreBps'] / 100, 2, ',', ' ').'%' }} · {{ $item['reviewer']['name'] ?? '' }}</p>
                @if($item['comment'])<p style="white-space:pre-wrap">{{ $item['comment'] }}</p>@endif
                @if($editable)<x-filament::button color="gray" wire:click="editItem({{ $item['id'] }})">Изменить оценку</x-filament::button>@endif
            @endif
        </x-filament::section>
    @endforeach
    <form wire:submit="finalize" style="display:flex;flex-wrap:wrap;gap:12px">
        @if($canReview && !$detail['attempt']['finalizedAt'] && $detail['attempt']['status'] === 'pending_review')<x-filament::button type="submit" wire:loading.attr="disabled" :disabled="$detail['attempt']['pendingCount'] > 0">Завершить проверку</x-filament::button>@endif
        <x-filament::button type="button" color="gray" x-on:click="$dispatch('assessment-refresh')">Обновить данные</x-filament::button>
    </form>
    </div>
</x-filament-panels::page>
