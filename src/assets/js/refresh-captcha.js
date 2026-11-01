document.addEventListener("DOMContentLoaded", function () {
    const refreshButton = document.querySelector(".refresh-captcha");
    const captchaImage = document.querySelector(".captcha-image");

    if (refreshButton && captchaImage) {
        refreshButton.addEventListener("click", function () {
            // Derive the endpoint from the rendered image so the same script
            // works from auth/ (captcha.php) and from the root editor
            // (auth/captcha.php).
            const base = captchaImage.getAttribute("src").split("?")[0];
            captchaImage.src = base + "?" + Date.now();
        });
    }
});
