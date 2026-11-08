<?php

namespace App\Filament\Pages\Operations;

use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use App\Support\Admin\HasColumnControls;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class OperationsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use HasColumnControls;

    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public const COLUMN_FILTERS = [];

    public const CHOICE_FILTERS = [];

    public const COLUMN_SEARCHES = [];
    public const QUERY_SEARCHES = [];
    public const QUERY_DATES = [];
    public const QUERY_CHOICES = [];
    public const QUERY_SORTS = ['id' => 'id'];
    public const QUERY_DEFAULT_SORT = 'id:desc';

    protected string $view = 'filament.operations.table';

    protected static string $readPermission;

    #[Locked]
    public string $operationKey = '';

    public static function canAccess(): bool
    {
        $actor = auth('web')->user();
        $actor = $actor instanceof User ? $actor->fresh() : null;

        return $actor instanceof User && ! $actor->blocksAuthenticationForMigration()
            && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, static::$readPermission);
    }

    protected function actor(?string $writePermission = null): User
    {
        abort_unless(static::canAccess(), 403);
        $actor = auth('web')->user()->fresh();
        if ($writePermission !== null) {
            abort_unless(Rbac::hasPermission($actor, $writePermission), 403);
            abort_if(ImpersonationContext::isActive(request()), 403);
        }

        return $actor;
    }

    protected function beginOperation(?Schema $schema, array $data = []): void
    {
        $this->actor();
        $this->operationKey = (string) Str::uuid();
        $schema?->fill($data);
    }

    protected function completeOperation(callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $statePath = $this->getMountedActionSchema()?->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$statePath ? $statePath.'.'.$field : $field] = $messages;
            }
            Notification::make()->title('Изменение не сохранено')
                ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
            throw ValidationException::withMessages($errors);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }
            Notification::make()->title('Изменение не сохранено')->body($exception->getMessage())->danger()->send();
            throw new Halt;
        }
        Notification::make()->title('Сохранено')->success()->send();
        $this->resetTable();
    }

    protected function assertFields(array $allowed): void
    {
        $raw = $this->getMountedActionSchema()?->getRawState() ?? [];
        $raw = $raw instanceof \Illuminate\Contracts\Support\Arrayable ? $raw->toArray() : $raw;
        if (array_diff(array_keys($raw), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Запрос содержит недопустимые поля.']);
        }
    }
}
