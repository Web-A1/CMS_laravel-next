<x-filament-panels::page>
    @if ($operationError)
        <x-filament::callout color="danger" icon="heroicon-o-exclamation-circle" role="alert" data-ui="admin.reviews.error">
            <x-slot name="heading">Действие не завершено</x-slot>
            <x-slot name="description">{{ $operationError }} Введённый текст остаётся в форме. Загрузка актуальной версии заменит несохранённые изменения.</x-slot>
        </x-filament::callout>
    @endif

    @if (in_array($review['dedupeStatus'] ?? '', ['candidate', 'duplicate'], true))
        <x-filament::callout color="warning" data-ui="admin.reviews.duplicate">
            <x-slot name="heading">Публикация недоступна</x-slot>
            <x-slot name="description">Найдено совпадение с другим отзывом. Сначала требуется проверка дубля.</x-slot>
        </x-filament::callout>
    @endif

    @if ($this->editableOnLoad)
        @php($imagePreparation = $this->imagePreparation())
        <div @if(collect($imagePreparation)->contains(fn ($image) => in_array($image['status'], ['pending', 'processing']))) wire:poll.2s="refreshImagePreparation" @endif>
        @foreach ($imagePreparation as $image)
            <x-filament::callout :color="$image['status'] === 'failed' ? 'danger' : 'info'" role="status" data-ui="admin.reviews.image-preparation">
                <x-slot name="heading">{{ $image['label'] }}</x-slot>
                <x-slot name="description">{{ $image['error'] ?? 'Изображение готовится. Дождитесь завершения перед сохранением отзыва.' }}</x-slot>
            </x-filament::callout>
        @endforeach
        @if ($preparedImagePreviews = $this->preparedImagePreviews())
            <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1rem" data-ui="admin.reviews.prepared-previews">
                @foreach ($preparedImagePreviews as $preview)
                    <a href="{{ $preview['fullUrl'] }}" target="_blank" rel="noopener" aria-label="{{ $preview['label'] }}: открыть подготовленное изображение">
                        <img src="{{ $preview['url'] }}" alt="{{ $preview['label'] }}" width="96" height="96" style="width:96px;height:96px;object-fit:contain" />
                    </a>
                @endforeach
            </div>
        @endif
        <form wire:submit="save" wire:key="review-saving-{{ $savingOpening }}" wire:ignore.self
            x-data="adminFieldSaving($wire, @js($this->savingConfig()))"
            x-on:submit.capture="submit($event)"
            x-on:review-reload.window="requestLeave(() => $wire.reloadReview())"
            data-ui="admin.reviews.form">
            {{ $this->form }}
            <div style="margin-top: 1.5rem">
                <x-filament::button type="submit" wire:target="save" data-ui="admin.reviews.save">
                    {{ $review ? 'Сохранить изменения' : 'Создать черновик' }}
                </x-filament::button>
            </div>
        </form>
        </div>
    @else
        <div data-ui="admin.reviews.readonly">{{ $this->readOnlyContent }}</div>
        @if (\App\Support\Rbac::hasPermission(auth('web')->user(), 'reviews.write'))
            <form wire:submit="saveHomePlacement" data-ui="admin.reviews.home-placement">
                {{ $this->homePlacement }}
                <div style="margin-top: 1.5rem">
                    <x-filament::button type="submit" wire:target="saveHomePlacement">Сохранить показ на главной</x-filament::button>
                </div>
            </form>
        @endif
    @endif

    {{ $this->supportingContent }}

</x-filament-panels::page>
