<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Users\UserDetailPresentation;
use App\Http\Requests\AdminUserMutationRequest;
use App\Models\User;
use App\Services\AccessResolver;
use App\Services\AdminUserManagement;
use App\Services\AdminUserQueries;
use App\Support\Admin\UsersActions;
use App\Support\Admin\UsersPage;
use App\Support\Rbac;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class UserDetail extends UsersPage
{
    use UsersActions;

    protected static ?string $slug = 'users/{userId}';

    protected static ?string $title = 'Карточка пользователя';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.user-detail';

    #[Locked]
    public int $userId;

    #[Locked]
    public string $revision = '';

    #[Locked]
    public string $commentKey = '';

    #[Url]
    public string $tab = 'profile';

    #[Url]
    public string $q = '';

    #[Url]
    public string $scope = 'users';

    #[Url]
    public array $filters = [];

    #[Url]
    public ?string $sort = null;

    #[Url]
    public int $page = 1;

    #[Url(as: 'orders_page')]
    public int $ordersPage = 1;

    #[Url(as: 'access_page')]
    public int $accessPage = 1;

    #[Url(as: 'courses_page')]
    public int $coursesPage = 1;

    #[Url(as: 'events_page')]
    public int $eventsPage = 1;

    public ?array $profileData = [];

    public ?array $managerData = [];

    public ?array $tagsData = [];

    public ?array $commentData = [];

    #[Locked]
    public string $savingOpening = '';

    #[Locked]
    public array $savingBaseline = [];

    public ?string $savingError = null;

    public function mount(string $userId): void
    {
        abort_unless(ctype_digit($userId) && strlen($userId) < 19 && (int) $userId > 0, 404);
        $this->userId = (int) $userId;
        $this->reloadEditor();
    }

    public function hydrate(): void
    {
        $this->subject();
    }

    public function reloadEditor(): void
    {
        $detail = app(AdminUserQueries::class)->show($this->actor(), [], $this->subject());
        $this->revision = $detail['revision'];
        $names = $this->subject()->hasStaffNameFields() ? ['first_name' => $detail['first_name'], 'last_name' => $detail['last_name']] : [];
        $this->form->fill(['name' => $detail['name'], ...$names, ...$detail['profile']]);
        if (Rbac::hasPermission($this->actor(), 'users.assign_manager')) {
            $this->managerForm->fill(['manager_id' => $detail['manager_id']]);
        }
        $this->tagsForm->fill(['tags' => implode(', ', $detail['tags'])]);
        $this->commentKey = (string) Str::uuid();
        $this->commentForm->fill(['comment' => '']);
        $this->savingOpening = (string) Str::uuid();
        $this->savingError = null;
        $this->savingBaseline = [];
        foreach (['profileData', 'managerData', 'tagsData', 'commentData'] as $path) {
            foreach ($this->$path ?? [] as $field => $value) {
                $this->savingBaseline[$path.'.'.$field] = $value;
            }
        }
    }

    public function openSavingTab(string $tab): void
    {
        $this->reloadEditor();
        $this->tab = $tab;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Отображаемое имя аккаунта')->required()->maxLength(255)
                ->extraInputAttributes(fn (TextInput $component): array => ['aria-describedby' => implode(' ', [...$component->getDescriptionIds(), 'user-name-help'])])
                ->belowContent([Text::make('Для интерфейса аккаунта. Авторы статей редактируются в отдельном справочнике.')->extraAttributes(['id' => 'user-name-help']),
                    SchemaView::make('filament.field-saving.hint')->viewData(['field' => 'profileData.name'])]),
            ...($this->subject()->hasStaffNameFields() ? [TextInput::make('first_name')->label('Имя')->maxLength(120)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.first_name'])),
                TextInput::make('last_name')->label('Фамилия')->maxLength(120)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.last_name']))] : []),
            DatePicker::make('birth_date')->label('Дата рождения')->native()->maxDate(now())->live(onBlur: true),
            Select::make('gender')->label('Пол')->options(['female' => 'Женский', 'male' => 'Мужской', 'other' => 'Другой'])->live(),
            TextInput::make('school')->label('Учебное заведение')->maxLength(255)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.school'])),
            TextInput::make('study_year')->label('Курс обучения')->maxLength(80)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.study_year'])),
            TextInput::make('enrollment_year')->label('Год поступления')->numeric()->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.enrollment_year'])),
            TextInput::make('study_direction')->label('Направление обучения')->maxLength(255)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.study_direction'])),
            TextInput::make('profession')->label('Профессия')->maxLength(255)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.profession'])),
            Textarea::make('interests')->label('Интересы')->maxLength(2000)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.interests'])),
            TextInput::make('referral_source')->label('Откуда узнали о школе')->maxLength(255)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.referral_source'])),
            TextInput::make('preferred_contact')->label('Предпочтительный способ связи')->maxLength(80)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.preferred_contact'])),
            TextInput::make('contact_details')->label('Контакт для связи')->maxLength(255)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.contact_details'])),
            TextInput::make('vk_profile')->label('Профиль ВКонтакте')->maxLength(255)->belowContent(view('filament.field-saving.hint', ['field' => 'profileData.vk_profile'])),
        ])->columns(2)->statePath('profileData')->disabled(fn () => ! Rbac::hasPermission($this->actor(), 'users.update'));
    }

    public function managerForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('manager_id')->label('Менеджер или преподаватель')->searchable()
                ->getSearchResultsUsing(function (string $search): array {
                    return collect(app(AdminUserQueries::class)->managers($this->actor(), ['q' => $search, 'per_page' => 100])['data'])->pluck('name', 'id')->all();
                })
                ->getOptionLabelUsing(function ($value): ?string {
                    if (! $value) {
                        return null;
                    }
                    $rows = app(AdminUserQueries::class)->managers($this->actor(), ['q' => (string) $value])['data'];

                    return collect($rows)->firstWhere('id', (int) $value)['name'] ?? null;
                }),
        ])->statePath('managerData');
    }

    public function tagsForm(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('tags')->label('Метки через запятую')->maxLength(1620)
            ->belowContent(view('filament.field-saving.hint', ['field' => 'tagsData.tags']))])
            ->statePath('tagsData');
    }

    public function commentForm(Schema $schema): Schema
    {
        return $schema->components([Textarea::make('comment')->label('Новый комментарий')->required()->maxLength(5000)])
            ->statePath('commentData');
    }

    public function saveProfile(): array
    {
        $this->writeActor('users.update', 'profile');
        $this->form->getState();

        return $this->mutate('profile', 'updateProfile', [...($this->profileData ?? []), 'revision' => $this->revision], 'profileData');
    }

    public function saveManager(): array
    {
        $this->writeActor('users.assign_manager', 'manager');
        $data = $this->managerForm->getState();

        return $this->mutate('manager', 'updateManager', ['revision' => $this->revision, 'manager_id' => $data['manager_id'] ?? null], 'managerData');
    }

    public function saveTags(): array
    {
        $this->writeActor('users.update', 'tags');
        $data = $this->tagsForm->getState();
        $tags = trim($data['tags'] ?? '') === '' ? [] : array_map('trim', explode(',', $data['tags']));

        return $this->mutate('tags', 'updateTags', ['revision' => $this->revision, 'tags' => $tags], 'tagsData');
    }

    public function addComment(): array
    {
        $this->writeActor('users.update', 'comment');
        $data = $this->commentForm->getState();
        $result = $this->mutate('comment', 'comment', ['comment' => $data['comment'], 'idempotency_key' => $this->commentKey], 'commentData');
        if ($result['ok']) {
            // Keep the key for retries; only an explicit new comment starts another attempt.
            Notification::make()->title('Комментарий добавлен')->success()->send();
        }

        return $result;
    }

    public function newComment(): array
    {
        $this->subject();
        abort_unless(Rbac::hasPermission($this->actor(), 'users.update'), 403);
        $this->commentKey = (string) Str::uuid();
        $this->commentForm->fill(['comment' => '']);

        return $this->ack(['commentData.comment' => '']);
    }

    private function mutate(string $operation, string $validationOperation, array $data, string $statePath): array
    {
        $actor = $this->actor();
        $this->subject();
        $submitted = $this->$statePath ?? [];
        try {
            $data = AdminUserMutationRequest::validateFor($validationOperation, $data);
            $result = app(AdminUserManagement::class)->mutate($actor, $this->userId, $operation, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [$statePath.'.'.$field => $messages])->all());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            Notification::make()->title($exception->getMessage())->danger()->persistent()->send();

            $this->savingError = $exception->getMessage();

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        $this->revision = $result['revision'] ?? $this->revision;
        if ($operation !== 'comment') {
            Notification::make()->title('Изменения сохранены')->success()->send();
        }

        return $this->ack(collect($submitted)->mapWithKeys(fn ($value, $field) => [$statePath.'.'.$field => $value])->all());
    }

    private function ack(array $fields): array
    {
        $this->savingBaseline = array_replace($this->savingBaseline, $fields);

        return ['ok' => true, 'revision' => $this->revision, 'fields' => $fields];
    }

    public function saveProfileParameters(array $patch, string $revision, string $opening): array
    {
        $this->writeActor('users.update', 'profile');
        abort_unless($opening === $this->savingOpening, 409);
        $data = [];
        foreach ($patch as $field => $value) {
            if (! in_array($field, ['profileData.birth_date', 'profileData.gender'], true)) {
                throw ValidationException::withMessages(['request' => 'Запрос содержит недопустимые поля.']);
            }
            $data[substr($field, strlen('profileData.'))] = $value;
        }
        try {
            $result = app(AdminUserManagement::class)->mutate($this->actor(), $this->userId, 'profileParameters', $data + ['revision' => $revision]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('profileData.'.$field, $messages[0]);
            }

            return ['ok' => false, 'errors' => $exception->errors()];
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->savingError = $exception->getMessage();

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        $this->revision = $result['revision'];
        foreach ($data as $field => $value) {
            $this->resetValidation('profileData.'.$field);
        }

        return $this->ack($patch);
    }

    public function saveOrdinary(): array
    {
        $actor = $this->writeActor('users.update', 'profile');
        $profile = [];
        foreach ($this->profileData ?? [] as $field => $value) {
            if ((string) $value !== (string) ($this->savingBaseline['profileData.'.$field] ?? null)) {
                $profile[$field] = $value;
            }
        }
        $tagsChanged = (string) ($this->tagsData['tags'] ?? '') !== (string) ($this->savingBaseline['tagsData.tags'] ?? '');
        $operations = [];
        $statePath = 'profileData';
        try {
            if ($profile !== []) {
                $this->form->getState();
                $operations['profile'] = AdminUserMutationRequest::validateFor('updateProfile', ($this->profileData ?? []) + ['revision' => $this->revision]);
            }
            if ($tagsChanged) {
                $statePath = 'tagsData';
                $state = $this->tagsForm->getState();
                $tags = trim($state['tags'] ?? '') === '' ? [] : array_map('trim', explode(',', $state['tags']));
                $operations['tags'] = AdminUserMutationRequest::validateFor('updateTags', ['revision' => $this->revision, 'tags' => $tags]);
            }
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [str_starts_with($field, $statePath.'.') ? $field : $statePath.'.'.$field => $messages])->all());
        }
        try {
            $revision = DB::transaction(function () use ($actor, $operations): string {
                $revision = $this->revision;
                foreach ($operations as $operation => $input) {
                    $result = app(AdminUserManagement::class)->mutate($actor, $this->userId, $operation, array_replace($input, ['revision' => $revision]));
                    $revision = $result['revision'];
                }

                return $revision;
            });
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['profileData.'.$field => $messages])->all());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $this->savingError = $exception->getMessage();

            return ['ok' => false, 'terminal' => true, 'message' => $exception->getMessage()];
        }
        $this->revision = $revision;
        $fields = [];
        foreach (array_keys($operations) as $operation) {
            $path = $operation === 'profile' ? 'profileData' : 'tagsData';
            foreach ($this->$path ?? [] as $field => $value) {
                $fields[$path.'.'.$field] = $value;
            }
        }

        return $this->ack($fields);
    }

    public function savingConfig(): array
    {
        $canEdit = Rbac::hasPermission($this->actor(), 'users.update');
        $parameters = $canEdit ? ['profileData.birth_date', 'profileData.gender'] : [];
        $manual = $canEdit ? array_values(array_filter(array_keys($this->savingBaseline), fn ($field) => str_starts_with($field, 'profileData.') && ! in_array($field, $parameters, true))) : [];
        if ($canEdit) {
            $manual[] = 'tagsData.tags';
        }
        $commands = $canEdit ? ['commentData.comment'] : [];
        if (Rbac::hasPermission($this->actor(), 'users.assign_manager')) {
            $commands[] = 'managerData.manager_id';
        }

        return ['statePath' => '', 'parameters' => $parameters, 'manual' => $manual, 'commands' => $commands,
            'baseline' => $this->savingBaseline, 'revision' => $this->revision, 'opening' => $this->savingOpening,
            'openingPath' => 'savingOpening', 'existing' => true, 'standalone' => true,
            'saveMethod' => 'saveProfileParameters', 'submitMethod' => 'saveOrdinary',
            'submitMethods' => ['saveProfile', 'saveTags', 'saveManager', 'addComment', 'newComment'],
            'guardForm' => 'user-ordinary', 'loginUrl' => '/auth?mode=login&next=%2Fadmin%2Fusers'];
    }

    private function subject(): User
    {
        $actor = $this->actor();
        $user = User::findOrFail($this->userId);
        abort_unless(Rbac::canAccessUserScope($actor, $user), 403);

        return $user;
    }

    protected function impersonationReturnPath(): string
    {
        return '/admin/users/'.$this->userId.'?'.http_build_query([
            ...$this->listContext(),
            'tab' => $this->tab, 'orders_page' => $this->ordersPage, 'access_page' => $this->accessPage,
            'courses_page' => $this->coursesPage, 'events_page' => $this->eventsPage,
        ]);
    }

    public function getBreadcrumbs(): array
    {
        $context = $this->listContext();

        return [
            '/admin/users?'.http_build_query($context) => $context['scope'] === 'staff' ? 'Сотрудники' : 'Пользователи',
            $this->getTitle(),
        ];
    }

    private function listContext(): array
    {
        // Only bounded list fields become a return link; a caller cannot supply its destination.
        return [
            'q' => mb_substr(trim($this->q), 0, 255),
            'scope' => $this->scope === 'staff' && in_array($this->actor()->role, ['admin_full', 'admin_limited'], true) ? 'staff' : 'users',
            'page' => $this->page >= 1 && $this->page <= 1000000 ? $this->page : 1,
            'filters' => array_intersect_key($this->filters, array_flip(['name', 'contact', 'created', 'scope', 'role', 'paid'])),
            'sort' => in_array($this->sort, ['id:asc', 'id:desc', 'name:asc', 'name:desc', 'created_at:asc', 'created_at:desc', 'paid_orders:asc', 'paid_orders:desc'], true) ? $this->sort : null,
        ];
    }

    protected function getViewData(): array
    {
        $actor = $this->actor();
        $user = $this->subject();
        $queries = app(AdminUserQueries::class);
        $detail = $queries->show($actor, [], $user);
        $isStaff = $user->usesAdminWorkspace();
        $tabs = ['profile' => 'Анкета'];
        if (! $isStaff && $detail['legacy_profile']) {
            $tabs['legacy'] = 'Старый сайт';
        }
        if (! $isStaff && Rbac::hasPermission($actor, 'payments.read_order')) {
            $tabs['orders'] = 'Заказы';
        }
        if (! $isStaff && Rbac::hasPermission($actor, 'access.read')) {
            $tabs['access'] = 'Доступы';
        }
        if (Rbac::hasPermission($actor, 'audit.read')) {
            $tabs['events'] = 'История';
        }
        abort_unless(array_key_exists($this->tab, $tabs), 403);
        $data = ['detail' => $detail, 'tabs' => $tabs,
            'canEdit' => Rbac::hasPermission($actor, 'users.update'),
            'canAssign' => Rbac::hasPermission($actor, 'users.assign_manager'),
            'canDelete' => Rbac::hasPermission($actor, 'users.delete') && app()->environment(['local', 'testing', 'staging'])
                && $user->role === 'user_no_purchase' && (bool) $user->email && ! $detail['legacy_profile']
                && ! $user->legacy_auto_verified && ! $user->blocksAuthenticationForMigration(),
            'canImpersonate' => Rbac::canImpersonate($actor, $user)];
        if ($this->tab === 'orders') {
            $data['orders'] = $this->listing(fn () => $queries->orders($actor, ['page' => $this->ordersPage], $user));
        } elseif ($this->tab === 'access') {
            $data['access'] = $this->listing(fn () => $queries->entitlements($actor, ['page' => $this->accessPage], $user));
            $data['courses'] = $this->listing(fn () => $queries->courses($actor, ['page' => $this->coursesPage], $user, app(AccessResolver::class)));
        } elseif ($this->tab === 'events') {
            $data['events'] = $this->listing(fn () => $queries->events($actor, ['page' => $this->eventsPage], $user));
        }

        return [...$data, ...UserDetailPresentation::schemas($this, $data)];
    }
}
