// Day/night theme: defaults to the OS/browser preference (prefers-color-scheme),
// but a user's explicit choice (stored in localStorage) always wins over it.
// The <html> "dark" class is also set synchronously in app.blade.php (before
// Vue mounts) to avoid a flash of the wrong theme on load; this module keeps
// that class in sync afterwards and exposes the toggle used by the UI.
const STORAGE_KEY = 'theme';
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function getStoredTheme() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        return null;
    }
}

function resolveIsDark(stored) {
    return stored === 'dark' || (stored !== 'light' && !!media?.matches);
}

function apply(isDark) {
    document.documentElement.classList.toggle('dark', isDark);
    return isDark;
}

export function isDarkMode() {
    return document.documentElement.classList.contains('dark');
}

export function hasExplicitTheme() {
    return getStoredTheme() !== null;
}

export function setTheme(theme) {
    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {
        /* storage unavailable, ignore */
    }
    return apply(theme === 'dark');
}

export function toggleTheme() {
    return setTheme(isDarkMode() ? 'light' : 'dark');
}

// Call once from the app's root layout: keeps the theme in sync with OS
// changes (only while the user hasn't explicitly overridden it) and returns
// an unsubscribe function.
export function watchSystemTheme(onChange) {
    if (!media) return () => {};
    const handler = (e) => {
        if (!hasExplicitTheme()) {
            apply(e.matches);
            onChange?.(e.matches);
        }
    };
    media.addEventListener('change', handler);
    return () => media.removeEventListener('change', handler);
}

export function initTheme() {
    return apply(resolveIsDark(getStoredTheme()));
}
