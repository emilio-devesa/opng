<?php

const OPNG_MAX_PASTE_BYTES = 1048576;
const OPNG_MAX_TOPIC_BYTES = 255;

function paste_validation_error(string $text, string $topic): ?string
{
    if (trim($text) === '') {
        return 'The text cannot be empty.';
    }
    if (strlen($text) > OPNG_MAX_PASTE_BYTES) {
        return 'The text cannot exceed 1 MiB.';
    }
    if (trim($topic) === '') {
        return 'The topic is required.';
    }
    if (strlen($topic) > OPNG_MAX_TOPIC_BYTES) {
        return 'The topic cannot exceed 255 bytes.';
    }

    return null;
}
