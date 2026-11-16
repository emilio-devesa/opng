// Syntax-highlighting worker (10.8).
//
// Large pastes are highlighted off the main thread. The worker loads the same
// highlight.js bundle as the page and returns the escaped HTML fragment.
"use strict";

importScripts("../vendor/highlight.min.js");

self.onmessage = function (event) {
    const payload = event.data || {};
    const code = typeof payload.code === "string" ? payload.code : "";
    const language = typeof payload.language === "string" ? payload.language : "";

    try {
        let result;
        if (language !== "" && self.hljs.getLanguage(language)) {
            result = self.hljs.highlight(code, { language: language, ignoreIllegals: true });
        } else {
            result = self.hljs.highlightAuto(code);
        }
        self.postMessage({
            ok: true,
            html: result.value,
            language: result.language || language
        });
    } catch (error) {
        self.postMessage({ ok: false });
    }
};
