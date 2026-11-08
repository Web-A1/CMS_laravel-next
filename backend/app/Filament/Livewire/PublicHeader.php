<?php

namespace App\Filament\Livewire;

use Filament\Livewire\Topbar;
use Illuminate\Contracts\View\View;

final class PublicHeader extends Topbar
{
    protected function getUserMenuItems(): array
    {
        // This header exposes logout separately; the avatar menu is only for themes.
        return [];
    }

    public function render(): View
    {
        return view('filament.public-header');
    }
}
