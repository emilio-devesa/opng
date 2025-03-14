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
        fetch(`assets/lang/${lang}.json`)
            .then(response => response.json())
            .then(data => {
                themeToggle.textContent = document.body.classList.contains("dark-mode")
                    ? data.light_mode
                    : data.dark_mode;
            })
            .catch(error => console.error("Error loading language file:", error));
    }

    // Actualizar el botón cuando cambia el idioma
    document.addEventListener("languageChanged", updateThemeButtonText);

});