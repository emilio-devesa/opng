<?php
function preg_escape($text) {
    $text = str_replace("\\n", "\n", $text);
    return preg_quote($text, '/');
}

class CToken {
    var $start_tag = "";
    var $end_tag = "";
    var $pattern;
    var $link = "";
    var $tip = "";
    var $token = "";

    function __construct($token) {
        $start = isset($token['attributes']['START']) ? preg_escape($token['attributes']['START']) : '';
        $end = isset($token['attributes']['END']) ? preg_escape($token['attributes']['END']) : '';

        if ($start && $end) {
            $esc = isset($token['attributes']['ESCAPE']) ? preg_escape($token['attributes']['ESCAPE']) : '';
            $this->pattern = "/$start(.*?)" . ($esc ? "(?<!$esc)" : "") . "$end/s";
        } elseif (isset($token['attributes']['ANY'])) {
            $this->pattern = "/" . preg_escape($token['value']) . "/";
        } else {
            $this->pattern = "/\\b(" . preg_escape($token['value']) . ")\\b/";
        }

        $this->link = $token['attributes']['LINK'] ?? "";
        $this->tip = $token['attributes']['TIP'] ?? "";
        $this->token = $token['value'];
    }

    function apply($text) {
        return $this->start_tag . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . $this->end_tag;
    }

    function get_matches($text) {
        $raw_matches = [];
        if (preg_match_all($this->pattern, $text, $raw_matches, PREG_OFFSET_CAPTURE)) {
            $raw_matches = $raw_matches[0];
        } else {
            return [];
        }

        $matches = [];
        foreach ($raw_matches as $match) {
            $matches[] = [
                'strlen' => strlen($match[0]),
                'replace' => $this->apply($match[0]),
                'offset' => $match[1],
                'link' => $this->link,
                'token' => $this->token,
                'tip' => $this->tip
            ];
        }

        // Ejecutar la expresión regular y depurar el resultado
        preg_match_all($this->pattern, $text, $raw_matches, PREG_OFFSET_CAPTURE);

        /* echo "<h3>Depuración en CToken:</h3>";
        echo "<p>Patrón usado: " . htmlspecialchars($this->pattern) . "</p>";
        echo "<p>Texto analizado: " . htmlspecialchars($text) . "</p>";
        echo "<pre>Coincidencias encontradas:</pre>";
        var_dump($raw_matches);
        exit; */

        return $matches;
    }
}

class CClass {
    var $start_tag;
    var $end_tag;
    var $tokens = [];
    var $linkbase = false;

    function __construct($class) {
        $styles = [];
        if (isset($class['attributes']['COLOR'])) {
            $styles[] = "color:" . htmlspecialchars($class['attributes']['COLOR']);
        }
        if (isset($class['attributes']['STYLE'])) {
            if ($class['attributes']['STYLE'] === 'bold') {
                $styles[] = "font-weight: bold";
            } elseif ($class['attributes']['STYLE'] === 'italic') {
                $styles[] = "font-style: italic";
            }
        }

        $this->start_tag = "<span>"; 
        $this->end_tag = "</span>";

        if (isset($class['attributes']['COLOR'])) {
            $this->start_tag = "<span style='color: " . $class['attributes']['COLOR'] . ";'>";
        }
        /* 
        // Depuración: Verificar si la clase tiene tokens
        echo "<h3>Depuración en CClass:</h3>";
        echo "<p>Clase cargada: " . htmlspecialchars(print_r($class, true)) . "</p>";
        exit;
         */
        foreach ($class['TOKEN'] ?? [] as $tok) {
            $this->tokens[] = new CToken($tok);
        }
    }

    function get_matches($text) {
        $matches = [];
        foreach ($this->tokens as $token) {
            $matches = array_merge($matches, $token->get_matches($text));
        }
        return $matches;
    }
}

/* class CRule {
    var $classes = [];

    function __construct($rule) {
        foreach ($rule['CLASS'] ?? [] as $class) {
            $this->classes[] = new CClass($class);
        }
    }

    function get_matches($text) {
        $matches = [];
        foreach ($this->classes as $class) {
            $matches = array_merge($matches, $class->get_matches($text));
        }

        var_dump($this->classes); // Verificar si las reglas se cargaron
        
        return $matches;
    }
} */
class CRule {
    var $classes = array();

    function __construct($rule) {
        if (!empty($rule['class'])) {
            foreach ($rule['class'] as $class) {
                $this->classes[] = new CClass($class);
            }
        }
    }

    function get_matches($text) {
        $matches = [];
        foreach ($this->classes as $class) {
            $matches = array_merge($matches, $class->get_matches($text));
        }
/* 
        // Depuración: Ver si hay coincidencias
        echo "<pre>";
        var_dump($matches);
        echo "</pre>";
        exit;
 */
        return $matches;
    }
}

?>
