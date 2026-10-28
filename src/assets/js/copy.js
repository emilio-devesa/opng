document.addEventListener("DOMContentLoaded", function () {
    const copyButton = document.getElementById("copy-button");
    const codeBlock = document.getElementById("code-block");
    const status = document.getElementById("copy-status");
    const copiedMessage = document.getElementById("copy-copied-message");
    const errorMessage = document.getElementById("copy-error-message");

    if (!copyButton || !codeBlock || !status || !copiedMessage || !errorMessage) return;

    if (!window.hljs) {
        console.error("Syntax highlighting library did not load.");
    } else if (!codeBlock.classList.contains("language-plaintext")) {
        try {
            window.hljs.highlightElement(codeBlock);
        } catch (error) {
            console.error("Could not highlight paste text:", error);
        }
    }

    let statusMessageId = null;
    document.addEventListener("languageChanged", function () {
        if (statusMessageId) {
            status.textContent = document.getElementById(statusMessageId).textContent;
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
