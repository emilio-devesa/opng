// Modo oscuro.
// Se encapsula en una IIFE: los scripts clásicos comparten el ámbito global y
// antes este fichero y language.js declaraban ambos `const basePath`, lo que
// provocaba "Identifier 'basePath' has already been declared" y dejaba el
// segundo script (el del idioma) sin ejecutar.
(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        const themeToggle = document.getElementById("theme-toggle");
        if (!themeToggle) return; // Evita errores si el botón no está presente

        const currentTheme = localStorage.getItem("theme") || "light";
        applyTheme(currentTheme);

        // Alternar entre los modos
        themeToggle.addEventListener("click", function () {
            const newTheme = document.body.classList.contains("dark-mode") ? "light" : "dark";
            applyTheme(newTheme);
            localStorage.setItem("theme", newTheme);
        });

        function applyTheme(theme) {
            document.body.classList.toggle("dark-mode", theme === "dark");
            updateThemeButtonText();
        }

        // Reuse the catalog language.js has already fetched. The server renders
        // the initial label, so no extra request is needed here (PERF-16).
        function updateThemeButtonText(catalog) {
            const shared = window.opngLanguageCatalog;
            const data = catalog || (shared && shared.data);
            if (!data) return;

            const isDark = document.body.classList.contains("dark-mode");
            const label = isDark ? data.light_mode : data.dark_mode;
            const emoji = isDark ? "☀️" : "🌙";
            if (label) {
                themeToggle.textContent = emoji;
                themeToggle.setAttribute("aria-label", label);
                themeToggle.setAttribute("title", label);
            }
        }

        // language.js dispatches the loaded catalog with the change event, so
        // the button is refreshed without a second fetch.
        document.addEventListener("languageChanged", function (event) {
            const catalog = event && event.detail ? event.detail.catalog : null;
            updateThemeButtonText(catalog);
        });
    });
})();
