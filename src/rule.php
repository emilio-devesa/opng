<?php
function preg_escape($text)
{
    $text = str_replace("\\n", "\n", $text); // Reemplazar \n con saltos de línea
    $text = preg_quote($text, '/'); // Escapar correctamente para expresiones regulares
    $text = htmlentities($text);    // Escapar caracteres especiales para prevenir XSS
    return $text;
}

class CToken {
    var $start_tag = "";
    var $end_tag = "";
    var $pattern;
    var $link = "";
    var $tip = "";
    var $token = "";

    function __construct($token) {
        if (isset($token['attributes']['START'])) {
            $start = preg_escape($token['attributes']['START']);
            $end = preg_escape($token['attributes']['END']);
            if (isset($token['attributes']['ESCAPE'])) {
                $esc = preg_escape($token['attributes']['ESCAPE']);
                $this->pattern = "/$start(.*?)" . "(?<!$esc)$end/s";
            } else {
                $this->pattern = "/$start(.*?)$end/s";
            }
        } elseif (isset($token['attributes']['ANY'])) {
            $this->pattern = "/" . preg_escape($token['value']) . "/";
        } else {
            $this->pattern = "/\\b(" . preg_escape($token['value']) . ")\\b/";
        }

        if (isset($token['attributes']['LINK'])) {
            $this->link = $token['attributes']['LINK'];
        }

        if (isset($token['attributes']['TIP'])) {
            $this->tip = $token['attributes']['TIP'];
        }

        $this->token = $token['value'];
    }

    function apply($text) {
        return $this->start_tag . $text . $this->end_tag;
    }

    function get_matches($text) {
        $raw_matches = array();
        $matches = array();
        preg_match_all($this->pattern, $text, $raw_matches, PREG_OFFSET_CAPTURE);
        $raw_matches = $raw_matches[0];

        foreach ($raw_matches as $match) {
            $ret = array();
            $ret['strlen'] = strlen($match[0]);
            $ret['replace'] = $this->apply($match[0]);
            $ret['offset'] = $match[1];
            $ret['link'] = $this->link;
            $ret['token'] = $this->token;
            $ret['tip'] = $this->tip;

            $matches[] = $ret;
        }

        return $matches;
    }
}

class CClass {
    var $start_tag;
    var $end_tag;
    var $tokens = array();
    var $linkbase = false;

    function __construct($class) {
        $this->start_tag = "<span"; // Cambiado de <font> a <span>
        $this->end_tag = "</span>";

        if (isset($class['attributes']['COLOR'])) {
            $this->start_tag .= " style=\"color:" . $class['attributes']['COLOR'] . ";\"";
        }

        if (isset($class['attributes']['STYLE'])) {
            if ($class['attributes']['STYLE'] == 'bold') {
                $this->start_tag .= " style=\"font-weight: bold;\"";
            }
            if ($class['attributes']['STYLE'] == 'italic') {
                $this->start_tag .= " style=\"font-style: italic;\"";
            }
        }

        $this->start_tag .= ">";

        if (isset($class['attributes']['LINKBASE'])) {
            $this->linkbase = $class['attributes']['LINKBASE'];
        }

        if (isset($class['TOKEN'])) {
            foreach ($class['TOKEN'] as $tok) {
                $this->tokens[] = new CToken($tok);
            }
        }

        if (isset($class['RANGE'])) {
            foreach ($class['RANGE'] as $ran) {
                $this->tokens[] = new CToken($ran);
            }
        }
    }

    function get_matches($text) {
        $matches = array();
        foreach ($this->tokens as $token) {
            $matches = array_merge($matches, $token->get_matches($text));
        }

        foreach ($matches as $i => $match) {
            $matches[$i] = $this->apply($match);
        }

        return $matches;
    }

    function apply($match) {
        $start_tag = $this->start_tag;
        $end_tag = $this->end_tag;

        if ($this->linkbase !== false || $match['link']) {
            $link = $this->linkbase !== false ? str_replace("TOKEN", $match['token'], $this->linkbase) : $match['link'];

            $start_tag .= "<a href=\"$link\"";
            if ($match['tip']) {
                $start_tag .= " title=\"" . htmlentities($match['tip']) . "\"";
            }
            $start_tag .= ">";
            $end_tag .= "</a>";
        }

        $match['replace'] = $start_tag . $match['replace'] . $end_tag;
        return $match;
    }
}

class CRule {
    var $classes = array();

    function __construct($rule) {
        if (isset($rule['CLASS'])) {
            foreach ($rule['CLASS'] as $class) {
                $this->classes[] = new CClass($class);
            }
        }
    }

    function get_matches($text) {
        $matches = array();
        foreach ($this->classes as $class) {
            $matches = array_merge($matches, $class->get_matches($text));
        }

        foreach ($matches as $i => $match) {
            $matches[$i]['index'] = $i;
        }

        return $matches;
    }
}
?>
