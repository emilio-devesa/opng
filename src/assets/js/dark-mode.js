document.addEventListener("DOMContentLoaded", function () {
    const themeToggle = document.getElementById("theme-toggle");
    if (!themeToggle) return; // Evita errores si el botón no está presente

    const currentTheme = localStorage.getItem("theme");

    // Aplicar el tema guardado en localStorage
    if (currentTheme === "dark") {
        document.body.classList.add("dark-mode");
        themeToggle.textContent = "☀️ Modo Claro";
    }

    // Alternar entre los modos
    themeToggle.addEventListener("click", function () {
        document.body.classList.toggle("dark-mode");

        // Guardar la preferencia en localStorage
        if (document.body.classList.contains("dark-mode")) {
            localStorage.setItem("theme", "dark");
            themeToggle.textContent = "☀️ Modo Claro";
        } else {
            localStorage.setItem("theme", "light");
            themeToggle.textContent = "🌙 Modo Oscuro";
        }
    });
});
