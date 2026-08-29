<div class="lp-portal-menu-mobile">
    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <x-filament::button
                color="gray"
                icon="heroicon-o-arrow-left-end-on-rectangle"
                outlined
                size="sm"
            >
                {{ __('ui.admin.portal') }}
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :href="route('dashboard')"
                icon="heroicon-o-home"
                tag="a"
            >
                {{ __('ui.admin.portal_home') }}
            </x-filament::dropdown.list.item>

            <x-filament::dropdown.list.item
                :href="route('reservations.index')"
                icon="heroicon-o-calendar-days"
                tag="a"
            >
                {{ __('ui.nav.reservations') }}
            </x-filament::dropdown.list.item>

            <x-filament::dropdown.list.item
                :href="route('workspace')"
                icon="heroicon-o-command-line"
                tag="a"
            >
                {{ __('ui.nav.workspace') }}
            </x-filament::dropdown.list.item>

            <x-filament::dropdown.list.item
                :href="route('profile')"
                icon="heroicon-o-user-circle"
                tag="a"
            >
                {{ __('ui.nav.profile') }}
            </x-filament::dropdown.list.item>
            <div class="lp-admin-language-mobile"><x-language-switcher compact /></div>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
