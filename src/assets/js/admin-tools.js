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
        const userField = document.getElementById("admin_record_number");
        if (userId && userField instanceof HTMLInputElement) {
            userField.value = userId;
            userField.dispatchEvent(new Event("input", { bubbles: true }));
        }
        if (userRole === "admin" || userRole === "user") {
            const roleOption = document.querySelector(`input[name="role"][value="${userRole}"]`);
            if (roleOption instanceof HTMLInputElement) {
                roleOption.checked = true;
            }
        }
        userField?.focus();
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
});

document.addEventListener("submit", function (event) {
    const form = event.target;
    const submitter = event.submitter;
    if (
        !(form instanceof HTMLFormElement)
        || !form.classList.contains("admin-user-actions")
        || !(submitter instanceof HTMLButtonElement)
        || submitter.value !== "delete"
    ) {
        return;
    }

    const confirmation = document.getElementById("user_action_confirm");
    if (!(confirmation instanceof HTMLElement)) {
        console.error("User deletion confirmation text is missing.");
        event.preventDefault();
        return;
    }
    if (!window.confirm(confirmation.textContent.trim())) {
        event.preventDefault();
    }
});
