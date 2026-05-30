// ════════════════════════════════════════════════════════════
// Theme Switcher — швидка версія без затримок
// ════════════════════════════════════════════════════════════

(function () {
    var THEME_KEY = 'app-theme-preference';

    // Зчитуємо збережену тему одразу — до рендеру сторінки
    function getSavedTheme() {
        var saved = localStorage.getItem(THEME_KEY);
        if (saved) return saved;
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'dark'; // default
    }

    // Застосовуємо тему до body і html миттєво
    function applyTheme(theme) {
        if (theme === 'light') {
            document.documentElement.classList.add('light-theme');
            document.body.classList.add('light-theme');
            document.documentElement.classList.remove('dark-theme');
            document.body.classList.remove('dark-theme');
        } else {
            document.documentElement.classList.remove('light-theme');
            document.body.classList.remove('light-theme');
            document.documentElement.classList.add('dark-theme');
            document.body.classList.add('dark-theme');
        }
        localStorage.setItem(THEME_KEY, theme);
    }

    // Ранній запуск — до DOMContentLoaded — щоб уникнути flash
    var earlyTheme = getSavedTheme();
    if (earlyTheme === 'light') {
        document.documentElement.classList.add('light-theme');
    }

    // Повна ініціалізація після завантаження DOM
    function init() {
        // Застосовуємо тему на body
        applyTheme(earlyTheme);

        // Оновлюємо іконку кнопки
        updateToggleButtons();

        // Слухаємо кліки на всіх кнопках перемикання
        document.addEventListener('click', function (e) {
            if (e.target.closest('.theme-toggle')) {
                var current = document.body.classList.contains('light-theme') ? 'light' : 'dark';
                var next = current === 'dark' ? 'light' : 'dark';
                applyTheme(next);
                updateToggleButtons();
            }
        });

        // Слухаємо зміни системної теми
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
                if (!localStorage.getItem(THEME_KEY)) {
                    applyTheme(e.matches ? 'dark' : 'light');
                    updateToggleButtons();
                }
            });
        }
    }

    function updateToggleButtons() {
        var isLight = document.body.classList.contains('light-theme');
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            var icon = btn.querySelector('.theme-toggle-icon');
            var text = btn.querySelector('.theme-toggle-text');
            if (icon) {
                icon.textContent = isLight ? '🌙' : '☀️';
            }
            if (text) {
                text.textContent = isLight ? 'Темна тема' : 'Світла тема';
            }
            btn.setAttribute('title', isLight ? 'Перемкнути на темну тему' : 'Перемкнути на світлу тему');
        });
    }

    // Запускаємо після DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();