// Modo oscuro.
// Se encapsula en una IIFE: los scripts clásicos comparten el ámbito global y
// antes este fichero y language.js declaraban ambos `const basePath`, lo que
// provocaba "Identifier 'basePath' has already been declared" y dejaba el
// segundo script (el del idioma) sin ejecutar.
(function () {
    "use strict";

    // Prefijo de rutas (capturado en tiempo de carga; en subdirectorios como admin/ o auth/)
    const basePath = (document.currentScript && document.currentScript.dataset.base) || "";

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

        function updateThemeButtonText() {
            const lang = localStorage.getItem("lang") || "en";
            fetch(`${basePath}assets/lang/${lang}.json`)
                .then(response => response.json())
                .then(data => {
                    const isDark = document.body.classList.contains("dark-mode");
                    const label = isDark ? data.light_mode : data.dark_mode;
                    const emoji = isDark ? "☀️" : "🌙";
                    if (label) {
                        themeToggle.textContent = emoji;
                        themeToggle.setAttribute("aria-label", label);
                        themeToggle.setAttribute("title", label);
                    }
                })
                .catch(error => console.error("Error loading language file:", error));
        }

        // Actualizar el botón cuando cambia el idioma
        document.addEventListener("languageChanged", updateThemeButtonText);
    });
})();
