document.addEventListener("DOMContentLoaded", function () {
    const languageSelector = document.getElementById("language-selector");
    if (!languageSelector) return; // Evitar errores si el selector no está presente

    // Idiomas disponibles
    const validLanguages = ["en", "es", "de", "fr", "pt", "zh"];

    // Obtener idioma guardado en localStorage, si no existe, usar "en"
    let savedLang = localStorage.getItem("lang") || "en";
    if (!validLanguages.includes(savedLang)) savedLang = "en";

    // Aplicar el idioma guardado al selector y al documento
    languageSelector.value = savedLang;
    document.documentElement.lang = savedLang;

    // Cargar traducciones y aplicarlas
    function loadTranslations(lang) {
        fetch(`assets/lang/${lang}.json`)
            .then(response => response.json())
            .then(data => {
                // Reemplazar textos en la página usando data del JSON
                document.querySelectorAll("[data-i18n]").forEach(el => {
                    const key = el.getAttribute("data-i18n");
                    if (data[key]) el.innerHTML = data[key];
                });
                // Disparar evento de cambio de idioma
                document.dispatchEvent(new Event("languageChanged"));
            })
            .catch(error => console.error("Error cargando traducciones:", error));
    }

    // Aplicar traducciones al cargar la página
    loadTranslations(savedLang);

    // Manejar el cambio de idioma sin recargar la página
    languageSelector.addEventListener("change", function () {
        let selectedLang = this.value;
        if (!validLanguages.includes(selectedLang)) return;

        // Guardar en localStorage
        localStorage.setItem("lang", selectedLang);

        // Cambiar atributo lang de HTML
        document.documentElement.lang = selectedLang;

        // Cargar y aplicar nuevas traducciones
        loadTranslations(selectedLang);
    });
});
