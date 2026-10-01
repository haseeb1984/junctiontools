<?php

function inject_adsense_code($buffer) {
    $path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    $excluded_pages = [
        '/privacy-policy',
        '/terms',
        '/disclaimer',
        '/contact'
    ];
    
    if (in_array($path, $excluded_pages, true)) {
        return $buffer;
    }
    
    $adsense_tag = '
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2009027605349204"
        crossorigin="anonymous"></script>';

    // Agar AdSense pehle se maujood hai to dobara inject na karein
    if (strpos($buffer, 'ca-pub-2009027605349204') !== false) {
        return $buffer;
    }

    // Sirf </head> milne par code insert karein
    if (stripos($buffer, '</head>') !== false) {
        return preg_replace(
            '/<\/head>/i',
            $adsense_tag . "\n</head>",
            $buffer,
            1
        );
    }

    return $buffer;
}

ob_start('inject_adsense_code');

?>