@if(is_array($snapshot))
    <dl style="display:grid;gap:8px">
        @foreach($snapshot as $key => $value)
            <div>@if(!is_int($key) && !in_array($key, ['text', 'expectedAnswer', 'referenceAnswers'], true))<dt style="font-weight:500">{{ $key }}</dt>@endif
                <dd>@include('filament.pages.assessment-review-snapshot', ['snapshot' => $value])</dd>
            </div>
        @endforeach
    </dl>
@elseif(is_bool($snapshot))<p>{{ $snapshot ? 'Да' : 'Нет' }}</p>
@else<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $snapshot ?? '—' }}</p>@endif
