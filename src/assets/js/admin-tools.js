(function () {
    "use strict";

    function resetDialogState(dialog) {
        dialog.querySelectorAll('input[type="password"]').forEach(function (field) {
            field.value = "";
        });
        dialog.querySelectorAll('input[type="checkbox"]').forEach(function (field) {
            field.checked = false;
        });
    }

    function focusFirstField(dialog) {
        const field = dialog.querySelector(
            'input[type="text"], input[type="number"], input[type="email"], ' +
            'input[type="search"], input[type="date"], input[type="password"]'
        );
        if (field instanceof HTMLInputElement) {
            field.focus();
        }
    }

    function openAdminDialog(dialog) {
        // Always start from a clean confirmation state, but keep the values
        // that the search tables prefilled (entry and user identifiers).
        resetDialogState(dialog);
        if (typeof dialog.showModal === "function") {
            dialog.showModal();
        }
        focusFirstField(dialog);
    }

    document.addEventListener("click", function (event) {
        if (!(event.target instanceof Element)) return;

        const clearButton = event.target.closest(".admin-field-clear");
        if (clearButton instanceof HTMLButtonElement) {
            const wrapper = clearButton.closest(".admin-input-wrap");
            const input = wrapper?.querySelector("input");
            if (input instanceof HTMLInputElement) {
                input.value = "";
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.focus();
            }
            return;
        }

        const closeButton = event.target.closest("[data-admin-dialog-close]");
        if (closeButton instanceof HTMLButtonElement) {
            const dialog = closeButton.closest("dialog");
            if (dialog instanceof HTMLDialogElement) {
                dialog.close();
            }
            return;
        }

        const openButton = event.target.closest("[data-admin-dialog-open]");
        if (openButton instanceof HTMLButtonElement) {
            const dialogId = openButton.getAttribute("aria-controls");
            const dialog = dialogId ? document.getElementById(dialogId) : null;
            if (dialog instanceof HTMLDialogElement) {
                openAdminDialog(dialog);
            }
            return;
        }

        const entryButton = event.target.closest("[data-select-entry]");
        if (entryButton instanceof HTMLButtonElement) {
            const entryId = entryButton.dataset.selectEntry;
            const entryField = document.getElementById("input_ID");
            if (entryId && entryField instanceof HTMLInputElement) {
                entryField.value = entryId;
                entryField.dispatchEvent(new Event("input", { bubbles: true }));
                entryField.focus();
            }
            return;
        }

        const userButton = event.target.closest("[data-select-user]");
        if (userButton instanceof HTMLButtonElement) {
            const userId = userButton.dataset.selectUser;
            const userRole = userButton.dataset.userRole;
            if (userId) {
                // The role form and the deletion form are independent POST
                // forms, so both identifiers must be kept in sync.
                ["admin_record_number", "delete_user_id"].forEach(function (id) {
                    const field = document.getElementById(id);
                    if (field instanceof HTMLInputElement) {
                        field.value = userId;
                        field.dispatchEvent(new Event("input", { bubbles: true }));
                    }
                });
                document.getElementById("admin_record_number")?.focus();
            }
            if (userRole === "admin" || userRole === "user") {
                const roleOption = document.querySelector(`input[name="role"][value="${userRole}"]`);
                if (roleOption instanceof HTMLInputElement) {
                    roleOption.checked = true;
                }
            }
        }
    });

    document.addEventListener("input", function (event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;

        const wrapper = input.closest(".admin-input-wrap");
        const clearButton = wrapper?.querySelector(".admin-field-clear");
        if (clearButton instanceof HTMLButtonElement) {
            clearButton.hidden = input.value.length === 0;
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll(".admin-input-wrap input").forEach(function (element) {
            if (!(element instanceof HTMLInputElement)) return;

            const clearButton = element.closest(".admin-input-wrap")?.querySelector(".admin-field-clear");
            if (clearButton instanceof HTMLButtonElement) {
                clearButton.hidden = element.value.length === 0;
            }
        });

        document.querySelectorAll("dialog.admin-action-dialog").forEach(function (dialog) {
            if (!(dialog instanceof HTMLDialogElement)) return;

            // Escape and the Cancel button both close the dialog; wipe the
            // password and confirmation so reopening never resubmits them.
            dialog.addEventListener("close", function () {
                resetDialogState(dialog);
                const trigger = document.querySelector(
                    `[data-admin-dialog-open][aria-controls="${dialog.id}"]`
                );
                if (trigger instanceof HTMLButtonElement) {
                    trigger.focus();
                }
            });
        });
    });
})();
