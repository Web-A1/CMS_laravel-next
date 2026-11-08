<?php

namespace App\Filament\Pages;

use App\Models\VideoWatermarkSession;
use App\Services\VideoWatermarks\VideoWatermarkSessionService;
use App\Support\Admin\UsersPage;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

final class Watermark extends UsersPage
{
    protected static ?string $slug = 'video-watermarks';
    protected static ?string $title = 'Поиск водяного знака';
    protected static string | \UnitEnum | null $navigationGroup = 'Инструменты';
    protected static ?int $navigationSort = 2;
    protected static string $readPermission = 'video-watermarks.read';
    protected string $view = 'filament.pages.watermark';

    public ?array $data = [];
    #[Locked]
    public ?string $sessionId = null;
    #[Locked]
    public bool $searched = false;

    public function getBreadcrumbs(): array
    {
        return ['/admin/users' => 'Пользователи', $this->getTitle()];
    }

    public function mount(): void
    {
        $this->actor();
        $this->form->fill(['code' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('code')->label('Код водяного знака')->required()->maxLength(64)])
            ->statePath('data');
    }

    public function lookup(): void
    {
        $actor = $this->writeActor('video-watermarks.read', 'watermark', 30);
        $data = $this->form->getState();
        $session = app(VideoWatermarkSessionService::class)->lookupByInput($data['code'], $actor);
        $this->sessionId = $session?->id;
        $this->searched = true;
    }

    protected function getViewData(): array
    {
        $this->actor();
        $session = $this->sessionId ? VideoWatermarkSession::with('user:id,name')->find($this->sessionId) : null;

        return ['result' => $session ? [
            'code' => $session->code, 'status' => $session->computedStatus(),
            'startedAt' => $session->started_at?->toJSON(), 'lastUsedAt' => $session->last_used_at?->toJSON(),
            'endedAt' => $session->ended_at?->toJSON(), 'userId' => $session->user->id,
            'name' => $session->user->name ?: 'ID '.$session->user->id,
        ] : null];
    }
}
