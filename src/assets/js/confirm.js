document.addEventListener("submit", function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirmTarget) return;

    const confirmation = document.getElementById(form.dataset.confirmTarget);
    if (!confirmation) {
        console.error("Confirmation text is missing for a protected form.");
        event.preventDefault();
        return;
    }

    if (!window.confirm(confirmation.textContent.trim())) {
        event.preventDefault();
    }
});
