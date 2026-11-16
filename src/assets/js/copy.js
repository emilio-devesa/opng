document.addEventListener("DOMContentLoaded", function () {
    const copyButton = document.getElementById("copy-button");
    const codeBlock = document.getElementById("code-block");
    const status = document.getElementById("copy-status");
    const copiedMessage = document.getElementById("copy-copied-message");
    const errorMessage = document.getElementById("copy-error-message");

    const highlightButton = document.getElementById("highlight-button");
    const highlightNotice = document.getElementById("highlight-notice");
    const highlightStatus = document.getElementById("highlight-status");
    const highlightingMessage = document.getElementById("highlighting-message");
    const highlightErrorMessage = document.getElementById("highlight-error-message");

    // Keep this value aligned with OPNG_MAX_AUTO_HIGHLIGHT_BYTES in view.php.
    const MAX_AUTO_HIGHLIGHT_BYTES = 200 * 1024;
    let statusMessageId = null;
    let highlightStatusMessageId = null;

    function utf8ByteLength(value) {
        if (window.TextEncoder) {
            return new TextEncoder().encode(value).length;
        }

        return new Blob([value]).size;
    }

    function setHighlightStatus(messageId) {
        if (!highlightStatus) return;
        highlightStatusMessageId = messageId;
        const message = messageId ? document.getElementById(messageId) : null;
        highlightStatus.textContent = message ? message.textContent : "";
        highlightStatus.classList.toggle("highlight-status-error", messageId === "highlight-error-message");
        highlightStatus.hidden = messageId === null;
    }

    function languageFromClass() {
        if (!codeBlock) return "";
        const match = (codeBlock.className || "").match(/\blanguage-([a-z0-9-]+)\b/);
        return match ? match[1] : "";
    }

    function finishHighlight() {
        if (highlightButton) {
            highlightButton.disabled = false;
            highlightButton.hidden = true;
        }
        if (highlightNotice) highlightNotice.hidden = true;
        setHighlightStatus(null);
    }

    function failHighlight(error) {
        console.error("Could not highlight paste text:", error);
        setHighlightStatus("highlight-error-message");
        if (highlightButton) highlightButton.disabled = false;
    }

    function highlightOnMainThread() {
        try {
            window.hljs.highlightElement(codeBlock);
        } catch (error) {
            failHighlight(error);
            return;
        }
        finishHighlight();
    }

    function runHighlight() {
        if (!codeBlock) return;
        if (highlightButton) highlightButton.disabled = true;
        setHighlightStatus("highlighting-message");

        // Give the browser one frame to paint the status before the work
        // starts (UX-13).
        window.requestAnimationFrame(function () {
            window.setTimeout(function () {
                const source = codeBlock.textContent || "";
                const language = languageFromClass();

                // The worker keeps a 1 MiB paste from blocking the main thread
                // (PERF-15/10.8). Fall back to the in-page bundle when Worker
                // or the script is unavailable.
                if (window.Worker) {
                    let worker;
                    try {
                        worker = new Worker("assets/js/highlight-worker.js");
                    } catch (error) {
                        worker = null;
                    }
                    if (worker) {
                        const fallback = function (error) {
                            worker.terminate();
                            if (window.hljs) {
                                highlightOnMainThread();
                            } else {
                                failHighlight(error);
                            }
                        };
                        worker.onmessage = function (event) {
                            worker.terminate();
                            const payload = event.data || {};
                            if (!payload.ok || typeof payload.html !== "string") {
                                failHighlight(new Error("Highlight worker failed."));
                                return;
                            }
                            codeBlock.innerHTML = payload.html;
                            codeBlock.classList.add("hljs");
                            codeBlock.removeAttribute("data-highlighted");
                            finishHighlight();
                        };
                        worker.onerror = fallback;
                        worker.postMessage({ code: source, language: language });
                        return;
                    }
                }

                if (!window.hljs) {
                    failHighlight(new Error("Syntax highlighting library did not load."));
                    return;
                }
                highlightOnMainThread();
            }, 0);
        });
    }

    if (codeBlock && codeBlock.classList.contains("language-plaintext")) {
        // Nothing to highlight.
    } else if (codeBlock) {
        const manual = highlightButton !== null && !highlightButton.hidden;
        if (manual) {
            highlightButton.addEventListener("click", runHighlight);
        } else if (utf8ByteLength(codeBlock.textContent || "") <= MAX_AUTO_HIGHLIGHT_BYTES) {
            runHighlight();
        }
    }

    if (!copyButton || !codeBlock || !status || !copiedMessage || !errorMessage) return;

    document.addEventListener("languageChanged", function () {
        if (statusMessageId) {
            status.textContent = document.getElementById(statusMessageId).textContent;
        }
        if (highlightStatusMessageId) {
            setHighlightStatus(highlightStatusMessageId);
        }
    });

    copyButton.addEventListener("click", async function () {
        status.textContent = "";
        statusMessageId = null;
        status.classList.remove("copy-status-error");

        if (!navigator.clipboard || !window.isSecureContext) {
            statusMessageId = "copy-error-message";
            status.textContent = errorMessage.textContent;
            status.classList.add("copy-status-error");
            return;
        }

        try {
            await navigator.clipboard.writeText(codeBlock.textContent || "");
            statusMessageId = "copy-copied-message";
            status.textContent = copiedMessage.textContent;
        } catch (error) {
            console.error("Could not copy paste text to the clipboard:", error);
            statusMessageId = "copy-error-message";
            status.textContent = errorMessage.textContent;
            status.classList.add("copy-status-error");
        }
    });
});
