// Ayuda de búsqueda como disclosure accesible: además de hover/focus en
// escritorio, un clic (o toque) abre y cierra la explicación, y Escape la cierra.
(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        const trigger = document.querySelector(".search-help-trigger");
        const help = document.getElementById("header-search-help");
        if (!trigger || !help) return;

        function setOpen(open) {
            trigger.setAttribute("aria-expanded", open ? "true" : "false");
            help.classList.toggle("is-open", open);
        }

        trigger.addEventListener("click", function (event) {
            event.preventDefault();
            setOpen(trigger.getAttribute("aria-expanded") !== "true");
        });

        document.addEventListener("click", function (event) {
            if (!help.classList.contains("is-open")) return;
            if (trigger.contains(event.target) || help.contains(event.target)) return;
            setOpen(false);
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && help.classList.contains("is-open")) {
                setOpen(false);
                trigger.focus();
            }
        });
    });
})();
