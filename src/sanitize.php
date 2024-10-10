<?php
function sanitize($input) 
{
    // Nunca confíes en la entrada del usuario
    $search = array(
        '@<\s*script[^>]*?>.*?<\s*/\s*script\s*>@si',  // Eliminar scripts
        '@<\s*style[^>]*?>.*?<\s*/\s*style\s*>@siU',   // Eliminar estilos
        '@<\s*[\/\!]*?[^<>]*?>@si',                    // Eliminar todas las etiquetas HTML
        '@<![\s\S]*?--[ \t\n\r]*>@'                    // Eliminar comentarios multi-línea
    );
    
    // Reemplazar con cadena vacía
    $output = preg_replace($search, '', $input);
    
    // Convertir caracteres especiales a entidades HTML (prevenir XSS)
    $output = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    
    return $output;
}
?>
