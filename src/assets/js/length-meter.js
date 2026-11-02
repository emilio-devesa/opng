// Live remaining-length meter for paste textareas.
// Counts UTF-8 bytes (the server limit is expressed in bytes) and updates a
// small progress bar below the textarea. It is intentionally independent from
// the i18n script: only the unit label is translated, while the numeric value
// is owned by this script.
(function () {
    "use strict";

    const BYTES_PER_MB = 1024 * 1024;

    function utf8ByteLength(value) {
        if (window.TextEncoder) {
            return new TextEncoder().encode(value).length;
        }

        return new Blob([value]).size;
    }

    function updateMeter(meter, textarea) {
        const maxBytes = Number(meter.dataset.maxBytes) || BYTES_PER_MB;
        const usedBytes = utf8ByteLength(textarea.value);
        const remainingBytes = Math.max(0, maxBytes - usedBytes);

        const progress = meter.querySelector(".length-meter-bar");
        if (progress instanceof HTMLProgressElement) {
            progress.max = maxBytes;
            progress.value = Math.min(usedBytes, maxBytes);
        }

        const value = meter.querySelector(".length-meter-value");
        if (value) {
            value.textContent = (remainingBytes / BYTES_PER_MB).toFixed(2);
        }

        meter.classList.toggle("over-limit", usedBytes > maxBytes);
    }

    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("[data-length-meter-for]").forEach(function (meter) {
            const targetId = meter.dataset.lengthMeterFor;
            const textarea = targetId ? document.getElementById(targetId) : null;
            if (!(textarea instanceof HTMLTextAreaElement)) return;

            updateMeter(meter, textarea);
            textarea.addEventListener("input", function () {
                updateMeter(meter, textarea);
            });
        });
    });
})();
