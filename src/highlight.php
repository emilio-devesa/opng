<?php
require("rule.php");

function apply_rule($rdata, $text) {
    // Crear una nueva regla desde los datos de reglas (rdata)
    $rule = new CRule($rdata);
    
    // Obtener las coincidencias del texto según la regla
    $matches = $rule->get_matches($text);

    // Verificar que `get_matches()` devolvió un array válido
    if (!is_array($matches) || empty($matches)) {
        return htmlspecialchars($text); // Si no hay coincidencias, devolver el texto con seguridad
    }

    // Ordenar las coincidencias por `offset` y `index` (evita reemplazos solapados)
    usort($matches, function ($a, $b) {
        return ($a['offset'] === $b['offset']) ? $a['index'] <=> $b['index'] : $a['offset'] <=> $b['offset'];
    });

    // Construcción segura del texto resaltado
    $result = "";
    $pos = 0;
    $adjust = 0; // Ajuste en posiciones debido a cambios en la longitud del texto

    foreach ($matches as $match) {
        // Validar que la coincidencia tiene los datos necesarios
        if (!isset($match['offset'], $match['replace'], $match['strlen'])) {
            continue; // Evita errores si faltan claves en `$match`
        }

        // Ajustar la posición si el reemplazo anterior cambió el tamaño del texto
        $adjusted_offset = $match['offset'] + $adjust;

        // Verifica si la palabra clave tiene una clase CSS asignada
        if (!strpos($match['replace'], '<span')) {
            $match['replace'] = "<span class='keyword'>" . $match['replace'] . "</span>";
        }

        // Agregar el texto hasta el punto de la coincidencia
        $result .= substr($text, $pos, $match['offset'] - $pos);
        // Agregar la parte reemplazada con etiquetas HTML para resaltado
        $result .= $match['replace'];

        // Mover la posición
        $pos = $match['offset'] + $match['strlen'];

        // Calcular el ajuste en la posición debido a la diferencia en la longitud del reemplazo
        $adjust += strlen($match['replace']) - $match['strlen'];
    }

    // Agregar cualquier texto restante después de la última coincidencia
    $result .= substr($text, $pos);

    return $result;
}
?>
