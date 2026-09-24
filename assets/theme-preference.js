// Restore the local UI preference before the first paint, independently of modules.
(() => {
    const root = document.documentElement;
    try {
        const preference = window.localStorage.getItem(root.dataset.themeStorageKey);
        if (preference === 'light' || preference === 'dark') {
            root.dataset.theme = preference;
            root.dataset.themePreference = preference;
        }
    } catch {
        // Restricted storage leaves the configured site theme in place.
    }
})();
