<?php

namespace App\Support\Admin;

use App\Exceptions\AdminImpersonationException;
use App\Http\Requests\AdminTestUserDeletionRequest;
use App\Models\User;
use App\Services\AdminImpersonationService;
use App\Services\AdminTestUserDeletion;
use App\Support\Rbac;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait UsersActions
{
    #[Locked]
    public ?int $deleteTarget = null;

    #[Locked]
    public string $deletionKey = '';

    public string $deleteConfirmation = '';

    public function prepareDeletion(int $userId): void
    {
        $actor = $this->actor();
        abort_unless(Rbac::hasPermission($actor, 'users.delete'), 403);
        $target = User::findOrFail($userId);
        abort_unless(Rbac::canAccessUserScope($actor, $target), 403);
        $this->deleteTarget = $userId;
        $this->deletionKey = (string) Str::uuid();
        $this->deleteConfirmation = '';
    }

    public function cancelDeletion(): void
    {
        $this->actor();
        $this->deleteTarget = null;
        $this->deleteConfirmation = '';
    }

    public function deleteTestAccount(): void
    {
        $actor = $this->writeActor('users.delete', 'delete', 10);
        abort_unless($this->deleteTarget !== null, 422);
        $this->validate(['deleteConfirmation' => ['required', 'in:УДАЛИТЬ']]);
        $data = AdminTestUserDeletionRequest::validateData(['idempotency_key' => $this->deletionKey]);
        try {
            app(AdminTestUserDeletion::class)->delete($actor, $this->deleteTarget, $data);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            Notification::make()->title($exception->getMessage())->danger()->persistent()->send();

            return;
        }
        Notification::make()->title('Тестовый аккаунт удалён')->success()->send();
        $this->redirect('/admin/users', navigate: false);
    }

    public function impersonate(int $userId): void
    {
        $actor = $this->writeActor('users.impersonate', 'impersonation', 20);
        $target = User::findOrFail($userId);
        abort_unless(Rbac::canImpersonate($actor, $target), 403);
        try {
            app(AdminImpersonationService::class)->start(request(), $actor, $target, $this->impersonationReturnPath());
        } catch (AdminImpersonationException $exception) {
            if ($exception->status !== 409) {
                abort($exception->status, $exception->getMessage());
            }
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }
        $this->redirect('/lk', navigate: false);
    }
}
