<header class="fi-header article-editor-header" data-ui="article-editor.header">
    @if ($breadcrumbs)
        <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
    @endif
    <h1 class="fi-header-heading">{{ $heading }}</h1>
    <x-filament::badge :color="$statusColor" size="sm" data-ui="article-editor.saved-status">
        {{ $statusLabel }}
    </x-filament::badge>
    <p class="fi-header-subheading" data-ui="article-editor.id">{{ $idLabel }}</p>
    <p class="fi-header-subheading article-editor-header__save-state" role="status" aria-live="polite" data-ui="article-editor.save-state" x-text="message" x-show="message" x-cloak></p>
</header>
