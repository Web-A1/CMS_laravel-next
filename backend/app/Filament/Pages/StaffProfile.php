<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminStaffProfile;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class StaffProfile extends Page
{
    use HasUnsavedDataChangesAlert;

    protected function hasUnsavedDataChangesAlert(): bool
    {
        return false;
    }

    protected static ?string $slug = 'settings';

    protected static ?string $title = 'Настройки';

    protected static ?string $navigationLabel = 'Настройки';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.staff-profile';

    public ?array $data = [];

    #[Locked]
    public array $savedData = [];

    #[Locked]
    public array $expectedSnapshot = [];

    #[Locked]
    public string $savingOpening = '';

    public static function canAccess(): bool
    {
        $actor = auth('web')->user();
        $current = $actor instanceof User ? $actor->fresh() : null;

        return $current instanceof User
            && $current->canAccessPanel(Filament::getPanel('admin'))
            && Rbac::hasPermission($current, 'admin.self.view');
    }

    public function mount(): void
    {
        $actor = $this->actor();
        $this->form->fill($actor->only($actor->hasStaffNameFields() ? ['name', 'first_name', 'last_name'] : ['name']));
        $this->savedData = $this->data ?? [];
        $this->expectedSnapshot = $this->savedData;
        $this->savingOpening = (string) Str::uuid();
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Отображаемое имя аккаунта')->required()->maxLength(255)
                ->extraInputAttributes(fn (TextInput $component): array => ['aria-describedby' => implode(' ', [...$component->getDescriptionIds(), 'staff-name-help'])])
                ->belowContent([Text::make('Для интерфейса аккаунта. Авторы статей редактируются в отдельном справочнике.')->extraAttributes(['id' => 'staff-name-help']),
                    SchemaView::make('filament.field-saving.hint')->viewData(['field' => 'name'])])
                ->disabled(fn (): bool => ! $this->canUpdate()),
            ...($this->actor()->hasStaffNameFields() ? [TextInput::make('first_name')->label('Имя')->maxLength(120)
                ->belowContent(view('filament.field-saving.hint', ['field' => 'first_name']))
                ->disabled(fn (): bool => ! $this->canUpdate()),
                TextInput::make('last_name')->label('Фамилия')->maxLength(120)
                    ->belowContent(view('filament.field-saving.hint', ['field' => 'last_name']))
                    ->disabled(fn (): bool => ! $this->canUpdate())] : []),
        ])->statePath('data');
    }

    public function savingConfig(): array
    {
        return ['statePath' => 'data', 'baseline' => $this->savedData, 'manual' => array_keys($this->savedData),
            'parameters' => [], 'revision' => 0, 'opening' => $this->savingOpening, 'openingPath' => 'savingOpening',
            'existing' => true, 'standalone' => true, 'submitMethod' => 'save'];
    }

    public function save(): array
    {
        abort_if(ImpersonationContext::isActive(request()), 403);
        $actor = $this->actor();
        abort_unless(Rbac::hasPermission($actor, 'admin.self.update'), 403);
        $rateKey = 'filament:staff-profile:'.$actor->getKey();
        abort_if(RateLimiter::tooManyAttempts($rateKey, 30), 429);
        RateLimiter::hit($rateKey, 60);
        $this->form->getState();
        $submitted = $this->data ?? [];
        try {
            $profile = app(AdminStaffProfile::class)->update($actor, $submitted, $this->expectedSnapshot);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $key = in_array($field, ['name', 'first_name', 'last_name'], true) ? 'data.'.$field : $field;
                $errors[$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        $this->expectedSnapshot = $profile->only(array_keys($this->expectedSnapshot));
        $this->savedData = $this->expectedSnapshot;
        $this->rememberData();
        Notification::make()->title('Профиль сохранён')->success()->send();

        return ['ok' => true, 'revision' => 0, 'fields' => $submitted, 'normalized' => $this->savedData];
    }

    public function canUpdate(): bool
    {
        return Rbac::hasPermission($this->actor(), 'admin.self.update');
    }

    protected function getViewData(): array
    {
        $actor = $this->actor();

        return [
            'email' => $actor->email,
            'roleLabel' => match ($actor->role) {
                'admin_full' => 'Администратор',
                'admin_limited' => 'Сотрудник',
                'manager' => 'Менеджер',
                'teacher' => 'Преподаватель',
                default => $actor->role,
            },
        ];
    }

    private function actor(): User
    {
        $actor = auth('web')->user();
        abort_unless($actor instanceof User && self::canAccess(), 403);

        return $actor->fresh();
    }
}
