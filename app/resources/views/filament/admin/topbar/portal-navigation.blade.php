<ul aria-label="{{ __('ui.admin.portal_navigation') }}" class="fi-topbar-nav-groups">
    <x-filament-panels::topbar.item
        icon="heroicon-o-home"
        :url="route('dashboard')"
    >
        {{ __('ui.admin.portal_home') }}
    </x-filament-panels::topbar.item>

    <x-filament-panels::topbar.item
        icon="heroicon-o-calendar-days"
        :url="route('reservations.index')"
    >
        {{ __('ui.nav.reservations') }}
    </x-filament-panels::topbar.item>

    <x-filament-panels::topbar.item
        icon="heroicon-o-command-line"
        :url="route('workspace')"
    >
        {{ __('ui.nav.workspace') }}
    </x-filament-panels::topbar.item>

    <x-filament-panels::topbar.item
        icon="heroicon-o-user-circle"
        :url="route('profile')"
    >
        {{ __('ui.nav.profile') }}
    </x-filament-panels::topbar.item>
    <li class="lp-admin-language"><x-language-switcher compact /></li>
</ul>
