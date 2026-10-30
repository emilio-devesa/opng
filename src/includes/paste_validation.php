<?php

const OPNG_MAX_PASTE_BYTES = 1048576;
const OPNG_MAX_TOPIC_BYTES = 255;

/**
 * Validate paste fields and return a canonical error key, or null when valid.
 */
function paste_validation_error(string $text, string $topic): ?string
{
    if (trim($text) === '') {
        return 'error_empty_text';
    }
    if (strlen($text) > OPNG_MAX_PASTE_BYTES) {
        return 'error_text_too_large';
    }
    if (trim($topic) === '') {
        return 'error_topic_required';
    }
    if (strlen($topic) > OPNG_MAX_TOPIC_BYTES) {
        return 'error_topic_too_large';
    }

    return null;
}
