(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        const searchInput = document.getElementById("header-search");
        const clearButton = document.querySelector(".search-clear");
        if (!(searchInput instanceof HTMLInputElement) || !(clearButton instanceof HTMLButtonElement)) {
            return;
        }

        function updateClearButton() {
            clearButton.hidden = searchInput.value.length === 0;
            searchInput.parentElement?.classList.toggle("has-value", searchInput.value.length > 0);
        }

        searchInput.addEventListener("input", updateClearButton);
        clearButton.addEventListener("click", function () {
            searchInput.value = "";
            updateClearButton();
            searchInput.form?.requestSubmit();
        });

        updateClearButton();
    });
})();
