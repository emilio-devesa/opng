document.addEventListener("DOMContentLoaded", function () {
    const copyButton = document.getElementById("copy-button");
    const codeBlock = document.querySelector("pre code");
    if (!copyButton || !codeBlock) return; // Evita errores si no hay código

    // Función para copiar el texto al portapapeles
    function copyToClipboard() {
        const text = codeBlock.innerText;
        navigator.clipboard.writeText(text).then(() => {
            copyButton.textContent = copyButton.dataset.copied; // Mostrar "Copiado"
            setTimeout(() => updateCopyButtonText(), 2000); // Volver a "Copiar" después de 2s
        }).catch(err => console.error("Error al copiar:", err));
    }

    copyButton.addEventListener("click", copyToClipboard);

    // Función para actualizar el texto del botón según el idioma
    function updateCopyButtonText() {
        const lang = localStorage.getItem("lang") || "en";
        fetch(`assets/lang/${lang}.json`)
            .then(response => response.json())
            .then(data => {
                copyButton.textContent = data.copy;
                copyButton.dataset.copied = data.copied; // Guardar el texto "Copiado"
            })
            .catch(error => console.error("Error cargando idioma:", error));
    }

    // Cargar el texto correcto al iniciar la página
    updateCopyButtonText();

    // Escuchar el evento de cambio de idioma
    document.addEventListener("languageChanged", updateCopyButtonText);
});
