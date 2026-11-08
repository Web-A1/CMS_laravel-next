<style>
    /* Public SiteHeader brand variant. Keep these styles scoped: panel controls retain Filament's theme. */
    @font-face {
        font-family: "CMS Header Montserrat";
        font-style: normal;
        font-weight: 500;
        font-display: swap;
        src: url('/fonts/v2/montserrat/Montserrat-500-700-latin.woff2') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
    }
    @font-face {
        font-family: "CMS Header Montserrat";
        font-style: normal;
        font-weight: 500;
        font-display: swap;
        src: url('/fonts/v2/montserrat/Montserrat-500-700-cyrillic.woff2') format('woff2');
        unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116;
    }

    @font-face {
        font-family: "CMS Header Montserrat";
        font-style: normal;
        font-weight: 700;
        font-display: swap;
        src: url('/fonts/v2/montserrat/Montserrat-500-700-latin.woff2') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
    }
    @font-face {
        font-family: "CMS Header Montserrat";
        font-style: normal;
        font-weight: 700;
        font-display: swap;
        src: url('/fonts/v2/montserrat/Montserrat-500-700-cyrillic.woff2') format('woff2');
        unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116;
    }

    html:has(.cms-public-topbar) { scrollbar-gutter: stable; }
    .fi-body { --topbar-height: 3.75rem; }
    .cms-public-topbar {
        position: sticky;
        top: 0;
        z-index: 30;
        height: var(--topbar-height);
        flex-shrink: 0;
        padding-top: max(.25rem, env(safe-area-inset-top));
    }
    .cms-public-header, .cms-site-menu-panel {
        --cms-header-brand: #5da08d;
        font-family: "CMS Header Montserrat", ui-sans-serif, system-ui, sans-serif;
        color: #fff;
    }
    .cms-public-header {
        display: flex;
        align-items: center;
        gap: .25rem;
        width: calc(100% - 1.25rem);
        max-width: 26.75rem;
        height: 3rem;
        margin-inline: auto;
        padding: 0 .5rem;
        border: 1px solid transparent;
        border-radius: 9999px;
        background: color-mix(in srgb, var(--cms-header-brand) 98%, transparent);
        box-shadow: inset 0 1px 0 rgb(255 255 255 / 28%), inset 0 -1px 0 rgb(49 95 83 / 16%);
        backdrop-filter: blur(2px);
    }
    .cms-header-logo {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        gap: .5em;
        color: inherit;
        text-transform: uppercase;
        text-decoration: none;
        font-size: .875rem;
        font-weight: 700;
        line-height: 1rem;
        letter-spacing: -.02em;
    }
    .cms-header-mark {
        width: calc(1.8cap * 256 / 170);
        height: 1.8cap;
        flex: none;
        background: currentColor;
        mask: url('/images/brand/christmedschool-graduation-cap.svg') center / contain no-repeat;
    }
    .cms-header-control {
        display: inline-flex;
        flex: 0 0 2rem;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        padding: .375rem;
        border: 0;
        border-radius: 9999px;
        color: inherit;
        background: transparent;
        cursor: pointer;
    }
    .cms-header-control svg { width: 100%; height: 100%; }
    .cms-header-control:hover { background: rgb(255 255 255 / 14%); }
    .cms-public-header a:focus-visible, .cms-public-header button:focus-visible,
    .cms-site-menu-panel a:focus-visible, .cms-site-menu-panel button:focus-visible {
        outline: 2px solid currentColor;
        outline-offset: 3px;
    }
    .cms-header-nav { display: none; }
    .cms-header-account { display: flex; min-width: 0; align-items: center; gap: 0; margin-left: auto; }
    .cms-header-identity { display: inline-flex; align-items: center; min-width: 0; max-width: 13rem; height: 2rem; padding: .25rem .3125rem; border: 1px solid transparent; gap: 0; }
    .cms-header-identity > .fi-user-menu, .cms-header-account > form { flex-shrink: 0; }
    .cms-header-account .fi-user-menu-trigger { display: flex; width: 1.25rem; height: 1.25rem; padding: 0; border: 0; border-radius: 50%; background: transparent; }
    .cms-header-avatar { display: inline-flex; width: 1.25rem; height: 1.25rem; align-items: center; justify-content: center; overflow: hidden; border-radius: 50%; color: #315f53; background: rgb(255 255 255 / 92%); }
    .cms-header-avatar svg { width: 1.125rem; height: 1.125rem; }
    .cms-header-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .cms-header-name { display: none; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: .875rem; font-weight: 500; line-height: 1.3; }
    .cms-header-account .cms-header-control { padding: 0; }
    .cms-header-account .cms-header-control svg { width: 1.125rem; height: 1.125rem; }
    .cms-site-menu {
        width: 100%;
        max-width: none;
        height: 100dvh;
        max-height: none;
        padding: 0;
        border: 0;
        background: transparent;
        color: #fff;
    }
    .cms-site-menu::backdrop { background: rgb(24 24 27 / 40%); }
    .cms-site-menu-panel {
        width: min(100%, 28rem);
        min-height: 100%;
        margin-inline: auto;
        padding: 1rem;
        background: var(--cms-header-brand);
    }
    .cms-site-menu-heading { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .cms-site-menu nav { display: grid; gap: .5rem; padding-block: 2rem; }
    .cms-site-menu nav a { padding: .75rem 1rem; font-size: 1.25rem; font-weight: 500; text-decoration: none; }
    @media (max-width: 359px) {
        .cms-public-header .cms-header-logo { font-size: .75rem; }
        .cms-public-header .cms-header-mark { display: none; }
        .cms-public-header { gap: 0; padding-inline: .375rem; }
    }
    @media (min-width: 1024px) {
        .fi-body { --topbar-height: 5.5rem; }
        .cms-public-topbar { padding-top: 1.25rem; }
        .cms-public-header { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); width: calc(100% - 2rem); max-width: none; padding: 0 1rem; gap: .875rem; }
        .cms-header-sidebar, .cms-header-site-toggle { display: none; }
        .cms-header-nav { display: flex; flex: 1 1 auto; justify-content: center; align-items: center; gap: clamp(.55rem, 1.1vw, 1rem); }
        .cms-header-link { color: #fff; font-size: .75rem; font-weight: 600; line-height: 1; text-decoration: none; white-space: nowrap; border-radius: .45rem; }
        .cms-header-link:hover, .cms-header-subjects:focus-within > .cms-header-link, .cms-header-subjects:hover > .cms-header-link { background: rgb(255 255 255 / 14%); box-shadow: 0 0 0 .375rem rgb(255 255 255 / 14%); }
        .cms-header-account { margin-left: 0; justify-self: end; max-width: 100%; }
        .cms-header-identity { padding-inline: .75rem; gap: .5rem; }
        .cms-header-name { display: block; }
        .cms-header-subjects { position: relative; }
        .cms-header-subjects::after { content: ''; position: absolute; top: 100%; left: -.75rem; right: -.75rem; height: .875rem; }
        .cms-header-subject-panel {
            display: grid; position: absolute; top: calc(100% + .875rem); left: 0;
            width: max-content; max-width: calc(100vw - 2rem); max-height: calc(100dvh - 6rem); overflow-y: auto;
            padding: .5rem; gap: .25rem; border: 1px solid rgb(255 255 255 / 72%); border-radius: 1rem;
            background: rgb(255 255 255 / 96%); color: #3f3f46;
            box-shadow: 0 18px 42px rgb(24 28 32 / 14%), 0 4px 14px rgb(24 28 32 / 8%);
            visibility: hidden; pointer-events: none;
        }
        .cms-header-subjects:not([data-dismissed="true"]):is(:hover, :focus-within) .cms-header-subject-panel { visibility: visible; pointer-events: auto; }
        .cms-header-subject-panel a { padding: .4375rem .75rem; border-radius: .625rem; font-size: .875rem; font-weight: 500; line-height: 1.15; text-decoration: none; }
        .cms-header-subject-panel a:hover { background: #e4ffb5; color: #315f53; }
    }
    html.dark .cms-public-header, html.dark .cms-site-menu-panel {
        --cms-header-brand: var(--gray-900);
        color: var(--gray-300);
    }
    html.dark .cms-public-header {
        background: var(--cms-header-brand);
        border-color: var(--gray-800);
        box-shadow: none;
    }
    html.dark .cms-header-link { color: inherit; }
    html.dark .cms-header-avatar { color: var(--gray-300); background: var(--gray-800); }
    html.dark .cms-header-control:hover,
    html.dark .cms-site-menu nav a:hover { background: var(--gray-800); }
    html.dark .cms-header-link:hover,
    html.dark .cms-header-subjects:focus-within > .cms-header-link,
    html.dark .cms-header-subjects:hover > .cms-header-link {
        background: var(--gray-800);
        box-shadow: 0 0 0 .375rem var(--gray-800);
    }
    html.dark .cms-header-subject-panel {
        background: var(--gray-900);
        color: var(--gray-300);
        border-color: var(--gray-800);
        box-shadow: 0 12px 28px rgb(0 0 0 / 24%);
    }
    html.dark .cms-header-subject-panel a:hover {
        background: var(--gray-800);
        color: inherit;
    }
</style>
