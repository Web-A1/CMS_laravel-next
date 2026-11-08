@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $avatarUrl = filament()->auth()->user()->getAttributeValue('avatar_url');

    // Mirror SiteHeader's public destinations; covered by the navigation parity test.
    $links = [
        '/' => 'Главная', '/subjects' => 'Предметы', '/boxes' => 'Боксы',
        '/about' => 'О школе', '/articles' => 'Статьи', '/reviews' => 'Отзывы', '/faq' => 'Вопросы',
    ];
    $subjects = [
        'anatomiya' => 'Анатомия', 'gistologiya' => 'Гистология', 'latinskij-yazyk' => 'Латинский язык',
        'bioorganicheskaya-himiya' => 'Биоорганическая химия', 'mikrobiologiya' => 'Микробиология',
        'fiziologiya' => 'Нормальная физиология', 'biohimiya' => 'Биохимия',
        'patologicheskaya-anatomiya' => 'Патологическая анатомия', 'patofiziologiya' => 'Патологическая физиология',
        'farmakologiya' => 'Фармакология', 'propedevtika-vnutrennih-boleznej' => 'Пропедевтика внутренних болезней',
        'hirurgiya' => 'Хирургия', 'nevrologiya' => 'Неврология', 'luchevaya-diagnostika' => 'Лучевая диагностика',
    ];
@endphp

<div class="cms-public-topbar" data-ui="admin.publicHeader"
    x-data="{}"
    x-on:resize.window="if (window.innerWidth >= 1024 && $refs.siteMenu.open) $refs.siteMenu.close()"
    x-on:keydown.escape.window="if (window.innerWidth < 1024 && $store.sidebar.isOpen && ! $refs.siteMenu.open) { $store.sidebar.close(); $refs.sidebarToggle.focus() }"
>
    <header class="cms-public-header" data-ui="header.root">
        {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_START) }}

        <button type="button" class="cms-header-control cms-header-sidebar" aria-label="Разделы админки"
            aria-controls="fi-main-sidebar" x-bind:aria-expanded="$store.sidebar.isOpen" x-ref="sidebarToggle"
            x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()" data-ui="admin.header.sidebarToggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16M5.5 8h1M5.5 12h1" /></svg>
        </button>

        <a href="/" class="cms-header-logo" data-ui="header.logo">
            <span class="cms-header-mark" aria-hidden="true"></span>christmedschool
        </a>

        <nav class="cms-header-nav" aria-label="Основная навигация" data-ui="header.desktopNav">
            @foreach ($links as $href => $label)
                @if ($href === '/subjects')
                    <div class="cms-header-subjects" x-data="{ dismissed: false }"
                        x-on:mouseenter="dismissed = false" x-on:focusin="dismissed = false"
                        x-on:keydown.escape.stop.prevent="$refs.subjectLink.focus(); dismissed = true"
                        x-bind:data-dismissed="dismissed" data-ui="header.subjectsMenu">
                        <a href="{{ $href }}" class="cms-header-link" x-ref="subjectLink">{{ $label }}</a>
                        <div class="cms-header-subject-panel" role="group" aria-label="Предметы" data-ui="header.subjectsMenu.panel">
                            @foreach ($subjects as $slug => $subject)
                                <a href="/subjects/{{ $slug }}" data-ui="header.subjectsMenu.link">{{ $subject }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ $href }}" class="cms-header-link">{{ $label }}</a>
                @endif
            @endforeach
        </nav>

        <div class="cms-header-account" data-ui="header.account">
            <div class="cms-header-identity">
                <x-filament::dropdown placement="bottom-end" teleport class="fi-user-menu">
                    <x-slot name="trigger">
                        <button type="button" class="fi-user-menu-trigger" aria-label="Выбрать тему" data-ui="header.themeTrigger">
                            <span class="cms-header-avatar" aria-hidden="true">
                                @if (is_string($avatarUrl) && trim($avatarUrl) !== '')
                                    <img src="{{ $avatarUrl }}" alt="" width="20" height="20" loading="eager" fetchpriority="high" />
                                @else
                                <svg viewBox="0 0 16 16" focusable="false" fill="currentColor">
                                    <path d="M8 2a2.84 2.84 0 0 0-1.12.221c-.345.141-.651.348-.906.615v.003l-.001.002c-.248.269-.44.592-.574.96-.137.367-.203.769-.203 1.2 0 .435.065.841.203 1.209.135.361.327.68.574.95l.001.002c.254.267.558.477.901.624v.003c.346.141.723.21 1.12.21.395 0 .77-.069 1.117-.21v-.002c.343-.147.644-.357.892-.625.255-.268.45-.59.586-.952.138-.368.204-.774.204-1.21h.01c0-.43-.065-.831-.203-1.198a2.771 2.771 0 0 0-.585-.963 2.5 2.5 0 0 0-.897-.618A2.835 2.835 0 0 0 7.999 2zM8.024 10.002c-2.317 0-3.561.213-4.486.91-.462.35-.767.825-.939 1.378-.172.553-.226.975-.228 1.71L8 14.002h5.629c-.001-.736-.052-1.159-.225-1.712-.172-.553-.477-1.027-.94-1.376-.923-.697-2.124-.912-4.44-.912z" />
                                </svg>
                                @endif
                            </span>
                        </button>
                    </x-slot>
                    @if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()) && filament()->hasThemeSwitcher())
                        <x-filament::dropdown.list>
                            <x-filament-panels::theme-switcher />
                        </x-filament::dropdown.list>
                    @endif
                </x-filament::dropdown>
                <span class="cms-header-name">{{ filament()->getUserName(filament()->auth()->user()) }}</span>
            </div>
            <form action="{{ filament()->getLogoutUrl() }}" method="post" data-ui="header.logoutForm">
                @csrf
                <button type="submit" class="cms-header-control" aria-label="Выйти из аккаунта" title="Выйти из аккаунта" data-ui="header.logoutButton">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M15 17l5-5-5-5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M20 12H9" stroke-linecap="round" />
                        <path d="M12 4H5a2 2 0 00-2 2v12a2 2 0 002 2h7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </form>
        </div>

        <button type="button" class="cms-header-control cms-header-site-toggle" aria-label="Открыть меню сайта"
            aria-haspopup="dialog" aria-controls="cms-site-menu" x-ref="siteMenuToggle"
            x-on:click="$store.sidebar.close(); $refs.siteMenu.showModal()" data-ui="header.burger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 8h16M4 16h16" /></svg>
        </button>

        {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_END) }}
    </header>

    <dialog id="cms-site-menu" class="cms-site-menu" x-ref="siteMenu" aria-label="Меню сайта"
        x-on:click="if ($event.target === $el) $el.close()"
        x-on:close="if (window.innerWidth < 1024) $refs.siteMenuToggle.focus()" data-ui="header.mobileMenu">
        <div class="cms-site-menu-panel">
            <div class="cms-site-menu-heading">
                <a href="/" class="cms-header-logo"><span class="cms-header-mark" aria-hidden="true"></span>christmedschool</a>
                <button type="button" class="cms-header-control" aria-label="Закрыть меню сайта" autofocus
                    x-on:click="$refs.siteMenu.close()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </div>
            <nav aria-label="Навигация сайта">
                @foreach ($links as $href => $label)
                    <a href="{{ $href }}">{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </dialog>

    <x-filament-actions::modals />
</div>
