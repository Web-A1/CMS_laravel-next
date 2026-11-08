<?php

namespace App\Filament\Pages\Operations;

use App\Filament\Concerns\HasManualModalSaving;
use App\Models\SupportMailReceipt;
use App\Services\AdminOperationsService;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportRecovery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SupportTickets extends OperationsPage
{
    use HasManualModalSaving;

    protected static ?string $slug = 'support';

    protected static ?string $title = 'Поддержка';

    protected static ?string $navigationLabel = 'Поддержка';

    protected static string $readPermission = 'support.read';

    protected string $view = 'filament.operations.support';

    public const COLUMN_FILTERS = ['subject' => ['subject'], 'contact' => ['contact'], 'contact_email' => ['contact', 'contact_email', 'public_number'], 'public_number' => ['public_number'], 'status' => ['status'], 'priority' => ['priority'], 'created_at' => ['created_at']];

    public const COLUMN_SEARCHES = ['subject' => 'subject', 'contact_email' => 'contact'];

    public const CHOICE_FILTERS = ['status' => 'status', 'priority' => 'priority'];

    public const QUERY_SEARCHES = ['subject' => ['subject'], 'contact' => ['contact_email', 'public_number'], 'contact_email' => ['contact_email'], 'public_number' => ['public_number']];

    public const QUERY_DATES = ['created_at' => 'created_at'];

    public const QUERY_CHOICES = ['priority' => 'priority'];

    public const QUERY_SORTS = ['id' => 'id', 'created_at' => 'created_at', 'last_message_at' => 'last_message_at'];

    private function fields(): array
    {
        return [
            $this->manualSavingStatus(),
            TextInput::make('subject')->label('Тема')->required()->maxLength(180)->belowContent($this->manualSaveHint('subject')),
            Textarea::make('description')->label('Описание')->required()->maxLength(20000)->rows(6)->belowContent($this->manualSaveHint('description')),
            TextInput::make('contact_email')->label('Контактный email')->email()->maxLength(254)->belowContent($this->manualSaveHint('contact_email')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->records(function (int $page, int $recordsPerPage, ?array $filters) {
            $input = ['page' => $page, 'per_page' => $recordsPerPage];
            foreach ($filters ?? [] as $name => $filter) {
                if (($filter['value'] ?? '') !== '' && ($filter['value'] ?? null) !== null) {
                    $input[$name] = $filter['value'];
                }
            }

            return app(AdminOperationsService::class)->tickets($this->actor(), 'list', $input, configureQuery: $this->columnQuery())['data'];
        })
            ->emptyStateHeading('Обращения не найдены')->emptyStateDescription(null)
            ->recordUrl(fn (Model $record) => SupportTicketDetail::getUrl([
                'ticketId' => $record->id,
                'list' => SupportTicketDetail::sanitizeListContext(['filters' => $this->tableFilters, 'sort' => $this->tableSort, 'page' => $this->getTablePage()]),
            ]))
            ->paginationPageOptions([25, 50, 100])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('subject')->label('Обращение')->limit(80)->wrap()
                    ->description(fn (Model $record) => self::descriptionPreview($record))
                    ->tooltip(fn (Model $record) => $record->subject)
                    ->extraAttributes(['style' => 'max-width: 25rem; overflow-wrap: anywhere']),
                TextColumn::make('contact_email')->label('Email / номер')->placeholder('Email не указан')->wrap()
                    ->description(fn (Model $record) => $record->public_number ?? '#'.$record->id)
                    ->extraAttributes(['style' => 'max-width: 15rem; overflow-wrap: anywhere']),
                TextColumn::make('status')->label('Статус')
                    ->badge()->color(fn ($state) => match ($state) {
                        'new' => 'warning',
                        'in_progress' => 'info',
                        'waiting' => 'gray',
                        'closed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => SupportOperations::STATUSES[$state] ?? $state)
                    ->description(fn (Model $record) => $record->messages()->whereIn('delivery_status', ['failed', 'delivery_unknown'])->exists() ? 'Проверьте доставку' : null),
                TextColumn::make('last_message_at')->label('Обновлено')->sortable()->dateTime('d.m.Y H:i'),
            ])
            ->filters([
                self::textFilter('subject', 'Тема'),
                self::textFilter('contact', 'Email или номер'),
                self::textFilter('contact_email', 'Email'),
                self::textFilter('public_number', 'Номер'),
                SelectFilter::make('status')->label('Статус')->options(SupportOperations::STATUSES),
                SelectFilter::make('priority')->label('Приоритет')->options(['low' => 'Низкий', 'normal' => 'Обычный', 'high' => 'Высокий']),
                self::dateFilter('created_at', 'Создано'),
            ], layout: FiltersLayout::Hidden)->deferFilters(false)
            ->headerActions([
                Action::make('resolveReceipt')->label('Разобрать спорные письма')
                    ->modalSubmitActionLabel('Применить решение')->modalCancelActionLabel('Отмена')
                    ->visible(fn () => SupportMailReceipt::where('outcome', 'recovery_manual')->exists())->schema([
                        Select::make('receipt_id')->label('Письмо с неизвестным временем получения')->options(fn () => SupportMailReceipt::where('outcome', 'recovery_manual')->latest('id')->limit(100)->get()->mapWithKeys(fn ($receipt) => [$receipt->id => '#'.$receipt->id.' UID '.$receipt->uidvalidity.'/'.$receipt->uid])->all())->required(),
                        Select::make('decision')->label('Решение')->options(['accept' => 'Принять как новое', 'historical' => 'Историческое, не импортировать'])->required(),
                    ])->mountUsing(fn (?Schema $schema) => $this->beginOperation($schema))
                    ->action(fn (array $data) => $this->completeOperation(fn () => app(SupportOperations::class)->resolveReceipt($this->actor('support.write'), (int) $data['receipt_id'], $data['decision'], $this->operationKey))),
                $this->manualModalAction(Action::make('create')->label('Создать')->schema($this->fields()))
                    ->modalSubmitActionLabel('Создать обращение')->modalCancelActionLabel('Отмена')
                    ->beforeFormValidated(fn () => $this->assertFields(['subject', 'description', 'contact_email']))
                    ->mountUsing(fn (?Schema $schema) => $this->beginManualModal($schema))
                    ->action(fn (array $data) => $this->completeManualModal(fn () => app(AdminOperationsService::class)
                        ->tickets($this->actor('support.write'), 'create', $data, key: $this->operationKey))),
            ]);
    }

    private static function descriptionPreview(Model $record): ?string
    {
        $description = preg_replace('/\s+/u', ' ', trim($record->description ?? ''));
        $subject = trim($record->subject);
        if ($description === '' || $description === $subject) {
            return null;
        }
        if (str_starts_with($description, $subject)) {
            $description = trim(mb_substr($description, mb_strlen($subject)));
        }

        return $description === '' ? null : Str::limit($description, 110);
    }

    public function supportHealth(): array
    {
        $this->actor();
        $health = app(SupportRecovery::class)->health();

        return [...$health,
            'smtp_label' => $health['smtp'] === 'configured' ? 'настроена' : 'не настроена',
            'imap_label' => match ($health['imap']) {
                'not_configured' => 'не подключён',
                'disabled' => 'выключен',
                'inactive' => 'ожидает первого включения',
                'active' => 'включён',
                'recovery_required' => 'требует восстановления после сбоя',
                'connection_failed' => 'ошибка подключения',
                default => 'состояние неизвестно',
            },
        ];
    }

    public function mailProblems()
    {
        $this->actor();

        return SupportMailReceipt::whereIn('outcome', ['quarantine', 'recovery_manual'])->orWhere(fn ($query) => $query->where('outcome', 'pending')->where('intake_status', 'unclassified'))->latest('id')->limit(25)->get();
    }
}
