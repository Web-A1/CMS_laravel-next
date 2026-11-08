<?php

namespace App\Filament\Pages\Reviews;

use App\Exceptions\AdminReviewException;
use App\Http\Requests\AdminReviewRequest;
use App\Models\Review;
use App\Models\ReviewImageAsset;
use App\Models\User;
use App\Rules\ReviewSourceUrl;
use App\Services\Reviews\ReviewImageAssets;
use App\Services\Reviews\ReviewMediaManager;
use App\Support\Admin\ReviewsPanel;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class ReviewEditor extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Отзыв';

    protected string $view = 'filament.reviews.editor';

    #[Locked]
    public ?int $reviewId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public string $saveKey = '';

    #[Locked]
    public string $publicationKey = '';

    #[Locked]
    public string $deletionKey = '';

    #[Locked]
    public string $loadedInputHash = '';

    #[Locked]
    public bool $editableOnLoad = true;

    public ?array $data = [];

    public ?string $operationError = null;

    #[Url]
    public string $returnTo = '/admin/reviews';

    #[Locked]
    public ?string $saveIntent = null;

    #[Locked]
    public ?string $publicationIntent = null;

    #[Locked]
    public string $savingOpening = '';

    #[Locked]
    public array $savingBaseline = [];

    #[Locked]
    public array $savingPreparation = ['pending' => false, 'error' => null];

    protected function hasUnsavedDataChangesAlert(): bool
    {
        // The saving coordinator owns beforeunload and confirmed discard.
        return ! $this->editableOnLoad;
    }

    #[Computed]
    public function review(): ?array
    {
        return $this->reviewId === null ? null : app(ReviewsPanel::class)->detail($this->reviewId);
    }

    #[Computed]
    public function manuallyManaged(): bool
    {
        app(ReviewsPanel::class)->actor();

        return $this->reviewId === null || Review::query()->whereKey($this->reviewId)
            ->where('source', 'admin')->where('origin_type', Review::ORIGIN_MANUAL)->exists();
    }

    public function backUrl(): string
    {
        $url = parse_url($this->returnTo);

        return is_array($url) && ! isset($url['scheme'])
            && ! isset($url['host']) && ($url['path'] ?? '') === '/admin/reviews'
            && strlen($this->returnTo) <= 4096 && ! str_contains($this->returnTo, "\n")
            && ! str_contains($this->returnTo, "\r")
            ? $this->returnTo : '/admin/reviews';
    }

    public static function canAccess(): bool
    {
        $actor = auth('web')->user();
        $actor = $actor instanceof User ? $actor->fresh() : null;

        return $actor instanceof User && ! $actor->blocksAuthenticationForMigration()
            && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'reviews.read');
    }

    public function mount(?string $reviewId = null): void
    {
        if ($reviewId !== null) {
            abort_unless(ctype_digit($reviewId) && (int) $reviewId > 0, 404);
            $this->reviewId = (int) $reviewId;
            $this->loadReview(app(ReviewsPanel::class)->detail($this->reviewId));
        } else {
            app(ReviewsPanel::class)->actor('reviews.write');
            $this->form->fill(['displayName' => '', 'isAnonymous' => false, 'text' => '',
                'showOnHome' => false, 'screenshotPaths' => [],
                'sourceUrl' => null,
                'reviewWrittenAt' => now()->toDateString(), 'scope' => 'all', 'subjectId' => null, 'courseId' => null]);
        }
        $this->saveKey = (string) Str::uuid();
        $this->publicationKey = (string) Str::uuid();
        $this->deletionKey = (string) Str::uuid();
        $this->savingOpening = (string) Str::uuid();
        $this->savingBaseline = $this->data ?? [];
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Group::make([
                    Section::make('Содержание')->schema([
                        Toggle::make('isAnonymous')->label('Анонимный автор')->live()
                            ->helperText(fn (Get $get) => $get('isAnonymous') ? 'На сайте будет указано «Анонимный автор».' : null),
                        TextInput::make('displayName')->label('Имя автора')->maxLength(255)->live(onBlur: true)
                            ->belowContent(view('filament.field-saving.hint', ['field' => 'displayName']))
                            ->disabled(fn (Get $get) => (bool) $get('isAnonymous'))->dehydrated()
                            ->required(fn (Get $get) => ! $get('isAnonymous')),
                        // The asset determines its physical disk; imagePreview validates and reads it.
                        FileUpload::make('authorPhotoPath')->label('Фотография автора')
                            ->disk('public')->directory('review-authors')->visibility('public')->previewable(false)->fetchFileInformation(false)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif'])->maxSize(25600)
                            ->afterStateUpdated(fn (FileUpload $component) => $this->stageImages($component))
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => $this->storeImage($file, 'authorPhotoPath'))
                            ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => $this->canUseImage($file, 'avatar'))
                            ->getUploadedFileUsing(fn (string $file): ?array => $this->imagePreview($file, 'avatar'))
                            ->disabled(fn (Get $get) => (bool) $get('isAnonymous'))
                            ->helperText('Необязательно. Без фотографии на сайте появится нейтральный значок человека.'),
                        Textarea::make('text')->label('Текст отзыва')->required()->maxLength(20000)->rows(9)->live(onBlur: true)
                            ->extraInputAttributes(fn (Textarea $component): array => ['aria-describedby' => implode(' ', [...$component->getDescriptionIds(), 'review-text-help'])])
                            ->belowContent([
                                Text::make('Сохраняйте слова автора и разделение на абзацы.')->extraAttributes(['id' => 'review-text-help']),
                                SchemaView::make('filament.field-saving.hint')->viewData(['field' => 'text']),
                            ]),
                        DatePicker::make('reviewWrittenAt')->label('Дата отзыва')->required()->maxDate(now())->live(onBlur: true),
                        FileUpload::make('screenshotPaths')->label('Скриншоты отзыва')
                            ->multiple()->reorderable()->maxFiles(10)
                            ->disk('public')->directory('review-screenshots')->visibility('public')->previewable(false)->fetchFileInformation(false)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif'])->maxSize(25600)
                            ->afterStateUpdated(fn (FileUpload $component) => $this->stageImages($component))
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => $this->storeImage($file, 'screenshotPaths'))
                            ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => $this->canUseImage($file, 'screenshot'))
                            ->getUploadedFileUsing(fn (string $file): ?array => $this->imagePreview($file, 'screenshot'))
                            ->helperText('До 10 изображений JPEG, PNG, WebP, AVIF или HEIC, до 25 МБ каждое. Дождитесь подготовки перед сохранением. Загруженные оригиналы после обработки удаляются.'),
                        TextInput::make('sourceUrl')->label('Ссылка на источник')->type('url')->maxLength(255)
                            ->extraInputAttributes(fn (TextInput $component): array => ['aria-describedby' => implode(' ', [...$component->getDescriptionIds(), 'review-source-help'])])
                            ->rules([new ReviewSourceUrl])->live(onBlur: true)
                            ->placeholder('https://…')
                            ->belowContent([
                                Text::make('Необязательно. Ссылка на оригинал отзыва, например ВКонтакте или на Яндекс.Картах. Для личных сообщений оставьте пустым.')->extraAttributes(['id' => 'review-source-help']),
                                SchemaView::make('filament.field-saving.hint')->viewData(['field' => 'sourceUrl']),
                            ]),
                    ]),
                    Section::make('Привязка')->schema([
                        Select::make('scope')->label('К чему относится отзыв')->options(['all' => 'Общий отзыв', 'subject' => 'Предмет', 'course' => 'Курс'])->required()->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('subjectId', null);
                                $set('courseId', null);
                            }),
                        Select::make('subjectId')->label('Предмет')->options(fn () => app(ReviewsPanel::class)->subjects())->searchable()
                            ->visible(fn (Get $get) => $get('scope') === 'subject')->required(fn (Get $get) => $get('scope') === 'subject')->dehydrated()->live(),
                        Select::make('courseId')->label('Курс')->options(fn () => app(ReviewsPanel::class)->courses())->searchable()
                            ->visible(fn (Get $get) => $get('scope') === 'course')->required(fn (Get $get) => $get('scope') === 'course')->dehydrated()->live(),
                        Toggle::make('showOnHome')->label('Показывать на главной')
                            ->helperText('На главной отображаются только опубликованные отзывы с этой отметкой.'),
                    ]),
                ])->columnSpan(['lg' => 2]),
                $this->metadataSection(),
            ]),
            SchemaView::make('filament.field-saving.status'),
        ])->statePath('data');
    }

    public function savingConfig(): array
    {
        return ['baseline' => $this->savingBaseline, 'revision' => $this->revision,
            'opening' => $this->savingOpening, 'openingPath' => 'savingOpening', 'statePath' => 'data',
            'parameters' => AdminReviewRequest::PARAMETERS, 'manual' => ['displayName', 'text', 'sourceUrl'],
            'existing' => $this->reviewId !== null, 'saveMethod' => 'saveReviewParameters', 'submitMethod' => 'save',
            'standalone' => true, 'uploads' => true, 'preparationPath' => 'savingPreparation',
            'loginUrl' => '/auth?mode=login&next=%2Fadmin%2Freviews',
            'groups' => [['scope', 'subjectId', 'courseId']]];
    }

    public function dehydrate(): void
    {
        $images = $this->imagePreparation();
        $failed = collect($images)->firstWhere('status', 'failed');
        $this->savingPreparation = ['pending' => collect($images)->contains(fn ($image) => in_array($image['status'], ['pending', 'processing'], true)),
            'error' => $failed ? ($failed['error'] ?? 'Не удалось подготовить изображение. Введённый текст остаётся в форме.') : null];
    }

    public function saveReviewParameters(array $patch, int $revision, string $opening): array
    {
        app(ReviewsPanel::class)->actor('reviews.write');
        abort_unless($this->reviewId !== null && $opening === $this->savingOpening, 409);
        if (array_diff(array_keys($patch), AdminReviewRequest::PARAMETERS)) {
            throw ValidationException::withMessages(['data.request' => 'Запрос содержит недопустимые поля.']);
        }
        try {
            $input = $patch;
            if (array_key_exists('authorPhotoPath', $input)) {
                $paths = $this->imagePaths($input['authorPhotoPath'], 'authorPhotoPath');
                if (count($paths) > 1) {
                    throw ValidationException::withMessages(['authorPhotoPath' => 'Выберите одну фотографию автора.']);
                }
                $input['authorPhotoPath'] = $paths[0] ?? null;
            }
            if (array_key_exists('screenshotPaths', $input)) {
                $input['screenshotPaths'] = $this->imagePaths($input['screenshotPaths'], 'screenshotPaths');
            }
            $review = app(ReviewsPanel::class)->execute('parameters', $this->reviewId, $input + ['revision' => $revision], $this->saveKey);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('data.'.$field, $messages[0]);
            }

            return ['ok' => false, 'errors' => $exception->errors()];
        } catch (AdminReviewException $exception) {
            $this->operationError = $exception->getMessage();

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        foreach ($patch as $field => $value) {
            $this->savingBaseline[$field] = $value;
            $this->resetValidation('data.'.$field);
        }
        $derived = [];
        if (($patch['isAnonymous'] ?? false) === true) {
            $derived['displayName'] = $review['displayName'];
            $derived['authorPhotoPath'] = [];
            $this->savingBaseline['displayName'] = $review['displayName'];
            $this->savingBaseline['authorPhotoPath'] = [];
        }
        $this->revision = $review['revision'];
        $this->loadedInputHash = $this->hashInput($this->savingBaseline);
        $this->saveKey = (string) Str::uuid();
        $this->saveIntent = null;
        unset($this->review);

        return ['ok' => true, 'revision' => $this->revision, 'fields' => $patch, 'derivedFields' => $derived];
    }

    private function imagePaths(mixed $state, string $field): array
    {
        $paths = array_values(Arr::wrap($state));
        if (collect($paths)->contains(fn ($path) => ! is_string($path))) {
            throw ValidationException::withMessages([$field => 'Дождитесь подготовки изображений.']);
        }

        return $paths;
    }

    private function metadataSection(): Section
    {
        return Section::make('Сведения')->schema([
            TextEntry::make('publication')->label('Публикация')->badge()
                ->state(fn () => ReviewsPanel::PUBLICATION_LABELS[$this->review['publicationStatus'] ?? 'draft'])
                ->color(fn () => match ($this->review['publicationStatus'] ?? 'draft') {
                    'published' => 'success', 'hidden' => 'warning', default => 'gray'
                }),
            TextEntry::make('origin')->label('Источник')->state(fn () => ReviewsPanel::ORIGIN_LABELS[$this->review['originType'] ?? 'manual']),
            TextEntry::make('moderation')->label('Модерация')->state(fn () => ['pending' => 'Ожидает', 'needs_review' => 'Требует проверки', 'approved' => 'Одобрен', 'rejected' => 'Отклонён'][$this->review['moderationStatus'] ?? 'pending']),
            TextEntry::make('dedupe')->label('Проверка дублей')->state(fn () => ['unchecked' => 'Не проверен', 'unique' => 'Уникальный', 'candidate' => 'Возможный дубль', 'duplicate' => 'Дубль'][$this->review['dedupeStatus'] ?? 'unchecked']),
            TextEntry::make('photo')->label('Фото в материалах')->state(fn () => ($this->review['hasPhoto'] ?? false) ? 'Есть' : 'Нет'),
            TextEntry::make('published')->label('Опубликован')->state(fn () => $this->review['publishedAt'] ?? null)->dateTime('d.m.Y H:i')->placeholder('—'),
        ]);
    }

    private function storeImage(TemporaryUploadedFile $file, string $field): string
    {
        try {
            return app(ReviewImageAssets::class)->stage($file, $field === 'authorPhotoPath' ? 'avatar' : 'screenshot', auth('web')->user())->primary_path;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages(['data.'.$field => $exception->getMessage()]);
        }
    }

    private function stageImages(FileUpload $component): void
    {
        // Saving invokes afterStateUpdated again with permanent path strings.
        if (collect(Arr::wrap($component->getRawState()))->contains(fn ($file): bool => $file instanceof TemporaryUploadedFile)) {
            $component->saveUploadedFiles();
        }
    }

    private function canUseImage(string $path, string $kind): bool
    {
        if ($kind === 'screenshot' && str_starts_with($path, 'existing:')) {
            return $this->reviewId !== null && isset(app(ReviewMediaManager::class)->current(Review::findOrFail($this->reviewId))[$path]);
        }
        $asset = ReviewImageAsset::where('primary_path', $path)->first();
        if ($asset) {
            return $asset->kind === $kind && $asset->status !== 'deleting'
                && (($asset->review_id === null && (int) $asset->owner_id === auth('web')->id())
                    || ($this->reviewId !== null && (int) $asset->review_id === $this->reviewId));
        }

        return $this->reviewId !== null && Review::whereKey($this->reviewId)
            ->when($kind === 'avatar', fn ($query) => $query->where('author_photo_path', $path),
                fn ($query) => $query->whereHas('media', fn ($media) => $media->where('type', 'screenshot')->where('url', '/storage/'.$path)))->exists();
    }

    private function imagePreview(string $path, string $kind): ?array
    {
        if (! $this->canUseImage($path, $kind)) {
            return null;
        }
        if (str_starts_with($path, 'existing:')) {
            $image = app(ReviewMediaManager::class)->current(Review::findOrFail($this->reviewId))[$path];

            return ['name' => 'Скриншот отзыва.webp', 'size' => 0, 'type' => 'image/webp', 'url' => $image['url']];
        }
        $asset = ReviewImageAsset::where('primary_path', $path)->first();
        $disk = Storage::disk($asset?->disk ?? 'public');
        if ($asset && $asset->status !== 'ready') {
            return ['name' => $kind === 'avatar' ? 'Фотография автора.webp' : 'Скриншот отзыва.webp', 'size' => 0, 'type' => 'application/octet-stream',
                'url' => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#eeeeee"/></svg>')];
        }
        if ($asset?->disk === 'review-media-published') {
            return ['name' => basename($path), 'size' => collect($asset->variants)->firstWhere('path', $path)['bytes'] ?? 0,
                'type' => 'image/webp', 'url' => $asset->url()];
        }
        if (! $disk->exists($path)) {
            return null;
        }

        return ['name' => basename($path), 'size' => $disk->size($path), 'type' => $disk->mimeType($path), 'url' => $asset?->url() ?? '/storage/'.$path];
    }

    public function imagePreparation(): array
    {
        $paths = array_values(array_filter([
            ...Arr::wrap($this->data['authorPhotoPath'] ?? []),
            ...Arr::wrap($this->data['screenshotPaths'] ?? []),
        ], 'is_string'));

        return ReviewImageAsset::whereIn('primary_path', $paths)->where('status', '!=', 'ready')
            ->where(fn ($query) => $query->where('owner_id', auth('web')->id())
                ->when($this->reviewId !== null, fn ($query) => $query->orWhere('review_id', $this->reviewId)))
            ->get()->map(fn (ReviewImageAsset $asset): array => ['status' => $asset->status,
                'label' => $asset->kind === 'avatar' ? 'Фотография автора' : 'Скриншот отзыва', 'error' => $asset->error])->all();
    }

    public function refreshImagePreparation(): void
    {
        if ($this->imagePreparation() !== []) {
            return;
        }
        foreach ($this->getErrorBag()->messages() as $key => $messages) {
            if ((str_starts_with($key, 'data.authorPhotoPath') || str_starts_with($key, 'data.screenshotPaths'))
                && collect($messages)->every(fn (string $message): bool => str_contains($message, 'готовится'))) {
                $this->resetValidation($key);
            }
        }
    }

    public function preparedImagePreviews(): array
    {
        $paths = array_filter([...Arr::wrap($this->data['authorPhotoPath'] ?? []),
            ...Arr::wrap($this->data['screenshotPaths'] ?? [])], 'is_string');

        return ReviewImageAsset::whereIn('primary_path', $paths)->where('status', 'ready')->get()
            ->filter(fn (ReviewImageAsset $asset): bool => $this->canUseImage($asset->primary_path, $asset->kind))
            ->map(function (ReviewImageAsset $asset): array {
                $preview = collect($asset->variants)->where('format', 'webp')->first(fn (array $variant): bool => $variant['width'] >= 128)
                    ?? collect($asset->variants)->firstWhere('format', 'webp');

                return ['url' => $asset->url($preview['path']), 'fullUrl' => $asset->url(), 'label' => $asset->kind === 'avatar' ? 'Фотография автора' : 'Скриншот отзыва'];
            })->all();
    }

    public function readOnlyContent(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Содержание')->description(fn () => $this->readOnlyReason())->schema([
                    TextEntry::make('displayName')->label('Автор')->state(fn () => ($this->review['isAnonymous'] ?? false) ? 'Анонимный автор' : $this->review['displayName']),
                    TextEntry::make('text')->label('Текст отзыва')->state(fn () => $this->review['text'])
                        ->formatStateUsing(fn (string $state) => nl2br(e($state)))->html()->extraAttributes(['style' => 'overflow-wrap: anywhere']),
                    TextEntry::make('date')->label('Дата отзыва')->state(fn () => $this->review['reviewWrittenAt'])->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('scope')->label('Привязка')->state(fn () => $this->scopeLabel()),
                ])->columnSpan(['lg' => 2]),
                $this->metadataSection(),
            ]),
        ]);
    }

    public function homePlacement(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Главная страница')->schema([
                Toggle::make('showOnHome')->label('Показывать на главной')
                    ->helperText('Отметка действует только для опубликованного отзыва. Снятие отметки не скрывает отзыв с остальных страниц.'),
            ]),
        ])->statePath('data');
    }

    public function saveHomePlacement(): void
    {
        app(ReviewsPanel::class)->actor('reviews.write');
        abort_unless($this->reviewId !== null, 404);
        $input = ['showOnHome' => $this->data['showOnHome'] ?? false, 'revision' => $this->revision];
        $intent = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        if ($this->saveIntent !== null && $this->saveIntent !== $intent) {
            $this->saveKey = (string) Str::uuid();
        }
        $this->saveIntent = $intent;
        $review = $this->execute('placement', $input, $this->saveKey);
        if ($review !== null) {
            $this->loadReview($review);
            $this->saveKey = (string) Str::uuid();
            $this->saveIntent = null;
        }
    }

    private function readOnlyReason(): string
    {
        if (! $this->manuallyManaged) {
            return 'Исходные сведения доступны только для просмотра. Опубликованный отзыв можно скрыть с сайта.';
        }

        return ($this->review['publicationStatus'] ?? '') === 'published'
            ? 'Чтобы изменить отзыв, сначала скройте его с сайта.'
            : 'Этому отзыву доступен только просмотр.';
    }

    private function scopeLabel(): string
    {
        $review = $this->review;

        return isset($review['course']) ? ($review['subject']['title'] ?? '').' · '.$review['course']['title']
            : ($review['subject']['title'] ?? 'Общий отзыв');
    }

    public function supportingContent(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Текстовый предпросмотр')->collapsible()->collapsed()
                ->description('Содержимое отзыва без оформления публичной карточки и прикреплённых материалов.')
                ->schema([
                    TextEntry::make('previewName')->hiddenLabel()->state(fn () => ($this->data['isAnonymous'] ?? false) ? 'Анонимный автор' : ($this->data['displayName'] ?: 'Имя автора')),
                    TextEntry::make('previewText')->hiddenLabel()->state(fn () => $this->data['text'] ?: 'Текст отзыва появится здесь.')
                        ->formatStateUsing(fn (string $state) => nl2br(e($state)))->html()->extraAttributes(['style' => 'overflow-wrap: anywhere']),
                    TextEntry::make('previewDate')->label('Дата отзыва')->state(fn () => $this->data['reviewWrittenAt'] ?? null)->date('d.m.Y')->placeholder('—'),
                    TextEntry::make('previewScope')->label('Привязка')->state(function (): string {
                        return match ($this->data['scope'] ?? 'all') {
                            'subject' => app(ReviewsPanel::class)->subjects()[$this->data['subjectId'] ?? ''] ?? 'Предмет не выбран',
                            'course' => app(ReviewsPanel::class)->courses()[$this->data['courseId'] ?? ''] ?? 'Курс не выбран',
                            default => 'Общий отзыв',
                        };
                    }),
                ]),
            Section::make('История изменений')->collapsible()->collapsed()->visible(fn () => $this->reviewId !== null)->schema([
                RepeatableEntry::make('events')->hiddenLabel()->state(fn () => array_map(fn (array $event): array => [
                    ...$event,
                    'changeDetails' => $this->changeDetails($event['changes'] ?? []),
                    'transition' => (ReviewsPanel::PUBLICATION_LABELS[$event['fromPublicationStatus'] ?? ''] ?? '—')
                        .' → '.(ReviewsPanel::PUBLICATION_LABELS[$event['toPublicationStatus'] ?? ''] ?? '—'),
                ], $this->review['events'] ?? []))->contained(false)
                    ->schema([
                        TextEntry::make('createdAt')->label('Когда')->dateTime('d.m.Y H:i'),
                        TextEntry::make('actorName')->label('Кто')->placeholder('Система'),
                        TextEntry::make('type')->label('Действие')->formatStateUsing(fn (string $state) => ReviewsPanel::EVENT_LABELS[$state] ?? 'Изменение отзыва'),
                        TextEntry::make('transition')->label('Статус'),
                        TextEntry::make('changeDetails')->label('Что изменено')->columnSpanFull()
                            ->formatStateUsing(fn (string $state) => nl2br(e($state)))->html(),
                    ])->columns(['default' => 1, 'md' => 4]),
                TextEntry::make('emptyHistory')->hiddenLabel()->state('История пока пуста.')->visible(fn () => empty($this->review['events'])),
            ]),
        ]);
    }

    public function save(): array
    {
        app(ReviewsPanel::class)->actor('reviews.write');
        $submitted = $this->data ?? [];
        $state = $this->form->getState();
        $input = array_replace($this->data ?? [], $state);
        $input['authorPhotoPath'] = ($input['isAnonymous'] ?? false) ? null : ($state['authorPhotoPath'] ?? null);
        $input['screenshotPaths'] = $state['screenshotPaths'] ?? [];
        if ($this->reviewId !== null && $this->inputHash() === $this->loadedInputHash) {
            return ['ok' => true, 'revision' => $this->revision, 'fields' => $submitted];
        }
        if ($this->reviewId !== null) {
            $input['revision'] = $this->revision;
        }
        $intent = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        if ($this->saveIntent !== null && $this->saveIntent !== $intent) {
            $this->saveKey = (string) Str::uuid();
        }
        $this->saveIntent = $intent;
        $review = $this->execute($this->reviewId === null ? 'create' : 'update', $input, $this->saveKey);
        if ($review === null) {
            return ['ok' => false, 'terminal' => true, 'message' => $this->operationError];
        }
        if ($this->reviewId === null) {
            $this->rememberData();
            $redirect = '/admin/reviews/'.$review['id'].($this->backUrl() !== '/admin/reviews' ? '?'.http_build_query(['returnTo' => $this->backUrl()]) : '');
            $this->redirect($redirect);

            return ['ok' => true, 'revision' => $review['revision'], 'fields' => $submitted, 'redirect' => $redirect];
        }
        $this->revision = $review['revision'];
        $normalized = ($input['isAnonymous'] ?? false) ? ['displayName' => $review['displayName'], 'authorPhotoPath' => []] : [];
        $this->savingBaseline = array_replace($submitted, $normalized);
        $this->loadedInputHash = $this->hashInput($submitted);
        $this->rememberData();
        $this->saveKey = (string) Str::uuid();
        $this->saveIntent = null;

        return ['ok' => true, 'revision' => $this->revision, 'fields' => $submitted, 'normalized' => $normalized];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public')->label('Открыть на сайте')->url(fn () => '/reviews?review='.$this->reviewId)->openUrlInNewTab()
                ->visible(fn () => ($this->review['publicationStatus'] ?? '') === 'published'),
            Action::make('publish')->label('Опубликовать')->requiresConfirmation()
                ->modalHeading('Опубликовать отзыв?')->modalDescription('Сохранённый отзыв станет виден посетителям сайта.')->modalSubmitActionLabel('Опубликовать')
                ->visible(fn () => $this->review['canPublish'] ?? false)
                ->action(fn () => $this->publishReview('publish')),
            Action::make('hide')->label('Скрыть с сайта')->color('warning')->requiresConfirmation()
                ->modalHeading('Скрыть отзыв с сайта?')->modalSubmitActionLabel('Скрыть с сайта')
                ->modalDescription('Отзыв исчезнет с сайта. Его данные и история сохранятся. Его можно опубликовать снова.')
                ->visible(fn () => $this->review['canHide'] ?? false)
                ->action(fn () => $this->publishReview('hide')),
            Action::make('reload')->label('Загрузить актуальную версию')->color('gray')->requiresConfirmation()
                ->extraAttributes(['x-on:click.capture' => '$event.preventDefault(); $event.stopImmediatePropagation(); $dispatch("review-reload")'])
                ->modalDescription('Несохранённые изменения будут потеряны.')
                ->visible(fn () => $this->reviewId !== null && $this->operationError !== null)
                ->action(fn () => $this->reloadReview()),
            Action::make('delete')->label('Удалить навсегда')->color('danger')->requiresConfirmation()
                ->modalHeading(fn () => 'Удалить отзыв № '.$this->reviewId.' навсегда?')
                ->modalDescription('Отзыв, его источники и история будут удалены без возможности восстановления. Рабочие изображения и все их версии также будут удалены; общие файлы других отзывов сохранятся.')
                ->modalSubmitActionLabel('Удалить навсегда')
                ->modalCancelActionLabel('Отмена')
                ->schema([TextInput::make('confirmation')->label('Введите ID отзыва для подтверждения')->required()
                    ->validationMessages(['required' => 'Введите ID отзыва для подтверждения.', 'in' => 'Введите точный ID этого отзыва.'])
                    ->rules([fn () => 'in:'.$this->reviewId])])
                ->visible(fn () => $this->reviewId !== null && Rbac::hasPermission(auth('web')->user(), 'reviews.write'))
                ->action(fn (array $data) => $this->deleteReview($data)),
        ];
    }

    protected function getViewData(): array
    {
        return ['review' => $this->review];
    }

    public function getBreadcrumbs(): array
    {
        return [$this->backUrl() => 'Отзывы', $this->reviewId === null ? 'Новый отзыв' : 'Отзыв № '.$this->reviewId];
    }

    public function getSubheading(): ?string
    {
        return $this->reviewId === null ? 'Сначала сохраните черновик. Публикация выполняется отдельно.'
            : '№ '.$this->reviewId.' · '.ReviewsPanel::PUBLICATION_LABELS[$this->review['publicationStatus']];
    }

    private function publishReview(string $action): void
    {
        abort_unless($this->reviewId !== null, 404);
        if ($this->inputHash() !== $this->loadedInputHash) {
            $this->operationError = 'Сначала сохраните изменения или загрузите отзыв заново.';

            return;
        }
        $intent = $action.':'.$this->revision;
        if ($this->publicationIntent !== null && $this->publicationIntent !== $intent) {
            $this->publicationKey = (string) Str::uuid();
        }
        $this->publicationIntent = $intent;
        $review = $this->execute('publication', ['action' => $action, 'revision' => $this->revision], $this->publicationKey);
        if ($review !== null) {
            $this->loadReview($review);
            $this->publicationKey = (string) Str::uuid();
            $this->publicationIntent = null;
        }
    }

    private function deleteReview(array $data): void
    {
        abort_unless($this->reviewId !== null, 404);
        $this->operationError = null;
        try {
            app(ReviewsPanel::class)->delete($this->reviewId, ['confirmation' => $data['confirmation'], 'revision' => $this->revision], $this->deletionKey);
        } catch (AdminReviewException $exception) {
            $this->operationError = $exception->getMessage();

            return;
        } catch (QueryException $exception) {
            report($exception);
            $this->operationError = 'Не удалось подтвердить удаление. Повторите действие.';

            return;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 429) {
                throw $exception;
            }
            $this->operationError = 'Слишком много попыток. Подождите минуту и повторите действие.';

            return;
        }
        $this->rememberData();
        $this->reviewId = null;
        unset($this->review);
        Notification::make()->title('Отзыв удалён навсегда')->success()->send();
        $this->redirect($this->backUrl());
    }

    public function reloadReview(): void
    {
        $this->loadReview(app(ReviewsPanel::class)->detail($this->reviewId));
        $this->saveKey = (string) Str::uuid();
        $this->publicationKey = (string) Str::uuid();
        $this->operationError = null;
        $this->saveIntent = $this->publicationIntent = null;
        $this->resetValidation();
    }

    private function execute(string $operation, array $input, string $key): ?array
    {
        $this->operationError = null;
        try {
            $review = app(ReviewsPanel::class)->execute($operation, $this->reviewId, $input, $key);
        } catch (AdminReviewException $exception) {
            $this->operationError = $exception->getMessage();

            return null;
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[array_key_exists($field, $this->data ?? []) ? 'data.'.$field : $field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 429) {
                throw $exception;
            }
            $this->operationError = 'Слишком много попыток. Подождите минуту и повторите действие. Введённые данные сохранены в форме.';

            return null;
        } catch (QueryException $exception) {
            report($exception);
            $this->operationError = 'Не удалось подтвердить сохранение. Введённые данные остались в форме. Повторите действие.';

            return null;
        }
        unset($this->review);
        Notification::make()->title(match ($operation) {
            'create' => 'Черновик создан',
            'publication' => $input['action'] === 'publish' ? 'Отзыв опубликован' : 'Отзыв скрыт с сайта',
            default => 'Изменения сохранены',
        })->success()->send();

        return $review;
    }

    private function loadReview(array $review): void
    {
        $this->reviewId = $review['id'];
        $this->revision = $review['revision'];
        $this->editableOnLoad = $review['canEdit'];
        $this->form->fill([
            'displayName' => $review['displayName'], 'isAnonymous' => $review['isAnonymous'], 'text' => $review['text'],
            'authorPhotoPath' => $review['authorPhotoPath'],
            'showOnHome' => $review['showOnHome'], 'screenshotPaths' => $review['screenshotPaths'],
            'sourceUrl' => $review['sourceUrl'] ?? null,
            'reviewWrittenAt' => $review['reviewWrittenAt'], 'scope' => $review['course'] ? 'course' : ($review['subject'] ? 'subject' : 'all'),
            'subjectId' => $review['course'] ? null : ($review['subject']['id'] ?? null), 'courseId' => $review['course']['id'] ?? null,
        ]);
        $this->loadedInputHash = $this->inputHash();
        $this->savingBaseline = $this->data ?? [];
        $this->savingOpening = (string) Str::uuid();
        $this->rememberData();
        unset($this->review);
    }

    private function changeDetails(array $changes): string
    {
        $labels = ['displayName' => 'Автор', 'isAnonymous' => 'Анонимность', 'text' => 'Текст', 'reviewWrittenAt' => 'Дата отзыва',
            'authorPhotoPath' => 'Фотография автора', 'showOnHome' => 'Показ на главной', 'subjectId' => 'Предмет', 'courseId' => 'Курс', 'screenshots' => 'Скриншоты', 'sourceUrl' => 'Ссылка на источник'];
        $lines = [];
        foreach ($changes as $field => $change) {
            $format = static fn ($value) => is_array($value) ? count($value).' шт.'
                : (is_bool($value) ? ($value ? 'Да' : 'Нет') : Str::limit((string) ($value ?? '—'), 500));
            $lines[] = ($labels[$field] ?? $field).': '.$format($change['before']).' → '.$format($change['after']);
        }

        return implode("\n", $lines) ?: '—';
    }

    private function inputHash(): string
    {
        return $this->hashInput($this->data ?? []);
    }

    private function hashInput(array $input): string
    {
        foreach (['authorPhotoPath', 'screenshotPaths'] as $field) {
            $input[$field] = array_values(Arr::wrap($input[$field] ?? []));
        }
        if ($input['isAnonymous'] ?? false) {
            $input['displayName'] = 'Анонимный автор';
            $input['authorPhotoPath'] = [];
        }
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
