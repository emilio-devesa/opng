<?php
function sanitize($input) 
{
    // Asegurar que la entrada está en UTF-8
    $input = mb_convert_encoding($input, 'UTF-8', 'auto');

    // Expresiones regulares para eliminar contenido peligroso
    $search = array(
        '@<\s*script[^>]*?>.*?<\s*/\s*script\s*>@si',  // Eliminar scripts
        '@<\s*style[^>]*?>.*?<\s*/\s*style\s*>@siU',   // Eliminar estilos
        '@<\s*iframe[^>]*?>.*?<\s*/\s*iframe\s*>@siU', // Eliminar iframes
        '@<\s*object[^>]*?>.*?<\s*/\s*object\s*>@siU', // Eliminar objetos Flash
        '@<\s*embed[^>]*?>.*?<\s*/\s*embed\s*>@siU',   // Eliminar embebidos
        '@<\s*[\/\!]*?[^<>]*?>@si',                    // Eliminar todas las etiquetas HTML
        '@<![\s\S]*?--[ \t\n\r]*>@'                    // Eliminar comentarios multi-línea
    );
    
    // Eliminar código peligroso
    $output = preg_replace($search, '', $input) ?? '';

    // Convertir caracteres especiales a entidades HTML para prevenir XSS
    $output = htmlspecialchars($output, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return $output;
}
?>
