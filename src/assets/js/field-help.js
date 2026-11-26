// Ayuda contextual de un campo: hover/focus la muestran en escritorio y un
// clic o toque la abre y cierra. Solo puede haber una abierta a la vez y Escape
// devuelve el foco al botón, igual que la ayuda del buscador.
(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        const triggers = Array.prototype.slice.call(
            document.querySelectorAll("[data-help-trigger]")
        );
        if (triggers.length === 0) return;

        function helpFor(trigger) {
            const id = trigger.getAttribute("aria-controls");
            return id ? document.getElementById(id) : null;
        }

        function setOpen(trigger, open) {
            const help = helpFor(trigger);
            if (!help) return;
            trigger.setAttribute("aria-expanded", open ? "true" : "false");
            help.classList.toggle("is-open", open);
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener("click", function (event) {
                event.preventDefault();
                const open = trigger.getAttribute("aria-expanded") !== "true";
                triggers.forEach(function (other) {
                    if (other !== trigger) setOpen(other, false);
                });
                setOpen(trigger, open);
            });
        });

        document.addEventListener("click", function (event) {
            triggers.forEach(function (trigger) {
                if (trigger.getAttribute("aria-expanded") !== "true") return;
                const help = helpFor(trigger);
                if (trigger.contains(event.target) || (help && help.contains(event.target))) return;
                setOpen(trigger, false);
            });
        });

        document.addEventListener("keydown", function (event) {
            if (event.key !== "Escape") return;
            triggers.forEach(function (trigger) {
                if (trigger.getAttribute("aria-expanded") !== "true") return;
                setOpen(trigger, false);
                trigger.focus();
            });
        });
    });
})();
