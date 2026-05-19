// ════════════════════════════════════════════════════════════
// Theme Switcher
// ════════════════════════════════════════════════════════════

class ThemeSwitcher {
    constructor() {
        this.THEME_KEY = 'app-theme-preference';
        this.DARK_THEME = 'dark';
        this.LIGHT_THEME = 'light';
        this.init();
    }

    init() {
        // Load saved theme preference or use system preference
        const savedTheme = this.getSavedTheme();
        this.setTheme(savedTheme);
        
        // Listen for theme toggle button clicks
        document.addEventListener('click', (e) => {
            if (e.target.closest('.theme-toggle')) {
                this.toggleTheme();
            }
        });

        // Listen for system theme changes
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (!localStorage.getItem(this.THEME_KEY)) {
                    this.setTheme(e.matches ? this.DARK_THEME : this.LIGHT_THEME);
                }
            });
        }
    }

    getSavedTheme() {
        const saved = localStorage.getItem(this.THEME_KEY);
        if (saved) {
            return saved;
        }

        // Use system preference if available
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return this.DARK_THEME;
        }

        return this.DARK_THEME; // Default to dark theme
    }

    setTheme(theme) {
        if (theme === this.LIGHT_THEME) {
            document.documentElement.classList.add('light-theme');
            document.body.classList.add('light-theme');
        } else {
            document.documentElement.classList.remove('light-theme');
            document.body.classList.remove('light-theme');
        }

        // Save preference
        localStorage.setItem(this.THEME_KEY, theme);

        // Update toggle button icon if exists
        this.updateToggleButton();
    }

    toggleTheme() {
        const current = this.getCurrentTheme();
        const newTheme = current === this.DARK_THEME ? this.LIGHT_THEME : this.DARK_THEME;
        this.setTheme(newTheme);
    }

    getCurrentTheme() {
        return document.body.classList.contains('light-theme') ? this.LIGHT_THEME : this.DARK_THEME;
    }

    updateToggleButton() {
        const toggleBtn = document.querySelector('.theme-toggle');
        if (!toggleBtn) return;

        const icon = toggleBtn.querySelector('.theme-toggle-icon');
        const text = toggleBtn.querySelector('.theme-toggle-text');
        const currentTheme = this.getCurrentTheme();

        if (currentTheme === this.LIGHT_THEME) {
            icon.textContent = '🌙';
            if (text) text.textContent = 'Темна тема';
        } else {
            icon.textContent = '☀️';
            if (text) text.textContent = 'Світла тема';
        }
    }
}

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new ThemeSwitcher();
    });
} else {
    new ThemeSwitcher();
}
