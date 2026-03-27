(function() {
    // Theme handling for The Bible plugin
    // Supports light, dark, and auto modes with localStorage persistence

    // Theme constants
    const THEME_KEY = 'dwbible-theme-mode';
    const THEME_LIGHT = 'light';
    const THEME_DARK = 'dark';
    const THEME_AUTO = 'auto';

    // Function to get the current theme from localStorage or default to auto
    function getThemePreference() {
        const savedTheme = localStorage.getItem(THEME_KEY);
        return savedTheme || THEME_AUTO;
    }

    // Function to save theme preference to localStorage
    function saveThemePreference(theme) {
        localStorage.setItem(THEME_KEY, theme);
    }

    // Function to detect system preference for dark mode
    function isSystemDarkMode() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    // Function to apply theme to document
    function applyTheme(theme) {
        // Remove any existing theme classes
        document.documentElement.classList.remove('theme-light', 'theme-dark');

        let effectiveTheme;
        
        // Determine the effective theme
        if (theme === THEME_AUTO) {
            effectiveTheme = isSystemDarkMode() ? THEME_DARK : THEME_LIGHT;
        } else {
            effectiveTheme = theme;
        }
        
        // Apply the theme class to the HTML element
        document.documentElement.classList.add('theme-' + effectiveTheme);
    }

    // Function to initialize theme
    function initTheme() {
        const theme = getThemePreference();
        applyTheme(theme);
        
        // Listen for system theme changes if in auto mode
        if (window.matchMedia) {
            const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            mediaQuery.addEventListener('change', function() {
                const currentTheme = getThemePreference();
                if (currentTheme === THEME_AUTO) {
                    applyTheme(THEME_AUTO);
                }
            });
        }
    }

    // Create theme toggle functionality for future use
    window.dwbibleTheme = {
        get: getThemePreference,
        set: function(theme) {
            if ([THEME_LIGHT, THEME_DARK, THEME_AUTO].includes(theme)) {
                saveThemePreference(theme);
                applyTheme(theme);
            }
        },
        toggle: function() {
            const current = getThemePreference();
            if (current === THEME_LIGHT) {
                this.set(THEME_DARK);
            } else if (current === THEME_DARK) {
                this.set(THEME_AUTO);
            } else {
                this.set(THEME_LIGHT);
            }
        }
    };

    // Initialize theme as soon as possible
    initTheme();

    // Also initialize on DOMContentLoaded to ensure it works with all browsers
    document.addEventListener('DOMContentLoaded', initTheme);
})();
