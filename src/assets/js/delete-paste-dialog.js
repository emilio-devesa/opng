document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const openButton = document.querySelector("[data-delete-paste-open]");
    if (!(openButton instanceof HTMLButtonElement)) {
        return;
    }

    const dialog = document.getElementById(openButton.getAttribute("aria-controls") || "");
    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    const form = dialog.querySelector("form");

    openButton.addEventListener("click", function () {
        if (typeof dialog.showModal === "function") {
            dialog.showModal();
        }
    });

    dialog.querySelectorAll("[data-delete-paste-close]").forEach(function (button) {
        button.addEventListener("click", function () {
            dialog.close();
        });
    });

    dialog.addEventListener("close", function () {
        // Never keep the password or a checked confirmation around after closing.
        if (form instanceof HTMLFormElement) {
            form.reset();
        }
        openButton.focus();
    });
});
