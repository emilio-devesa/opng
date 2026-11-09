document.addEventListener("DOMContentLoaded", function () {
    const refreshButton = document.querySelector(".refresh-captcha");
    const captchaImage = document.querySelector(".captcha-image");
    const status = document.querySelector("[data-captcha-status]");
    const statusText = document.getElementById("captcha-refreshed-text");

    if (refreshButton && captchaImage) {
        // Derive the endpoint from the rendered image so the same script
        // works from auth/ (captcha.php) and from the root editor
        // (auth/captcha.php).
        const base = captchaImage.getAttribute("src").split("?")[0];
        let pendingRefresh = false;

        refreshButton.addEventListener("click", function () {
            pendingRefresh = true;
            if (status) {
                status.textContent = "";
            }
            captchaImage.src = base + "?" + Date.now();
        });

        captchaImage.addEventListener("load", function () {
            if (!pendingRefresh) return;
            pendingRefresh = false;
            // Announce the new challenge to screen readers; the hidden source
            // node is translated by language.js.
            if (status && statusText) {
                status.textContent = statusText.textContent;
            }
        });
    }

    // The accessible text challenge can be spoken with the browser's own
    // speech synthesis, so no external audio service is required.
    const listenButton = document.querySelector(".captcha-listen");
    const question = document.querySelector("[data-captcha-question]");
    if (
        listenButton
        && question
        && window.speechSynthesis
        && typeof window.SpeechSynthesisUtterance === "function"
    ) {
        listenButton.hidden = false;
        listenButton.addEventListener("click", function () {
            const spoken = question.textContent.replace("+", " plus ").replace("= ?", "");
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(new SpeechSynthesisUtterance(spoken));
        });
    }
});
