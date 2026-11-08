<?php

namespace App\Filament\Pages\Operations;

use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportMediaDeletion;
use App\Models\User;
use App\Services\Support\SupportOperations;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SupportTicketDetail extends Page
{
    protected static ?string $slug = 'support/{ticketId}';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Обращение';

    protected string $view = 'filament.operations.support-detail';

    #[Locked]
    public int $ticketId;

    #[Locked]
    public int $revision;

    #[Locked]
    public string $replyKey;

    #[Locked]
    public string $changeKey;

    #[Locked]
    public string $retryKey;

    #[Locked]
    public string $closeKey;

    public string $replyBody = '';

    public string $status = '';

    public string $contactEmail = '';

    #[Locked]
    public string $savingOpening = '';

    #[Locked]
    public array $savingBaseline = [];

    #[Locked]
    public array $listContext = [];

    public static function canAccess(): bool
    {
        return SupportTickets::canAccess();
    }

    private function actor(bool $write = false): User
    {
        abort_unless(self::canAccess(), 403);

        return app(SupportOperations::class)->authorize(auth('web')->user(), $write);
    }

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $context = request()->query('list', []);
        $this->listContext = self::sanitizeListContext(is_array($context) ? $context : []);
        $this->refreshHistory();
    }

    public static function sanitizeListContext(array $input): array
    {
        $context = [];
        foreach (SupportTickets::COLUMN_FILTERS as $name => $columns) {
            foreach ($name === 'created_at' ? ['from', 'to'] : ['value'] as $field) {
                $value = $input['filters'][$name][$field] ?? null;
                if (is_string($value) && $value !== '') {
                    $context['filters'][$name][$field] = mb_substr($value, 0, 200);
                }
            }
        }
        $sort = $input['sort'] ?? null;
        if (is_string($sort) && preg_match('/\A(?:id|created_at|last_message_at):(asc|desc)\z/', $sort)) {
            $context['sort'] = $sort;
        }
        $page = filter_var($input['page'] ?? null, FILTER_VALIDATE_INT);
        if ($page !== false && $page >= 1 && $page <= 1000000) {
            $context['page'] = $page;
        }

        return $context;
    }

    public function getBreadcrumbs(): array
    {
        return [SupportTickets::getUrl($this->listContext) => 'Поддержка', 'Обращение'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('closeTicket')->label('Закрыть тикет')->color('danger')
                ->visible(fn () => Rbac::hasPermission(auth('web')->user(), 'support.write') && ! ImpersonationContext::isActive(request())
                    && (AdminSupportTicket::findOrFail($this->ticketId)->status !== 'closed'
                        || (DatabaseSchema::hasTable('support_media_deletions') && ! SupportMediaDeletion::where('ticket_id', $this->ticketId)->exists())))
                ->disabled(fn () => ! DatabaseSchema::hasTable('support_media_deletions'))
                ->requiresConfirmation()->modalHeading('Закрыть тикет?')
                ->modalDescription('Изображения, голосовые сообщения и сохранённые приложением оригиналы писем будут удалены без возможности восстановления. Текст переписки и история действий сохранятся.')
                ->modalSubmitActionLabel('Закрыть и удалить медиа')->modalCancelActionLabel('Отмена')
                ->action(fn () => $this->closeTicket()),
            Action::make('retryDelivery')->label('Повторить отправку')
                ->visible(fn () => AdminSupportTicket::findOrFail($this->ticketId)->messages()->whereIn('delivery_status', ['failed', 'delivery_unknown'])->exists())
                ->requiresConfirmation()
                ->modalSubmitActionLabel('Повторить отправку')->modalCancelActionLabel('Отмена')
                ->modalDescription('При неизвестном исходе SMTP получатель мог уже получить письмо. Повтор может создать ещё одну физическую копию.')
                ->schema([
                    Select::make('message_id')->label('Письмо')->required()
                        ->options(fn () => AdminSupportTicket::findOrFail($this->ticketId)->messages()->whereIn('delivery_status', ['failed', 'delivery_unknown'])->get()->mapWithKeys(fn ($message) => [
                            $message->id => '#'.$message->id.' · '.($message->delivery_status === 'failed' ? 'Не удалось отправить' : 'Результат отправки неизвестен').($message->error_code ? ' · '.$message->error_code : ''),
                        ])->all()),
                ])
                ->action(fn (array $data) => $this->retryDelivery((int) $data['message_id'])),
        ];
    }

    public function replyForm(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('replyBody')->id('support-reply')->label('Ответ пользователю')->required()->rows(6)->maxLength(20000),
            Actions::make([Action::make('sendReply')->label('Отправить ответ')->submit('replyForm')->formId('support-reply-form')->action(fn () => $this->sendReply())])->key('replyActions'),
        ]);
    }

    public function detailsForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->label('Статус')->options(SupportOperations::STATUSES)
                ->disableOptionWhen(fn (string $value): bool => $value === 'closed')->required()->live(),
            TextInput::make('contactEmail')->label('Контактный email')->email()->maxLength(254)
                ->belowContent(view('filament.field-saving.hint', ['field' => 'contactEmail'])),
            Actions::make([Action::make('saveDetails')->label('Сохранить')->submit('detailsForm')->formId('support-details-form')->action(fn () => $this->saveDetails())])->key('detailsActions'),
        ]);
    }

    public function hydrate(): void
    {
        $this->actor();
    }

    public function refreshHistory(): void
    {
        $this->actor();
        $ticket = AdminSupportTicket::findOrFail($this->ticketId);
        $this->revision = $ticket->revision;
        $this->status = $ticket->status;
        $this->contactEmail = $ticket->contact_email ?? '';
        $this->replyKey = (string) Str::uuid();
        $this->changeKey = (string) Str::uuid();
        $this->retryKey = (string) Str::uuid();
        $this->closeKey = (string) Str::uuid();
        $this->savingOpening = (string) Str::uuid();
        $this->savingBaseline = ['status' => $this->status, 'contactEmail' => $this->contactEmail, 'replyBody' => ''];
        $this->resetErrorBag();
    }

    public function discardAndRefreshHistory(): void
    {
        $this->replyBody = '';
        $this->refreshHistory();
    }

    public function savingConfig(): array
    {
        return ['statePath' => '', 'baseline' => $this->savingBaseline, 'revision' => $this->revision,
            'opening' => $this->savingOpening, 'openingPath' => 'savingOpening', 'existing' => true, 'standalone' => true,
            'parameters' => ['status'], 'manual' => ['contactEmail'], 'commands' => ['replyBody'],
            'saveMethod' => 'saveSupportParameters', 'submitMethod' => 'saveDetails', 'submitMethods' => ['sendReply'],
            'commandActionMethod' => 'callMountedAction', 'guardForm' => 'support-details-form'];
    }

    private function ack(array $fields, array $normalized = []): array
    {
        $this->savingBaseline = array_replace($this->savingBaseline, $fields, $normalized);

        return ['ok' => true, 'revision' => $this->revision, 'fields' => $fields, 'normalized' => $normalized];
    }

    public function saveSupportParameters(array $patch, int $revision, string $opening): array
    {
        $actor = $this->actor(true);
        abort_unless($opening === $this->savingOpening, 409);
        try {
            $ticket = app(SupportOperations::class)->parameters($actor, $this->ticketId, $patch, $revision, $this->changeKey);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return ['ok' => false, 'errors' => $exception->errors()];
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->addError('operation', $exception->getMessage());

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        $this->revision = $ticket->revision;
        $this->changeKey = (string) Str::uuid();
        foreach (array_keys($patch) as $field) {
            $this->resetValidation($field);
        }

        return $this->ack($patch);
    }

    public function sendReply(): array
    {
        $actor = $this->actor(true);
        $this->validate(['replyBody' => ['required', 'string', 'max:20000']]);
        $result = $this->perform(fn () => app(SupportOperations::class)->reply($actor, $this->ticketId, $this->replyBody, $this->revision, $this->replyKey));
        if (is_array($result)) {
            return $result;
        }
        $this->revision = $result->ticket_revision;
        $this->replyBody = '';
        $this->replyKey = (string) Str::uuid();

        return $this->ack(['replyBody' => ''], ['replyBody' => '']);
    }

    public function saveDetails(): array
    {
        $actor = $this->actor(true);
        $this->validate(['status' => ['required', 'in:'.implode(',', array_keys(SupportOperations::STATUSES))], 'contactEmail' => ['nullable', 'email:rfc', 'max:254']]);
        $fields = ['status' => $this->status, 'contactEmail' => $this->contactEmail];
        $result = $this->perform(fn () => app(SupportOperations::class)->change($actor, $this->ticketId, [
            'revision' => $this->revision,
            ...($this->status === 'closed' && AdminSupportTicket::findOrFail($this->ticketId)->status === 'closed' ? [] : ['status' => $this->status]),
            ...($this->contactEmail !== '' ? ['contact_email' => $this->contactEmail] : []),
        ], $this->changeKey));
        if (is_array($result)) {
            return $result;
        }
        $this->revision = $result->revision;
        $this->changeKey = (string) Str::uuid();

        return $this->ack($fields, ['contactEmail' => $result->contact_email ?? '']);
    }

    public function closeTicket(): array
    {
        $actor = $this->actor(true);
        $result = $this->perform(fn () => app(SupportOperations::class)->close($actor, $this->ticketId, $this->revision, $this->closeKey));
        if (is_array($result)) {
            return $result;
        }
        $this->revision = $result->revision;
        $this->closeKey = (string) Str::uuid();

        return $this->ack(['status' => 'closed'], ['status' => 'closed']);
    }

    public function retryDelivery(int $messageId): array
    {
        $actor = $this->actor(true);
        abort_unless(AdminSupportTicket::findOrFail($this->ticketId)->messages()->whereKey($messageId)->exists(), 404);
        $result = $this->perform(fn () => app(SupportOperations::class)->retry($actor, $messageId, $this->retryKey));
        if (is_array($result)) {
            return $result;
        }
        $this->retryKey = (string) Str::uuid();

        return $this->ack([]);
    }

    private function perform(callable $operation): mixed
    {
        try {
            $result = $operation();
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422, 503], true)) {
                throw $exception;
            }
            $this->addError('operation', $exception->getMessage());

            return ['ok' => false, 'validation' => $exception->getStatusCode() === 422,
                'terminal' => $exception->getStatusCode() !== 422, 'message' => $exception->getMessage()];
        }
        Notification::make()->title('Сохранено')->success()->send();

        return $result ?? true;
    }

    protected function getViewData(): array
    {
        $this->actor();

        return [
            'ticket' => AdminSupportTicket::with('messages.attachments')->findOrFail($this->ticketId),
            'events' => AdminOperationEvent::where('resource', 'AdminSupportTicket')->where('resource_id', $this->ticketId)->orderBy('id')->get(),
            'cleanups' => DatabaseSchema::hasTable('support_media_deletions') ? SupportMediaDeletion::where('ticket_id', $this->ticketId)->orderBy('id')->get()->keyBy('id') : collect(),
        ];
    }
}
