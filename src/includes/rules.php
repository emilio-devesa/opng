<?php
// includes/rules.php
// Carga de la lista de lenguajes permitidos desde rules.xml.
// Centraliza la lectura que antes se repetía en pastebin.php, submit.php y view.php.

function load_rules(): array {
    $dom = new DOMDocument();
    if (!$dom->load(__DIR__ . "/../rules.xml")) {
        die("<p style='color: red;'>Error: No se pudo cargar rules.xml.</p>");
    }

    $rules = [];
    foreach ($dom->getElementsByTagName('RULE') as $rule) {
        $name = trim($rule->getAttribute('name'));
        if ($name !== '') {
            $rules[$name] = true;
        }
    }
    return $rules;
}
