<?php

namespace App\Support\Admin;

use App\Services\AdminUserBulkDeletion;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait UserBulkDeletionActions
{
    #[Locked]
    public array $bulkDeletionPreview = [];

    #[Locked]
    public string $bulkDeletionKey = '';

    private function canBulkDeleteUsers(): bool
    {
        return $this->scope === 'users' && static::canAccess()
            && app()->environment(['local', 'testing', 'staging'])
            && Rbac::hasPermission($this->actor(), 'users.delete')
            && ! ImpersonationContext::isActive(request());
    }

    private function userDeletionAction(): BulkAction
    {
        return BulkAction::make('deleteSelected')->label('Удалить выбранные')->color('danger')
            ->visible(fn (): bool => $this->canBulkDeleteUsers())->requiresConfirmation()
            ->modalHeading('Удалить выбранные профили навсегда?')
            ->modalDescription('Проверьте список выбранных профилей перед удалением.')
            ->modalSubmitActionLabel('Удалить навсегда')->modalCancelActionLabel('Отмена')
            ->modalContent(fn () => view('filament.users.bulk-deletion-summary', ['preview' => $this->bulkDeletionPreview]))
            ->schema([
                TextInput::make('confirmation')->label('Введите количество выбранных профилей')
                    ->required()->maxLength(3)
                    ->rules([fn () => 'in:'.count($this->bulkDeletionPreview['ids'] ?? [])])
                    ->validationMessages(['required' => 'Введите количество профилей.', 'in' => 'Введите точное количество выбранных профилей.']),
            ])
            ->mountUsing(fn (?Schema $schema) => $this->beginUserDeletion($schema))
            ->action(fn (array $data) => $this->deleteSelectedUsers($data))
            ->deselectRecordsAfterCompletion();
    }

    private function beginUserDeletion(?Schema $schema): void
    {
        $actor = $this->writeActor('users.delete', 'delete-preview', 30);
        abort_unless($this->canBulkDeleteUsers(), 403);
        Validator::make(['selection' => $this->selectedTableRecords, 'all' => $this->isTrackingDeselectedTableRecords], [
            'selection' => ['required', 'array', 'min:1', 'max:'.AdminUserBulkDeletion::MAX_USERS],
            'selection.*' => ['required', 'integer', 'min:1', 'distinct'],
            'all' => ['declined'],
        ], ['selection.required' => 'Выберите профили.', 'all.declined' => 'Выберите профили текущей страницы.'])->validate();
        $ids = array_map('intval', $this->selectedTableRecords);
        $visible = $this->getTableRecords()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $visible) !== []) {
            throw ValidationException::withMessages(['selection' => 'Выбранные профили недоступны на текущей странице. Обновите список и выберите их заново.']);
        }
        sort($ids, SORT_NUMERIC);
        try {
            $this->bulkDeletionPreview = app(AdminUserBulkDeletion::class)->preview($actor, $ids);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [404, 409, 503], true)) {
                throw $exception;
            }
            Notification::make()->title('Профили не удалены')->body($exception->getMessage())->danger()->send();
            throw new Halt;
        } catch (QueryException $exception) {
            report($exception);
            Notification::make()->title('Удаление недоступно')
                ->body('Не удалось подготовить обзор удаления. Проверьте готовность схемы базы данных.')->danger()->send();
            throw new Halt;
        }
        $this->bulkDeletionKey = (string) Str::uuid();
        $schema?->fill();
    }

    private function deleteSelectedUsers(array $data): void
    {
        $actor = $this->writeActor('users.delete', 'bulk-delete', 10);
        abort_unless($this->canBulkDeleteUsers(), 403);
        try {
            $result = app(AdminUserBulkDeletion::class)->deleteMany(
                $actor, $this->bulkDeletionPreview['ids'] ?? [],
                $this->bulkDeletionPreview['fingerprint'] ?? '', (string) ($data['confirmation'] ?? ''), $this->bulkDeletionKey,
            );
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [404, 409, 429, 503], true)) {
                throw $exception;
            }
            Notification::make()->title('Профили не удалены')
                ->body($exception->getMessage().' Закройте подтверждение и выберите профили заново.')->danger()->send();
            throw new Halt;
        } catch (QueryException $exception) {
            report($exception);
            Notification::make()->title('Профили не удалены')
                ->body('Связанные данные не позволили завершить удаление. Вся выбранная группа сохранена.')->danger()->send();
            throw new Halt;
        }
        $this->selectedTableRecords = [];
        $this->bulkDeletionPreview = [];
        $this->bulkDeletionKey = '';
        unset($this->cachedSelectedTableRecords);
        $this->resetPage();
        Notification::make()->title('Удалено профилей: '.$result['count'])->success()->send();
    }
}
