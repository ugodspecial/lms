/*
|--------------------------------------------------------------------------
| Application JavaScript
|--------------------------------------------------------------------------
|
| Alpine.js is NOT imported here. Livewire 4 ships its own Alpine build and
| boots it automatically on any page containing a Livewire component; loading a
| second copy produces two competing Alpine instances and silently broken
| x-data bindings. Interactivity therefore comes from Livewire components, or
| from the small amount of framework-free JS below, which must work on pages
| that never load Livewire at all.
|
| Keep this file small. Anything that is really a UI behaviour belongs in a
| Livewire component or an Alpine directive next to the markup it controls.
|
*/

/**
 * Theme preference (light / dark / system).
 *
 * Persisted in localStorage and applied by an inline script in the document
 * <head> BEFORE first paint, so a dark-mode user never sees a white flash. The
 * `.dark` class here is the same one the `dark:` Tailwind variant targets
 * (see @custom-variant in resources/css/app.css).
 */
const STORAGE_KEY = 'platform-theme';

const readPreference = () => {
    try {
        return localStorage.getItem(STORAGE_KEY) || 'system';
    } catch {
        // Private browsing modes can throw on localStorage access. The theme is
        // a preference, never a reason to break the page.
        return 'system';
    }
};

const systemPrefersDark = () =>
    window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

export const applyTheme = (preference) => {
    const dark = preference === 'dark' || (preference === 'system' && systemPrefersDark());

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';

    // Kept in sync so assistive tech and native form controls follow the theme.
    const meta = document.querySelector('meta[name="color-scheme"]');
    if (meta) {
        meta.setAttribute('content', dark ? 'dark light' : 'light dark');
    }

    return dark;
};

export const setTheme = (preference) => {
    const allowed = ['light', 'dark', 'system'];
    const next = allowed.includes(preference) ? preference : 'system';

    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch {
        /* ignore — the choice simply will not persist */
    }

    applyTheme(next);

    return next;
};

const bootTheme = () => {
    applyTheme(readPreference());

    // Follow the OS while the preference is "system", including mid-session.
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (readPreference() === 'system') {
                applyTheme('system');
            }
        });
    }

    // Delegated listeners rather than Alpine so these controls work on pages
    // that never load Livewire, and survive content Livewire swaps in later.
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (! target) {
            return;
        }

        if (target.closest('[data-theme-toggle]')) {
            const cycle = { light: 'dark', dark: 'system', system: 'light' };

            setTheme(cycle[readPreference()] ?? 'system');

            return;
        }

        // <x-alert dismissible> — removing the node is enough; the notice is a
        // session flash and is not re-rendered until the next request.
        const dismiss = target.closest('[data-dismiss]');

        if (dismiss) {
            dismiss.closest('[data-dismissible]')?.remove();
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootTheme);
} else {
    bootTheme();
}
