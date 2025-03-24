document.addEventListener("DOMContentLoaded", function () {
    const refreshButton = document.querySelector(".refresh-captcha");
    const captchaImage = document.querySelector(".captcha-image");

    if (refreshButton && captchaImage) {
        refreshButton.addEventListener("click", function () {
            captchaImage.src = 'captcha.php?' + Date.now();
        });
    }
});
