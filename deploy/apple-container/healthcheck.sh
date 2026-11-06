#!/bin/sh
# Open Pastebin NG - container healthcheck (F3).
# Returns 0 only when Nginx answers and /healthz.php reports status ok.
set -eu

response="$(curl -fsS --max-time 5 http://127.0.0.1:8080/healthz.php 2>/dev/null)" || {
    echo "healthcheck: /healthz.php did not return HTTP 200" >&2
    exit 1
}

case "$response" in
    *'"status":"ok"'*)
        exit 0
        ;;
    *)
        echo "healthcheck: unexpected response: $response" >&2
        exit 1
        ;;
esac
