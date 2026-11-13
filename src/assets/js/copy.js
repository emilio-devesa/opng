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

    function runHighlight() {
        if (!codeBlock || !window.hljs) return;
        if (highlightButton) highlightButton.disabled = true;
        setHighlightStatus("highlighting-message");

        // Give the browser one frame to paint the status before the
        // synchronous highlight.js pass starts (UX-13).
        window.requestAnimationFrame(function () {
            window.setTimeout(function () {
                try {
                    window.hljs.highlightElement(codeBlock);
                } catch (error) {
                    console.error("Could not highlight paste text:", error);
                    setHighlightStatus("highlight-error-message");
                    if (highlightButton) highlightButton.disabled = false;
                    return;
                }

                if (highlightButton) highlightButton.hidden = true;
                if (highlightNotice) highlightNotice.hidden = true;
                setHighlightStatus(null);
            }, 0);
        });
    }

    if (codeBlock && codeBlock.classList.contains("language-plaintext")) {
        // Nothing to highlight.
    } else if (codeBlock && !window.hljs) {
        console.error("Syntax highlighting library did not load.");
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
