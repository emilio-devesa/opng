// Selector de idioma.
// Se encapsula en una IIFE: los scripts clásicos comparten el ámbito global y
// antes este fichero y dark-mode.js declaraban ambos `const basePath`, lo que
// provocaba "Identifier 'basePath' has already been declared" y hacía que este
// script no se ejecutara (el selector no cambiaba el idioma).
(function () {
    "use strict";

    // Prefijo de rutas (capturado en tiempo de carga; en subdirectorios como admin/ o auth/)
    const basePath = (document.currentScript && document.currentScript.dataset.base) || "";
    const validLanguages = ["en", "es", "de", "fr", "pt", "zh"];
    let savedLang = localStorage.getItem("lang") || "en";
    if (!validLanguages.includes(savedLang)) savedLang = "en";
    document.documentElement.lang = savedLang;

    document.addEventListener("DOMContentLoaded", function () {
        const languageSelector = document.getElementById("language-selector");
        if (!languageSelector) return; // Evitar errores si el selector no está presente

        // Aplicar el idioma guardado al selector y al documento
        languageSelector.value = savedLang;

        // Cargar traducciones y aplicarlas
        function loadTranslations(lang) {
            fetch(`${basePath}assets/lang/${lang}.json`)
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    // Reemplazar textos en la página usando data del JSON.
                    // textContent (no innerHTML): las traducciones son texto plano.
                    document.querySelectorAll("[data-i18n]").forEach(el => {
                        const key = el.getAttribute("data-i18n");
                        if (Object.prototype.hasOwnProperty.call(data, key)) {
                            el.textContent = data[key];
                        }
                    });
                    ["aria-label", "placeholder", "title", "alt"].forEach(attribute => {
                        document.querySelectorAll(`[data-i18n-${attribute}]`).forEach(el => {
                            const key = el.getAttribute(`data-i18n-${attribute}`);
                            if (Object.prototype.hasOwnProperty.call(data, key)) {
                                el.setAttribute(attribute, data[key]);
                            }
                        });
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
            const selectedLang = this.value;
            if (!validLanguages.includes(selectedLang)) return;

            // Guardar en localStorage
            localStorage.setItem("lang", selectedLang);
            savedLang = selectedLang;

            // Cambiar atributo lang de HTML
            document.documentElement.lang = selectedLang;

            // Cargar y aplicar nuevas traducciones
            loadTranslations(selectedLang);
        });
    });
})();
