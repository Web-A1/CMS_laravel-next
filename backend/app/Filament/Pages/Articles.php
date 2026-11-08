<?php

namespace App\Filament\Pages;

use App\Filament\RichContent\ArticleRichContentPlugin;
use App\Filament\RichContent\ArticleRichEditor;
use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\Subject;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleHtml;
use App\Services\Articles\ArticleMedia;
use App\Services\Articles\ArticleMediaLifecycle;
use App\Services\Articles\ArticlePreview;
use App\Services\Articles\ArticleRecommendations;
use App\Support\Admin\ColumnQuery;
use App\Support\Admin\HasColumnControls;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Articles extends Page implements HasTable
{
    use HasColumnControls;
    use HasUnsavedDataChangesAlert;
    use InteractsWithTable;

    public const COLUMN_FILTERS = ['title' => ['title'], 'subject.title' => ['subject'], 'author.name' => ['author'], 'status' => ['status'], 'published_at' => ['publication']];

    public const COLUMN_SEARCHES = ['title' => 'title'];

    public const CHOICE_FILTERS = ['subject.title' => 'subject', 'author.name' => 'author', 'status' => 'status'];

    #[Locked]
    public bool $editorOpen = false;

    #[Locked]
    public string $savedTitle = '';

    #[Locked]
    public string $savedStatus = 'draft';

    #[Locked]
    public array $savedForm = [];

    #[Locked]
    public ?int $authorId = null;

    #[Locked]
    public string $authorOpening = '';

    #[Locked]
    public ?string $authorHash = null;

    #[Locked]
    public array $authorSaved = [];

    #[Url]
    public string $returnTo = '/admin/articles';

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    #[Url(as: 'perPage')]
    public $tableRecordsPerPage = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/articles/{articleId?}';
    }

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        $middleware = static::getRouteMiddleware($panel);
        if ($configuration) {
            $middleware = [...$middleware, "page-configuration:{$configuration->getKey()}"];
        }
        Route::get(static::getRoutePath($panel), static::class)
            ->where('articleId', 'create|[1-9][0-9]*')
            ->middleware($middleware)->withoutMiddleware(static::getWithoutRouteMiddleware($panel))
            ->name(static::getRelativeRouteName($panel));
    }

    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return ! $this->editorOpen ? 'Статьи' : ($this->entryId ? $this->savedTitle : 'Новая статья');
    }

    public function getSubheading(): ?string
    {
        if (! $this->editorOpen) {
            return null;
        }

        return $this->entryId ? 'ID статьи: '.$this->entryId : 'Ещё не сохранена';
    }

    public function getSavedStatusLabel(): string
    {
        return self::statuses()[$this->savedStatus] ?? $this->savedStatus;
    }

    public function getHeader(): ?View
    {
        return $this->editorOpen ? view('filament.article-editor-header', [
            'heading' => $this->getTitle(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'idLabel' => $this->getSubheading(),
            'statusLabel' => $this->getSavedStatusLabel(),
            'statusColor' => match ($this->savedStatus) {
                'published' => 'success', 'draft' => 'warning', default => 'gray',
            },
        ]) : null;
    }

    public function getBreadcrumbs(): array
    {
        if (! $this->editorOpen) {
            return [];
        }

        return [$this->backUrl() => 'Статьи', $this->entryId
            ? (filled($this->data['title'] ?? null) ? $this->data['title'] : 'Статья №'.$this->entryId)
            : 'Новая статья'];
    }

    protected static ?string $title = 'Статьи';

    protected static ?string $slug = 'articles';

    protected string $view = 'filament.articles';

    public ?array $data = [];

    #[Locked]
    public ?int $entryId = null;

    #[Locked]
    public int $revision = 0;

    public static function canAccess(): bool
    {
        try {
            app(ArticleEditor::class)->actor('articles.read');

            return true;
        } catch (HttpException $e) {
            return false;
        }
    }

    public function mount(?string $articleId = null): void
    {
        app(ArticleEditor::class)->actor('articles.read');
        if ($articleId === 'create') {
            $this->fillNewEntry();
        } elseif ($articleId !== null) {
            $id = filter_var($articleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($id !== false, 404);
            $this->fillEntry($id);
        } else {
            $query = self::listQuery(['filters' => $this->tableFilters, 'sort' => $this->tableSort, 'perPage' => $this->tableRecordsPerPage]);
            $this->tableFilters = $query['filters'] ?? null;
            $this->tableSort = $query['sort'] ?? null;
            $this->tableRecordsPerPage = $query['perPage'] ?? 10;
            $this->snapshotForm();
        }
    }

    private function fillNewEntry(): void
    {
        app(ArticleEditor::class)->actor();
        $this->entryId = null;
        $this->revision = 0;
        $this->editorOpen = true;
        $this->savedTitle = '';
        $this->savedStatus = 'draft';
        $this->form->fill(['status' => 'draft', 'body' => '', 'title' => '', 'slug' => '', 'sourceMode' => false, 'recommendation_article_ids' => [], 'recommendation_faq_ids' => []]);
        $this->snapshotForm();
    }

    private function fillEntry(int $id, ?bool $sourceMode = null): void
    {
        app(ArticleEditor::class)->actor('articles.read');
        $entry = ContentEntry::query()->article()->findOrFail($id);
        $this->entryId = $id;
        $this->revision = $entry->revision;
        $this->savedTitle = $entry->title;
        $this->savedStatus = $entry->status;
        $this->editorOpen = true;
        $data = $entry->toArray();
        unset($data['tags']);
        $data['recommendation_article_ids'] = $entry->payload['recommendations']['article_ids'] ?? [];
        $data['recommendation_faq_ids'] = $entry->payload['recommendations']['faq_ids'] ?? [];
        $data['sourceMode'] = $sourceMode ?? filled(data_get($entry->payload, 'legacy.id'));
        if ($entry->body_format !== 'html') {
            $data['body'] = '<p>'.nl2br(e($entry->body)).'</p>';
        }
        $data['body'] = app(ArticleHtml::class)->sanitize($data['body']);
        $this->form->fill($data);
        $this->snapshotForm();
    }

    private function snapshotForm(): void
    {
        $this->savedForm = $this->data ?? [];
        $this->rememberData();
    }

    private function saveEntry(): ContentEntry
    {
        $sourceMode = (bool) ($this->data['sourceMode'] ?? false);
        $entry = app(ArticleEditor::class)->save($this->entryId, $this->form->getState(), $this->revision);
        $this->fillEntry($entry->id, $sourceMode);

        return $entry;
    }

    public function save(): array
    {
        $isNew = $this->entryId === null;
        $entry = $this->saveEntry();
        Notification::make()->title('Статья сохранена')->success()->send();

        $url = $isNew ? static::getUrl(['articleId' => $entry->id, 'returnTo' => $this->backUrl()], isAbsolute: false) : null;
        if ($url !== null) {
            // Both unsaved-change guards recognize Livewire's confirmed redirect effect.
            $this->redirect($url);
        }

        return ['ok' => true, 'entryId' => $entry->id, 'revision' => $entry->revision,
            'url' => $url];
    }

    public function previewDocument(): array
    {
        app(ArticleEditor::class)->actor('articles.read');
        abort_unless($this->editorOpen, 422);
        try {
            $html = app(ArticlePreview::class)->document($this->data ?? [], $this->componentFileAttachments ?? []);
            $this->resetValidation(['data.body', 'data.cover_path']);

            return ['ok' => true, 'html' => $html];
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $field = str_starts_with($field, 'data.') ? $field : 'data.'.($field === 'cover_path' ? 'body' : $field);
                $errors[$field] = $messages;
                $this->addError($field, $messages[0]);
            }

            return ['ok' => false, 'errors' => $errors];
        }
    }

    public function saveParameters(array $fields, int $revision): array
    {
        app(ArticleEditor::class)->actor();
        abort_unless($this->editorOpen && $this->entryId !== null, 422);
        abort_if(array_diff($fields, ArticleEditor::PARAMETERS) || ! array_is_list($fields), 422);
        $patch = [];
        $raw = [];
        $draftHashes = [];
        try {
            foreach (array_unique($fields) as $field) {
                $component = $this->form->getComponentByStatePath($field);
                abort_unless($component, 422);
                if ($field === 'cover_path') {
                    $draftHashes = app(ArticleMediaLifecycle::class)->hashes([app(ArticleHtml::class)->fromEditorState($this->data['body'] ?? '')]);
                    $component->saveUploadedFiles();
                }
                $patch[$field] = $component->getState();
                $raw[$field] = $component->getRawState();
            }
            $entry = app(ArticleEditor::class)->saveParameters($this->entryId, $patch, $revision, $draftHashes);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $key = str_starts_with($key, 'data.') ? $key : 'data.'.$key;
                $errors[$key] = $messages;
                $this->addError($key, $messages[0]);
            }

            return ['ok' => false, 'conflict' => isset($errors['data.revision']), 'errors' => $errors];
        }
        foreach ($raw as $key => $value) {
            $this->savedForm[$key] = $value;
            $this->resetValidation('data.'.$key);
        }
        $this->resetValidation('data.published_at');
        $this->revision = $entry->revision;
        $this->savedStatus = $entry->status;
        $this->savedDataHash = md5((string) str(json_encode($this->savedForm, JSON_UNESCAPED_UNICODE))->replace('\\', ''));

        return ['ok' => true, 'entryId' => $entry->id, 'revision' => $entry->revision, 'fields' => $raw];
    }

    public function table(Table $table): Table
    {
        app(ArticleEditor::class)->actor('articles.read');

        return $table->query(function () {
            $query = ContentEntry::query()->article()->with(['subject', 'author']);
            ColumnQuery::search($query, ['title'], $this->tableFilters['title']['value'] ?? null);
            ColumnQuery::dates($query, 'published_at', $this->tableFilters['publication'] ?? null, 'Europe/Moscow');

            return $query;
        })->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('title')->label('Заголовок')->wrap()->lineClamp(1)
                ->extraAttributes(['style' => 'max-width: 18rem'])->tooltip(fn (ContentEntry $record) => $record->title),
            TextColumn::make('subject.title')->label('Предмет')->placeholder('Без предмета')->wrap()->lineClamp(1)
                ->extraAttributes(['style' => 'max-width: 10rem'])->tooltip(fn (ContentEntry $record) => $record->subject?->title),
            TextColumn::make('author.name')->label('Автор')->placeholder('Без автора')->wrap()->lineClamp(1)
                ->extraAttributes(['style' => 'max-width: 10rem'])->tooltip(fn (ContentEntry $record) => $record->author?->name),
            TextColumn::make('status')->label('Статус')->badge()
                ->color(fn ($state) => match ($state) {
                    'published' => 'success', 'draft' => 'warning', default => 'gray'
                })
                ->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state),
            TextColumn::make('published_at')->label('Публикация')->dateTime('d.m.Y H:i', timezone: 'Europe/Moscow')->placeholder('Не назначена')->sortable(),
        ])->filters([
            self::textFilter('title', 'Заголовок'),
            SelectFilter::make('subject')->label('Предмет')
                ->options(fn () => ['none' => 'Без предмета'] + Subject::query()->orderBy('sort_order')->orderBy('id')->pluck('title', 'id')->all())
                ->query(function ($query, array $data): void {
                    $value = $data['value'] ?? null;
                    if (blank($value) || ! array_key_exists($value, $this->getTable()->getFilter('subject')->getOptions())) {
                        return;
                    }

                    $value === 'none' ? $query->whereNull('content_entries.subject_id') : $query->where('content_entries.subject_id', $value);
                }),
            SelectFilter::make('author')->label('Автор')->relationship('author', 'name')
                ->options(fn () => ContentAuthor::query()->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->label('Статус')->options(self::statuses()),
            self::dateFilter('publication', 'Публикация'),
        ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->defaultSort('published_at', 'desc')
            ->recordUrl(fn (ContentEntry $record) => static::getUrl(['articleId' => $record->id, 'returnTo' => $this->listUrl()], isAbsolute: false))->recordActions([])
            ->emptyStateHeading('Статьи не найдены')->emptyStateDescription('Добавьте статью или измените условия поиска.')
            ->paginated([10, 25, 50]);
    }

    private static function statuses(): array
    {
        return ['draft' => 'Черновик', 'published' => 'Опубликовано', 'hidden' => 'Скрыто', 'archived' => 'В архиве'];
    }

    private static function listQuery(array $query): array
    {
        $result = [];
        if (in_array($query['perPage'] ?? null, [10, 25, 50, '10', '25', '50'], true)) {
            $result['perPage'] = (int) $query['perPage'];
        }
        if (is_scalar($query['page'] ?? null) && ($page = filter_var($query['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false) {
            $result['page'] = $page;
        }
        if (is_string($query['sort'] ?? null) && preg_match('/^(id|published_at):(asc|desc)$/D', $query['sort'])) {
            $result['sort'] = $query['sort'];
        }
        $filters = is_array($query['filters'] ?? null) ? $query['filters'] : [];
        $title = $filters['title']['value'] ?? null;
        if (is_string($title)) {
            $result['filters']['title']['value'] = mb_substr($title, 0, 200);
        }
        foreach (['status' => array_keys(self::statuses()), 'subject' => array_merge(['none'], Subject::query()->pluck('id')->map(fn ($id) => (string) $id)->all()), 'author' => ContentAuthor::query()->pluck('id')->map(fn ($id) => (string) $id)->all()] as $key => $options) {
            $value = $filters[$key]['value'] ?? null;
            if (is_scalar($value) && in_array((string) $value, $options, true)) {
                $result['filters'][$key]['value'] = (string) $value;
            }
        }
        $range = $filters['publication'] ?? [];
        if (is_array($range) && array_filter($range) && ! Validator::make($range, ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from'])->fails()) {
            $result['filters']['publication'] = array_intersect_key($range, array_flip(['from', 'to']));
        }

        return $result;
    }

    public function backUrl(): string
    {
        $url = parse_url($this->returnTo);
        if (strlen($this->returnTo) > 4096 || preg_match('/[\\\\\x00-\x1f\x7f]/', $this->returnTo)
            || ! is_array($url) || isset($url['scheme']) || isset($url['host']) || ($url['path'] ?? '') !== '/admin/articles') {
            return '/admin/articles';
        }
        parse_str($url['query'] ?? '', $query);
        $query = self::listQuery($query);

        return '/admin/articles'.($query ? '?'.http_build_query($query) : '');
    }

    public function listUrl(): string
    {
        $query = self::listQuery(['filters' => $this->tableFilters, 'sort' => $this->tableSort,
            'perPage' => $this->tableRecordsPerPage, 'page' => $this->getPage($this->getTablePaginationPageName())]);

        return '/admin/articles'.($query ? '?'.http_build_query($query) : '');
    }

    public function getTableRecordsPerPage(): int|string|null
    {
        return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 10;
    }

    private function saveHint(string $field): SchemaView
    {
        return SchemaView::make('filament.article-field-save-hint')->viewData(['field' => $field]);
    }

    public function form(Schema $schema): Schema
    {
        app()->setLocale('ru');

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Group::make([
                    Section::make('Содержание')->schema([
                        TextInput::make('title')->label('Заголовок H1')->required()->maxLength(255)->belowContent($this->saveHint('title')),
                        Textarea::make('excerpt')->label('Вступление / краткое описание')->rows(3)->helperText('Два-три предложения о содержимом статьи. В образце этот текст стоит перед обложкой и используется в карточках. Основной текст начинайте с первого раздела.')->belowContent($this->saveHint('excerpt')),
                        Toggle::make('sourceMode')->label('Редактировать HTML')->live()
                            ->extraFieldWrapperAttributes(['x-bind:inert' => 'busy || fullBusy'])
                            ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                                app(ArticleEditor::class)->actor();
                                if ($state) {
                                    if (app(ArticleHtml::class)->hasPendingImages($get('body'))) {
                                        $set('sourceMode', false);
                                        $this->addError('data.body', 'Сначала сохраните статью, чтобы перейти к HTML.');

                                        return;
                                    }
                                    $set('body', app(ArticleHtml::class)->fromEditorState($get('body')));
                                } elseif (is_string($this->data['body'] ?? null)) {
                                    $set('body', app(ArticleHtml::class)->sanitize($this->data['body']));
                                }
                            })
                            ->helperText('Для импортированных статей включён исходный HTML. Визуальный режим поддерживает изображения, подписи, таблицы и видео Kinescope.'),
                        Group::make()->schema(fn (Get $get) => [
                            $get('sourceMode')
                                ? Textarea::make('body')->label('Содержание HTML')->helperText('Поддерживаются H2–H6, таблицы, sub/sup, изображения и видео Kinescope. Опасная разметка удаляется при сохранении.')->required()->validationMessages(['required' => 'Заполните содержание статьи.'])->rows(20)->belowContent($this->saveHint('body'))->extraFieldWrapperAttributes(['x-bind:inert' => 'coverBusy'])
                                : ArticleRichEditor::make('body')->label('Содержание')->required()->validationMessages(['required' => 'Заполните содержание статьи.'])->belowContent($this->saveHint('body'))->extraFieldWrapperAttributes(['x-bind:inert' => 'coverBusy'])
                                    ->plugins([new ArticleRichContentPlugin])->extraAttributes(['class' => 'article-editor-document'])
                                    ->stickyToolbar()->stickyOffset('var(--topbar-height, 3.75rem)')
                                    ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('article-media')->fileAttachmentsVisibility('public')
                                    ->saveUploadedFileAttachmentUsing(fn (TemporaryUploadedFile $file) => app(ArticleMedia::class)->upload($file))
                                    ->getFileAttachmentUrlUsing(fn (string $file) => '/storage/'.$file)
                                    ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'])->fileAttachmentsMaxSize(20480)
                                    ->toolbarButtons([['bold', 'italic', 'strike', 'subscript', 'superscript', 'link'], ['h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'], ['bulletList', 'orderedList', 'table'], ['attachFiles', 'articleImagePair', 'articleCaption', 'articleVideo', 'undo', 'redo']]),
                        ]),
                    ]),
                    Section::make('SEO')->schema([
                        TextInput::make('seo_title')->label('SEO title')->maxLength(255)->belowContent($this->saveHint('seo_title')),
                        Textarea::make('seo_description')->label('SEO description')->maxLength(500)->belowContent($this->saveHint('seo_description')),
                    ]),
                    Section::make('Рекомендации')->description('Выбранные материалы показываются первыми. Остальные места заполняются автоматически. Изменения сохраняются кнопкой «Сохранить».')->schema([
                        $this->recommendationSelect('article'),
                        $this->recommendationSelect('faq'),
                    ]),
                ])->columnSpan(['default' => 1, 'lg' => 2]),
                Group::make([
                    Section::make('Публикация')->schema([
                        Select::make('status')->label('Статус')->placeholder('Выберите статус')->options(self::statuses())->required(),
                        DateTimePicker::make('published_at')->label('Дата публикации')->timezone('Europe/Moscow')->seconds(false),
                        TextInput::make('slug')->label('Адрес')->helperText('Генерируется из заголовка при создании. После сохранения адрес постоянный.')->belowContent($this->saveHint('slug')),
                    ])->description(fn () => $this->entryId ? 'Выбранные параметры сохраняются автоматически.' : 'Параметры сохранятся после первого нажатия «Сохранить».'),
                    Section::make('Параметры статьи')->schema([
                        Select::make('subject_id')->label('Предмет')->placeholder('Без предмета')->options(fn () => Subject::query()->where(fn ($q) => $q->active()->orWhere('id', $this->entryId ? ContentEntry::find($this->entryId)?->subject_id : 0))->orderBy('sort_order')->orderBy('id')->get()->mapWithKeys(fn ($subject) => [$subject->id => $subject->title.($subject->is_active ? '' : ' (неактивен)')])->all())->nullable(),
                        Select::make('content_author_id')->label('Автор')->placeholder('Без автора')->options(fn () => ContentAuthor::query()->pluck('name', 'id'))->nullable()
                            ->hintAction(fn () => $this->getAction('author')),
                        $this->mediaUpload('cover_path')->label('Обложка'),
                        TextInput::make('cover_alt')->label('Описание обложки')->belowContent($this->saveHint('cover_alt')),
                    ]),
                ])->columnSpan(1),
            ]),
        ])->statePath('data');
    }

    private function recommendationContext(Get $get): ContentEntry
    {
        $context = new ContentEntry([
            'subject_id' => filled($get('subject_id')) ? (int) $get('subject_id') : null,
            'content_author_id' => filled($get('content_author_id')) ? (int) $get('content_author_id') : null,
        ]);
        $context->id = $this->entryId;

        return $context;
    }

    private function recommendationSelect(string $type): Select
    {
        $field = 'recommendation_'.$type.'_ids';

        return Select::make($field)->label($type === 'article' ? 'Похожие статьи' : 'Вопросы и ответы')
            ->multiple()->reorderable()->maxItems($type === 'article' ? 3 : 2)->searchable()->default([])
            ->options(fn (Get $get) => app(ArticleRecommendations::class)->options($this->recommendationContext($get), $type, ''))
            ->getSearchResultsUsing(fn (string $search, Get $get) => app(ArticleRecommendations::class)->options($this->recommendationContext($get), $type, $search))
            ->getOptionLabelsUsing(fn (array $values, Get $get) => app(ArticleRecommendations::class)->labels($this->recommendationContext($get), $type, $values))
            ->belowContent($this->saveHint($field));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('newEntry')->label('Новая статья')->visible(fn () => ! $this->editorOpen)
            ->url(fn () => static::getUrl(['articleId' => 'create', 'returnTo' => $this->listUrl()], isAbsolute: false))];
    }

    public function backAction(): Action
    {
        return Action::make('back')->label('Назад к списку')->color('gray')->visible(fn () => $this->editorOpen)
            ->modal(fn () => $this->hasUnsavedArticleChanges())
            ->requiresConfirmation(fn () => $this->hasUnsavedArticleChanges())
            ->modalHeading('Сохранить изменения перед выходом?')
            ->modalDescription('Можно сохранить статью и вернуться к списку или выйти без сохранения.')
            ->modalSubmitActionLabel('Сохранить и выйти')
            ->modalSubmitAction(fn (Action $action) => $action->color('primary'))
            ->extraModalFooterActions(fn (Action $action) => [
                $action->makeModalSubmitAction('discard', arguments: ['discard' => true])->label('Выйти без сохранения'),
            ])
            ->modalCancelActionLabel('Остаться в редакторе')
            ->action(function (array $arguments): void {
                if ($this->hasUnsavedArticleChanges() && ! ($arguments['discard'] ?? false)) {
                    try {
                        $this->saveEntry();
                    } catch (ValidationException $exception) {
                        $this->unmountAction();

                        throw $exception;
                    }
                }

                $this->redirect($this->backUrl());
            });
    }

    private function hasUnsavedArticleChanges(): bool
    {
        return $this->savedDataHash !== md5((string) str(json_encode($this->data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')->label('Удалить')->color('danger')->button()->icon('heroicon-o-trash')
            ->extraAttributes(['data-article-action' => 'delete'])
            ->visible(fn () => $this->editorOpen && $this->entryId !== null
                && Rbac::hasPermission(auth('web')->user()->fresh(), 'articles.delete'))->requiresConfirmation()
            ->modalHeading(fn () => 'Удалить статью №'.$this->entryId.' навсегда?')
            ->modalDescription('Статья будет удалена без возможности восстановления. Её неиспользуемые медиафайлы и все их версии будут очищены. Файлы, используемые другими материалами или авторами, сохранятся.')
            ->modalSubmitActionLabel('Удалить статью')->modalCancelActionLabel('Отмена')
            ->action(function (): void {
                app(ArticleEditor::class)->actor('articles.delete');
                app(ArticleEditor::class)->delete($this->entryId, $this->revision);
                Notification::make()->title('Статья удалена')->body('Очистка неиспользуемых медиафайлов поставлена в очередь. При сбое хранилища она будет повторена.')->success()->send();
                $this->redirect($this->backUrl());
            });

    }

    public function authorAction(): Action
    {
        return Action::make('author')->label('Авторы')->color('gray')->extraAttributes(['data-article-action' => 'author'])->visible(fn () => $this->editorOpen)->schema([
            SchemaView::make('filament.field-saving.status')->visibleJs('message || guard'),
            Select::make('id')->label('Изменить существующего')->options(fn () => ContentAuthor::query()->pluck('name', 'id'))
                ->extraInputAttributes(['x-on:change.capture' => 'switchRecord($event)']),
            TextInput::make('name')->label('Публичное имя')->required()
                ->belowContent($this->authorSaveHint('name')),
            TextInput::make('slug')->label('Адрес')->required()->belowContent($this->authorSaveHint('slug')),
            Textarea::make('bio')->label('Об авторе')->belowContent($this->authorSaveHint('bio')),
            $this->mediaUpload('photo_path')->label('Фото'),
            $this->mediaUpload('avatar_path')->label('Аватар'),
            TextInput::make('home_sort_order')->label('Порядок на главной')->numeric()->minValue(1)->maxValue(65535),
            Textarea::make('home_role_text')->label('Подпись на главной'),
            Select::make('home_cutout_key')->label('Иллюстрация на главной')->nullable()->options(array_combine(
                ['demo-author-6', 'demo-author-12', 'demo-author-14', 'demo-author-11', 'demo-author-5', 'demo-author-13', 'demo-author-3', 'demo-author-7'],
                ['demo-author-6', 'demo-author-12', 'demo-author-14', 'demo-author-11', 'demo-author-5', 'demo-author-13', 'demo-author-3', 'demo-author-7'])),
            Hidden::make('expectedHash'),
        ])->mountUsing(function (Schema $schema, array $arguments): void {
            app(ArticleEditor::class)->actor();
            $id = Validator::make($arguments, ['authorId' => ['nullable', 'integer', 'min:1']])->validate()['authorId'] ?? null;
            $author = filled($id) ? ContentAuthor::findOrFail($id) : null;
            $this->authorId = $author?->id;
            $this->authorHash = $author ? hash('sha256', json_encode($author->getAttributes())) : null;
            $this->authorOpening = (string) Str::uuid();
            $schema->fill(['id' => $this->authorId, ...($author?->only(['name', 'slug', 'bio', 'photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key']) ?? []), 'expectedHash' => $this->authorHash]);
            $raw = $schema->getRawState();
            $this->authorSaved = $raw instanceof Arrayable ? $raw->toArray() : $raw;
        })->extraModalWindowAttributes(fn (Action $action) => [
            'x-data' => 'adminFieldSaving($wire, '.Js::from([
                'statePath' => 'mountedActions.'.$action->getNestingIndex().'.data',
                'baseline' => $this->authorSaved,
                'opening' => $this->authorOpening,
                'openingPath' => 'authorOpening',
                'revision' => $this->authorHash,
                'existing' => $this->authorId !== null,
                'parameters' => ['photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key'],
                'manual' => ['name', 'slug', 'bio'],
                'saveMethod' => 'saveAuthorParameters',
                'submitMethod' => 'callMountedAction',
                'conflictField' => 'expectedHash',
                'recordId' => $this->authorId,
                'switchAction' => 'author',
                'switchArgument' => 'authorId',
                'uploads' => true,
            ])->toHtml().')',
            'x-on:submit.capture' => 'submit($event)',
            'data-ui' => 'author-editor',
        ])->modalCancelAction(fn (Action $action) => $action->extraAttributes([
            'x-on:click.capture' => '$event.preventDefault(); $event.stopImmediatePropagation(); requestLeave(() => { if (!disposed) close() })',
        ]))->beforeFormValidated(function (): void {
            $raw = $this->getMountedActionSchema()->getRawState();
            $raw = $raw instanceof Arrayable ? $raw->toArray() : $raw;
            if (array_key_exists('user_id', $raw)) {
                throw ValidationException::withMessages($this->authorErrors(
                    ValidationException::withMessages(['user_id' => 'Автор задаётся отдельно от учётной записи.'])
                ));
            }
        })->action(function (array $data): array {
            try {
                app(ArticleEditor::class)->saveTaxonomy('author', $this->authorId, $data, $this->authorHash);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages($this->authorErrors($exception));
            }

            return ['ok' => true];
        });
    }

    private function authorSaveHint(string $field): SchemaView
    {
        return SchemaView::make('filament.field-saving.hint')->viewData(['field' => $field]);
    }

    private function authorErrors(ValidationException $exception): array
    {
        $path = $this->getMountedActionSchema()->getStatePath();
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[$path.'.'.($field === 'cover_path' ? 'photo_path' : $field)] = $messages;
        }

        return $errors;
    }

    public function saveAuthorParameters(array $patch, string $expectedHash, string $opening): array
    {
        app(ArticleEditor::class)->actor();
        abort_unless($opening === $this->authorOpening && $this->authorId !== null && $this->getMountedAction()?->getName() === 'author', 409);
        abort_if(array_diff(array_keys($patch), ['photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key']), 422);
        $raw = $patch;
        try {
            foreach (['photo_path', 'avatar_path'] as $field) {
                if (! array_key_exists($field, $patch)) {
                    continue;
                }
                $component = $this->getMountedActionSchema()->getComponentByStatePath($field);
                $component->saveUploadedFiles();
                $patch[$field] = $component->getState();
                $raw[$field] = $component->getRawState();
            }
            $author = app(ArticleEditor::class)->saveAuthorParameters($this->authorId, $patch, $expectedHash);
        } catch (ValidationException $exception) {
            $errors = $this->authorErrors($exception);
            foreach ($errors as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return ['ok' => false, 'terminal' => isset($exception->errors()['expectedHash']), 'errors' => $errors,
                'message' => collect($exception->errors())->flatten()->first()];
        }
        foreach ($raw as $field => $value) {
            $this->authorSaved[$field] = $value;
            $this->resetValidation($this->getMountedActionSchema()->getStatePath().'.'.$field);
        }
        $this->authorHash = hash('sha256', json_encode($author->getAttributes()));

        return ['ok' => true, 'revision' => $this->authorHash, 'fields' => $raw];
    }

    private function mediaUpload(string $name): FileUpload
    {
        return FileUpload::make($name)->disk('public')->directory('article-media')->image()
            ->placeholder('Перетащите изображение или нажмите, чтобы выбрать файл')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'])->maxSize(20480)
            ->fetchFileInformation(false)
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => app(ArticleMedia::class)->upload($file))
            ->getUploadedFileUsing(function (string $file): ?array {
                app(ArticleEditor::class)->actor();
                $asset = ArticleMediaAsset::query()->where('filename', basename($file))->first();

                return $asset ? ['name' => $asset->filename, 'size' => $asset->bytes, 'type' => $asset->mime, 'url' => app(ArticleMedia::class)->publicUrl('article-media/'.$asset->filename)] : null;
            });
    }
}
