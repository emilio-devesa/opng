// Code font-size control (UX-10).
// Applies one of three CSS classes to <html> and remembers the choice in
// localStorage. The classes are static so the strict CSP never needs an inline
// style attribute.
(function () {
    "use strict";

    var STORAGE_KEY = "opng-code-font-size";
    var SIZES = ["small", "medium", "large"];

    function applySize(size) {
        document.documentElement.classList.remove(
            "code-font-small",
            "code-font-medium",
            "code-font-large"
        );
        document.documentElement.classList.add("code-font-" + size);
    }

    function readSize() {
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (SIZES.indexOf(stored) !== -1) {
                return stored;
            }
        } catch (error) {
            // Storage can be unavailable in private or hardened browsers.
        }
        return "medium";
    }

    function saveSize(size) {
        try {
            localStorage.setItem(STORAGE_KEY, size);
        } catch (error) {
            // The class still applies for this page view.
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        var controls = document.querySelector(".code-font-controls");
        if (!controls) return;

        var decrease = controls.querySelector(".code-font-decrease");
        var reset = controls.querySelector(".code-font-reset");
        var increase = controls.querySelector(".code-font-increase");

        applySize(readSize());

        function step(delta) {
            var current = readSize();
            var index = SIZES.indexOf(current);
            var next = SIZES[Math.max(0, Math.min(SIZES.length - 1, index + delta))];
            applySize(next);
            saveSize(next);
        }

        if (decrease) {
            decrease.addEventListener("click", function () {
                step(-1);
            });
        }
        if (reset) {
            reset.addEventListener("click", function () {
                applySize("medium");
                saveSize("medium");
            });
        }
        if (increase) {
            increase.addEventListener("click", function () {
                step(1);
            });
        }
    });
})();
