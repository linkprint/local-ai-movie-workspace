<style>
    .lp-portal-menu-mobile {
        display: flex;
        align-items: center;
    }

    .lp-admin-language { display: flex; align-items: center; margin-inline-start: .5rem; }
    .lp-admin-language-mobile { padding: .5rem 1rem; }
    .lp-admin-language .language-switcher, .lp-admin-language-mobile .language-switcher { display: inline-flex; align-items: center; border: 1px solid rgb(148 163 184 / .25); border-radius: 9999px; padding: 2px; }
    .lp-admin-language .language-switcher-option, .lp-admin-language-mobile .language-switcher-option { border-radius: 9999px; padding: 4px 9px; color: rgb(148 163 184); font-size: 12px; }
    .lp-admin-language .language-switcher-option.is-active, .lp-admin-language-mobile .language-switcher-option.is-active { background: rgb(252 211 77); color: rgb(15 23 42); font-weight: 600; }

    @media (min-width: 1024px) {
        .lp-portal-menu-mobile {
            display: none;
        }
    }
</style>
