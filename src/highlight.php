<?php
require("rule.php");

function apply_rule($rdata, $text) {
    // Crear una nueva regla desde los datos de reglas (rdata)
    $rule = new CRule($rdata);
    
    // Obtener las coincidencias del texto según la regla
    $matches = $rule->get_matches($text);
    
    // Ordenar las coincidencias por su posición y, si es necesario, por su índice
    usort($matches, function ($a, $b) {
        if ($a['offset'] === $b['offset']) {
            return $a['index'] <=> $b['index'];
        }
        return $a['offset'] <=> $b['offset'];
    });

    // Variables para rastrear posiciones en el texto
    $pos = 0;
    $adjust = 0;

    // Recorrer las coincidencias para aplicar los reemplazos
    foreach ($matches as $match) {
        // Si la posición actual del match es mayor o igual que la posición rastreada
        if ($match['offset'] >= $pos) {
            // Actualizar la posición
            $pos = $match['offset'];
            // Reemplazar la parte del texto correspondiente por el texto resaltado
            $text = substr_replace($text, $match['replace'], $pos + $adjust, $match['strlen']);
            // Ajustar las posiciones para futuros reemplazos
            $pos += $match['strlen'];
            $adjust += strlen($match['replace']) - $match['strlen'];
        }
    }
    
    return $text;
}
?>
