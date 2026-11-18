// Virtualized line-number gutter for very large pastes (10.8).
//
// The server renders a single <pre> with one number per line, which is already
// much cheaper than one element per line. For pastes above the threshold this
// script keeps only the numbers near the viewport in the DOM, using the shared
// line height to keep them aligned with the code.
(function () {
    "use strict";

    const MIN_LINES = 1000;
    const OVERSCAN = 20;

    function init() {
        const gutter = document.querySelector(".code-view .line-numbers");
        const code = document.getElementById("code-block");
        if (!gutter || !code) return;

        const totalLines = (gutter.textContent || "").split("\n").length;
        if (totalLines < MIN_LINES) return;

        const codeStyle = window.getComputedStyle(code);
        let lineHeight = parseFloat(codeStyle.lineHeight);
        if (!isFinite(lineHeight) || lineHeight <= 0) {
            lineHeight = parseFloat(codeStyle.fontSize) * 1.6;
        }
        if (!isFinite(lineHeight) || lineHeight <= 0) return;

        const gutterStyle = window.getComputedStyle(gutter);
        const basePaddingTop = parseFloat(gutterStyle.paddingTop) || 0;
        let frame = null;

        function render() {
            frame = null;
            const rect = code.getBoundingClientRect();
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            const firstVisible = Math.floor((0 - rect.top) / lineHeight);
            const lastVisible = Math.ceil((viewportHeight - rect.top) / lineHeight);
            const start = Math.max(0, firstVisible - OVERSCAN);
            const end = Math.min(totalLines, lastVisible + OVERSCAN);
            if (end <= start) return;

            const numbers = [];
            for (let line = start + 1; line <= end; line++) {
                numbers.push(line);
            }
            gutter.textContent = numbers.join("\n");
            gutter.style.paddingTop = (basePaddingTop + start * lineHeight) + "px";
        }

        function schedule() {
            if (frame === null) {
                frame = window.requestAnimationFrame(render);
            }
        }

        render();
        window.addEventListener("scroll", schedule, { passive: true });
        window.addEventListener("resize", schedule);
        document.addEventListener("languageChanged", schedule);
    }

    document.addEventListener("DOMContentLoaded", init);
})();
