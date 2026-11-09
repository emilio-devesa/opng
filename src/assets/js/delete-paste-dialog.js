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
    const confirmation = dialog.querySelector('input[name="confirm_delete"]');
    const password = dialog.querySelector('input[name="current_password"]');

    openButton.addEventListener("click", function () {
        // Start every confirmation from a clean state, even if the browser
        // restored a previous form value after a reload.
        if (form instanceof HTMLFormElement) {
            form.reset();
        }
        if (confirmation instanceof HTMLInputElement) {
            confirmation.checked = false;
        }
        if (password instanceof HTMLInputElement) {
            password.value = "";
        }

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
