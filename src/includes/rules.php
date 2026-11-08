<?php

declare(strict_types=1);

// includes/rules.php
// Loads the allowed language list from rules.xml.
// Centralizes the lookup that used to be duplicated in pastebin.php, submit.php and view.php.

function load_rules(): array
{
    $dom = new DOMDocument();
    $rules_path = __DIR__ . "/../rules.xml";
    $previous_error_mode = libxml_use_internal_errors(true);
    try {
        $loaded = $dom->load($rules_path);
        $xml_errors = libxml_get_errors();
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous_error_mode);
    }
    if (!$loaded) {
        $detail = isset($xml_errors[0]) ? trim($xml_errors[0]->message) : 'unknown XML error';
        error_log("OPNG rules.php: failed to load rules.xml: " . $detail);
        throw new RuntimeException("Language configuration is unavailable.");
    }

    $rules = [];
    foreach ($dom->getElementsByTagName('RULE') as $rule) {
        $name = trim($rule->getAttribute('name'));
        if ($name !== '') {
            $rules[$name] = true;
        }
    }
    if ($rules === []) {
        error_log("OPNG rules.php: rules.xml contains no valid language rules");
        throw new RuntimeException("Language configuration is unavailable.");
    }
    return $rules;
}
