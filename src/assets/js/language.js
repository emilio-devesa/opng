// Selector de idioma.
// Se encapsula en una IIFE: los scripts clásicos comparten el ámbito global y
// antes este fichero y dark-mode.js declaraban ambos `const basePath`, lo que
// provocaba "Identifier 'basePath' has already been declared" y hacía que este
// script no se ejecutara (el selector no cambiaba el idioma).
(function () {
    "use strict";

    // Prefijo de rutas (capturado en tiempo de carga; en subdirectorios como admin/ o auth/)
    const basePath = (document.currentScript && document.currentScript.dataset.base) || "";
    const languageVersion = (document.currentScript && document.currentScript.dataset.languageVersion) || "";
    const validLanguages = ["en", "es", "de", "fr", "pt", "zh"];
    const translationCache = new Map();

    function readLanguageCookie() {
        const match = document.cookie.match(/(?:^|;\s*)opng_lang=([^;]*)/);
        if (!match) return "";
        try {
            return decodeURIComponent(match[1]);
        } catch (error) {
            return "";
        }
    }

    function writeLanguageCookie(language) {
        const secure = window.location.protocol === "https:" ? "; Secure" : "";
        document.cookie = `opng_lang=${encodeURIComponent(language)}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
    }

    // The server sets <html lang> from the opng_lang cookie (then
    // Accept-Language). Prefer that value so the first paint and the
    // JavaScript state cannot disagree; fall back to localStorage for
    // visitors upgrading from versions that only stored the language there.
    let savedLang = readLanguageCookie();
    if (!validLanguages.includes(savedLang)) {
        savedLang = document.documentElement.lang || "";
    }
    if (!validLanguages.includes(savedLang)) {
        savedLang = localStorage.getItem("lang") || "en";
    }
    if (!validLanguages.includes(savedLang)) savedLang = "en";
    writeLanguageCookie(savedLang);
    localStorage.setItem("lang", savedLang);
    document.documentElement.lang = savedLang;

    document.addEventListener("DOMContentLoaded", function () {
        const languageSelector = document.getElementById("language-selector");
        if (!languageSelector) return; // Evitar errores si el selector no está presente

        // Aplicar el idioma guardado al selector y al documento
        languageSelector.value = savedLang;

        // Cargar traducciones y aplicarlas
        function loadTranslations(lang) {
            const cachedTranslations = translationCache.get(lang);
            if (cachedTranslations) {
                applyTranslations(cachedTranslations);
                return;
            }

            fetch(`${basePath}assets/lang/${lang}.json?v=${encodeURIComponent(languageVersion)}`, { cache: "force-cache" })
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    translationCache.set(lang, data);
                    applyTranslations(data);
                })
                .catch(error => console.error("Error cargando traducciones:", error));
        }

        function applyTranslations(data) {
            // Reemplazar textos en la página usando data del JSON.
            // textContent (no innerHTML): las traducciones son texto plano.
            document.querySelectorAll("[data-i18n]").forEach(el => {
                const key = el.getAttribute("data-i18n");
                if (!Object.prototype.hasOwnProperty.call(data, key)) return;
                // Do not destroy nested markup (for example an emoji plus a
                // visually-hidden label inside the same control).
                if (el.querySelector("*")) return;
                el.textContent = data[key];
            });
            ["aria-label", "placeholder", "title", "alt"].forEach(attribute => {
                document.querySelectorAll(`[data-i18n-${attribute}]`).forEach(el => {
                    const key = el.getAttribute(`data-i18n-${attribute}`);
                    if (Object.prototype.hasOwnProperty.call(data, key)) {
                        el.setAttribute(attribute, data[key]);
                    }
                });
            });
            document.dispatchEvent(new Event("languageChanged"));
        }

        // Aplicar traducciones al cargar la página
        loadTranslations(savedLang);

        // Manejar el cambio de idioma sin recargar la página
        languageSelector.addEventListener("change", function () {
            const selectedLang = this.value;
            if (!validLanguages.includes(selectedLang)) return;

            // Guardar en localStorage y en la cookie que usa el servidor para
            // renderizar el idioma antes del primer pintado (UX-04).
            localStorage.setItem("lang", selectedLang);
            writeLanguageCookie(selectedLang);
            savedLang = selectedLang;

            // Cambiar atributo lang de HTML
            document.documentElement.lang = selectedLang;

            // Cargar y aplicar nuevas traducciones
            loadTranslations(selectedLang);
        });
    });
})();
