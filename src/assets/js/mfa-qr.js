// MFA enrollment QR code.
//
// Renders the otpauth:// URI from the data attribute into an SVG QR code with
// the vendored qrcode-generator library. The secret key and setup URI remain
// available in the manual setup block when JavaScript is disabled or when
// generation fails, so enrollment never depends on this script.
(function () {
    "use strict";

    function render(container) {
        const data = container.getAttribute("data-otpauth");
        if (!data || typeof window.qrcode !== "function") {
            return;
        }

        const label = container.getAttribute("data-label") || "QR code";
        try {
            const qr = window.qrcode(0, "M");
            qr.addData(data);
            qr.make();
            container.innerHTML = qr.createSvgTag({
                cellSize: 4,
                margin: 16,
                scalable: true,
                alt: label,
                title: label
            });
        } catch (error) {
            container.hidden = true;
            if (window.console && typeof window.console.error === "function") {
                window.console.error("OPNG MFA QR generation failed", error);
            }
        }
    }

    function init() {
        document.querySelectorAll(".mfa-qr[data-otpauth]").forEach(render);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
