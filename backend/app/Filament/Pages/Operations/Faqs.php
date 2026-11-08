<?php

namespace App\Filament\Pages\Operations;

use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\Subject;
use App\Services\Faq\AdminFaqService;
use App\Support\Admin\ColumnQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Faqs extends OperationsPage
{
    protected static ?string $slug = 'faqs';

    protected static ?string $title = 'FAQ';

    protected static ?string $navigationLabel = 'FAQ';

    protected static string $readPermission = 'faq.read';

    public const COLUMN_FILTERS = ['title' => ['title'], 'body' => ['body'], 'status' => ['status']];

    public const COLUMN_SEARCHES = ['title' => 'title', 'body' => 'body'];

    public const CHOICE_FILTERS = ['status' => 'status'];

    #[Locked]
    public ?int $entryId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public string $faqOpening = '';

    #[Locked]
    public array $faqSaved = [];

    private function fields(): array
    {
        return [
            SchemaView::make('filament.field-saving.status')->visibleJs('message || guard'),
            TextInput::make('title')->label('Вопрос')->required()->maxLength(255)->belowContent($this->saveHint('title')),
            Textarea::make('body')->label('Ответ')->required()->maxLength(20000)->rows(8)->belowContent($this->saveHint('body')),
            Select::make('subject_id')->label('Предмет')->placeholder('Без предмета')->nullable()->options(fn () => Subject::query()->where(fn ($q) => $q->active()->orWhere('id', $this->entryId ? ContentEntry::find($this->entryId)?->subject_id : 0))->orderBy('sort_order')->orderBy('id')->get()->mapWithKeys(fn ($subject) => [$subject->id => $subject->title.($subject->is_active ? '' : ' (неактивен)')])->all()),
            Toggle::make('show_on_home')->label('Показывать на главной')->default(false),
            TextInput::make('sort_order')->label('Порядок')->required()->integer()->minValue(0)->maxValue(2147483647)->default(0)->belowContent($this->saveHint('sort_order')),
        ];
    }

    private function saveHint(string $field): SchemaView
    {
        return SchemaView::make('filament.field-saving.hint')->viewData(['field' => $field]);
    }

    private function guardedCancelAction(Action $action): Action
    {
        return $action->extraAttributes([
            'x-on:click.capture' => '$event.preventDefault(); $event.stopImmediatePropagation(); requestLeave(() => { if (!disposed) close() })',
        ]);
    }

    private function savingAttributes(Action $action): array
    {
        $config = [
            'statePath' => 'mountedActions.'.$action->getNestingIndex().'.data',
            'baseline' => $this->faqSaved,
            'opening' => $this->faqOpening,
            'openingPath' => 'faqOpening',
            'revision' => $this->revision,
            'existing' => $this->entryId !== null,
            'parameters' => ['subject_id', 'show_on_home'],
            'manual' => ['title', 'body', 'sort_order'],
            'saveMethod' => 'saveFaqParameters',
            'submitMethod' => 'callMountedAction',
        ];

        return [
            'x-data' => 'adminFieldSaving($wire, '.Js::from($config)->toHtml().')',
            'x-on:submit.capture' => 'submit($event)',
            'data-ui' => 'faq-editor',
        ];
    }

    private function beginFaq(?Schema $schema, ?ContentEntry $record = null): void
    {
        $entry = $record ? app(AdminFaqService::class)->detail($this->actor(), $record->id) : null;
        $this->entryId = $entry?->id;
        $this->revision = $entry?->revision;
        $this->beginOperation($schema, $entry ? [
            ...$entry->only(['title', 'body', 'sort_order', 'subject_id']),
            'show_on_home' => $entry->placements->contains(fn ($placement) => $placement->placement === ContentPlacement::HOME_FAQ && $placement->is_enabled),
        ] : ['show_on_home' => false, 'sort_order' => 0]);
        $this->faqOpening = (string) Str::uuid();
        $raw = $schema?->getRawState() ?? [];
        $this->faqSaved = $raw instanceof Arrayable ? $raw->toArray() : $raw;
    }

    public function saveFaqParameters(array $patch, int $revision, string $opening): array
    {
        $actor = $this->actor('faq.write');
        abort_unless($opening === $this->faqOpening && $this->entryId !== null
            && $this->getMountedAction()?->getName() === 'edit', 409);
        $statePath = $this->getMountedActionSchema()->getStatePath();
        try {
            $entry = app(AdminFaqService::class)->execute($actor, 'parameters', $patch,
                $this->operationKey, $this->entryId, $revision);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$statePath.'.'.$field] = $messages;
                $this->addError($statePath.'.'.$field, $messages[0]);
            }

            return ['ok' => false, 'errors' => $errors];
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        foreach ($patch as $field => $value) {
            $this->faqSaved[$field] = $value;
            $this->resetValidation($statePath.'.'.$field);
        }
        $this->revision = $entry->revision;
        $this->operationKey = (string) Str::uuid();

        return ['ok' => true, 'revision' => $entry->revision, 'fields' => $patch];
    }

    private function runFaq(string $operation, array $data = []): array
    {
        $this->completeOperation(fn () => app(AdminFaqService::class)->execute(
            $this->actor(in_array($operation, ['publish', 'hide'], true) ? 'faq.publish' : 'faq.write'),
            $operation, $data, $this->operationKey, $this->entryId, $this->revision,
        ));

        return ['ok' => true];
    }

    public function table(Table $table): Table
    {
        return $table->query(function () {
            $query = app(AdminFaqService::class)->query($this->actor());
            foreach (['title', 'body'] as $field) {
                ColumnQuery::search($query, [$field], $this->tableFilters[$field]['value'] ?? null);
            }

            return $query;
        })
            ->defaultSort('sort_order')->emptyStateHeading('Вопросы не найдены')->emptyStateDescription(null)
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('title')->label('Вопрос')->wrap(),
                TextColumn::make('body')->label('Ответ')->limit(120)->wrap(),
                TextColumn::make('status')->label('Статус')->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state),
                TextColumn::make('show_on_home')->label('Главная')->state(fn (ContentEntry $record) => $record->placements->contains(fn ($p) => $p->placement === ContentPlacement::HOME_FAQ && $p->is_enabled) ? 'Да' : 'Нет'),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->filters([
                self::textFilter('title', 'Вопрос'), self::textFilter('body', 'Ответ'),
                SelectFilter::make('status')->label('Статус')->options(self::statuses()),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->headerActions([
                Action::make('create')->label('Создать')->schema($this->fields())
                    ->extraModalWindowAttributes(fn (Action $action) => $this->savingAttributes($action))
                    ->modalCancelAction(fn (Action $action) => $this->guardedCancelAction($action))
                    ->beforeFormValidated(fn () => $this->assertFields(['title', 'body', 'show_on_home', 'sort_order', 'subject_id']))
                    ->mountUsing(fn (?Schema $schema) => $this->beginFaq($schema))
                    ->action(fn (array $data) => $this->runFaq('create', $data)),
            ])
            ->recordActions([
                Action::make('edit')->label('Изменить')->schema($this->fields())
                    ->extraModalWindowAttributes(fn (Action $action) => $this->savingAttributes($action))
                    ->modalCancelAction(fn (Action $action) => $this->guardedCancelAction($action))
                    ->beforeFormValidated(fn () => $this->assertFields(['title', 'body', 'show_on_home', 'sort_order', 'subject_id']))
                    ->mountUsing(fn (?Schema $schema, ContentEntry $record) => $this->beginFaq($schema, $record))
                    ->action(fn (array $data) => $this->runFaq('update', $data)),
                Action::make('publish')->label('Опубликовать')->requiresConfirmation()
                    ->visible(fn (ContentEntry $record) => in_array($record->status, [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_HIDDEN], true))
                    ->mountUsing(fn (?Schema $schema, ContentEntry $record) => $this->beginFaq($schema, $record))
                    ->action(fn () => $this->runFaq('publish')),
                Action::make('hide')->label('Скрыть')->requiresConfirmation()
                    ->visible(fn (ContentEntry $record) => $record->status === ContentEntry::STATUS_PUBLISHED)
                    ->mountUsing(fn (?Schema $schema, ContentEntry $record) => $this->beginFaq($schema, $record))
                    ->action(fn () => $this->runFaq('hide')),
                Action::make('delete')->label('Удалить')->color('danger')->requiresConfirmation()
                    ->modalHeading(fn (ContentEntry $record) => 'Удалить вопрос «'.$record->title.'»?')
                    ->modalDescription('Вопрос и его размещения будут безвозвратно удалены. Восстановить их нельзя.')
                    ->modalSubmitActionLabel('Удалить навсегда')
                    ->mountUsing(fn (?Schema $schema, ContentEntry $record) => $this->beginFaq($schema, $record))
                    ->action(fn () => $this->runFaq('delete')),
            ]);
    }

    private static function statuses(): array
    {
        return ['draft' => 'Черновик', 'published' => 'Опубликовано', 'hidden' => 'Скрыто', 'archived' => 'В архиве'];
    }
}
