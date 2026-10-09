<?php

// -----------------------------------------------------------------------------
// Helper Functions
// -----------------------------------------------------------------------------

function classifyScope(int $usedPagesCount, int $totalPagesCount): string {
    if ($usedPagesCount === 0) return 'UNUSED';
    if ($usedPagesCount === $totalPagesCount) return 'GLOBAL';
    if ($usedPagesCount > 1) return 'SECTION';
    return 'PAGE-SPECIFIC';
}

function categorizeResourceType(string $url, string $contentType = ''): string {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'CSS'        => ['content' => 'text/css',           'extensions' => ['css']],
        'JavaScript' => ['content' => 'javascript',         'extensions' => ['js', 'mjs']],
        'Images'     => ['content' => 'image/',             'extensions' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico']],
        'Fonts'      => ['content' => 'font/',              'extensions' => ['woff', 'woff2', 'ttf', 'otf', 'eot']],
        'HTML'       => ['content' => 'text/html',          'extensions' => ['html', 'htm', 'php']],
        'Audio'      => ['content' => 'audio/',             'extensions' => ['mp3', 'wav', 'ogg', 'flac']],
        'Video'      => ['content' => 'video/',             'extensions' => ['mp4', 'webm', 'mov']],
        'Documents'  => ['content' => 'application/pdf',    'extensions' => ['pdf']],
        'Archives'   => ['content' => 'application/',       'extensions' => ['zip', 'gz', 'tar', '7z']]
    ];
    foreach ($types as $type => $rules):
        if (str_contains($contentType, $rules['content']) || in_array($ext, $rules['extensions'], true)):
            return $type;
        endif;
    endforeach;
    return 'Other';
}

function registerResource(array &$inventory, string $key, string $type, string $nature, string $pageUrl, ?string $code = null): void {
    if (!isset($inventory[$key])) {
        $inventory[$key] = [
            'type'     => $type,
            'nature'   => $nature, // 'external' or 'inline'
            'code'     => $code,
            'found_on' => [],
            'hash'     => $code !== null ? hash('sha256', $code) : null
        ];
    }
    if (!in_array($pageUrl, $inventory[$key]['found_on'], true)) {
        $inventory[$key]['found_on'][] = $pageUrl;
    }
}

// -----------------------------------------------------------------------------
// Parsing & Network Helpers
// -----------------------------------------------------------------------------

function sanitizeCssForParsing(string $css): string {
    $css = preg_replace('!/\*.*?\*/!s', '', $css);
    return preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $css);
}

function isSupportedSelector(string $selector): bool {
    if (preg_match('/::|:(has|is|where|not|nth-child|nth-of-type|focus-within|state)\(/i', $selector)) return false;
    if (preg_match('/::part|::slotted|:host|:scope/i', $selector)) return false;
    if (str_contains($selector, '&')) return false;
    if (str_starts_with($selector, '@') || str_contains($selector, '::-webkit-') || str_contains($selector, '::-moz-')) return false;
    return true;
}

function extractCssRules(string $cleanCss): array {
    $rules = [];
    $length = strlen($cleanCss);
    $buffer = '';
    $depth = 0;
    $currentSelector = '';

    for ($i = 0; $i < $length; $i++) {
        $char = $cleanCss[$i];
        if ($char === '{') {
            if ($depth === 0) {
                $currentSelector = trim($buffer);
                $buffer = '';
            }
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                if ($currentSelector !== '' && !str_starts_with($currentSelector, '@')) {
                    $rules[] = [
                        'selector' => $currentSelector,
                        'body'     => trim($buffer)
                    ];
                }
                $currentSelector = '';
                $buffer = '';
            }
        } else {
            $buffer .= $char;
        }
    }
    return $rules;
}

function extractJsFunctions(string $jsCode): array {
    $cleanJs = preg_replace('!/\*.*?\*/!s', '', $jsCode);
    $cleanJs = preg_replace('!//.*!', '', $cleanJs);
    $cleanJs = preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`/s', '""', $cleanJs);

    $functions = [];
    if (preg_match_all('/\bfunction\s+([a-zA-Z0-9_\$]+)\s*\(/i', $cleanJs, $m)) {
        $functions = array_merge($functions, $m[1]);
    }
    if (preg_match_all('/\b(?:const|let|var)\s+([a-zA-Z0-9_\$]+)\s*=\s*(?:async\s+)?(?:function|\([^)]*\)\s*=>|[a-zA-Z0-9_\$]+\s*=>)/i', $cleanJs, $m)) {$functions = array_merge($functions,$m[1]);
    }
    if (preg_match_all('/\b(?<!function\s)(?<!if)(?<!for)(?<!while)(?<!switch)(?<!catch)([a-zA-Z0-9_\$]+)\s*\([^)]*\)\s*\{/i', $cleanJs, $m)) {$reserved = ['if', 'for', 'while', 'switch', 'catch', 'constructor', 'function'];
        foreach ($m[1] as$name) {
            if (!in_array(strtolower($name),$reserved, true)) {
                $functions[] =$name;
            }
        }
    }
    return array_values(array_unique($functions));
}

function fetchUrl(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (ResourceScope/2.0)',
        CURLOPT_ENCODING       => '',
        CURLOPT_COOKIEFILE     => '', // Enable in-memory cookie engine for local session redirects
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);

    $content     = curl_exec($ch);
    $errno       = curl_errno($ch);
    $errorMsg    = curl_error($ch);
    $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl    = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($errno !== 0 || $content === false) {
        return [
            'success'      => false, 
            'status'       => $httpCode, 
            'content'      => '', 
            'content_type' => $contentType, 
            'final_url'    => $finalUrl ?: $url, 
            'error'        => $errorMsg ?: "cURL Error {$errno}"
        ];
    }
    
    if ($httpCode >= 400) {
        return ['success' => false, 'status' => $httpCode, 'content' => $content, 'content_type' =>$contentType, 'final_url' => $finalUrl, 'error' => "HTTP {$httpCode}"];
    }

    return [
        'success'      => ($httpCode >= 200 && $httpCode < 400), 
        'status'       => $httpCode, 
        'content'      => $content, 
        'content_type' => $contentType, 
        'final_url'    => $finalUrl, 
        'error'        => $httpCode >= 400 ? "HTTP {$httpCode}" : null
    ];

}

function resolveUrl(string $rel, string$base): string {
    if ($rel === '') return $base;
    if (str_starts_with($rel, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?? 'http';
        return $scheme . ':' . $rel;
    }
    if (parse_url($rel, PHP_URL_SCHEME) !== null) return$rel;

    $baseParsed = parse_url($base);
    if (!$baseParsed || !isset($baseParsed['host'])) return$rel;

    $scheme    = $baseParsed['scheme'] ?? 'http';$host      = $baseParsed['host'];$port      = isset($baseParsed['port']) ? ':' .$baseParsed['port'] : '';
    $authority =$scheme . '://' . $host .$port;

    if ($rel[0] === '?' || $rel[0] === '#') {
        return $authority . ($baseParsed['path'] ?? '/') .$rel;
    }
    if ($rel[0] === '/') {
        return $authority . normalizePath($rel);
    }

    $basePath =$baseParsed['path'] ?? '/';
    $dir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
    return $authority . normalizePath($dir .$rel);
}

function normalizePath(string $path): string {
    $q = '';
    if (($pos = strpos($path, '?')) !== false) {
        $q = substr($path, $pos);$path = substr($path, 0,$pos);
    }
    $segments = explode('/', $path);$stack = [];
    foreach ($segments as$s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') array_pop($stack);
        else $stack[] =$s;
    }
    return '/' . implode('/', $stack) .$q;
}

function cssToXpath(string $selector): ?string {
    $selector = trim($selector);
    if ($selector === '' ||  preg_match('/[+~&\vert{}:]/',$selector)) return null;

    $parts = preg_split('/\s+/', trim($selector));$xpathSegments = [];

    foreach ($parts as$part) {
        $element = '*';$conditions = [];

        if (preg_match('/#([a-zA-Z0-9_-]+)/', $part,$m)) {
            $conditions[] = "@id='{$m[1]}'";
            $part = str_replace($m[0], '',$part);
        }

        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $part,$matches)) {
            foreach ($matches[1] as$class) {
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
            }
            $part = preg_replace('/\.([a-zA-Z0-9_-]+)/', '',$part);
        }

        if (!empty($part) &&$part !== '*') {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/',$part)) return null;
            $element =$part;
        }

        $segment = '//' .$element . (!empty($conditions) ? '[' . implode(' and ', $conditions) . ']' : '');
        $xpathSegments[] =$segment;
    }

    return !empty($xpathSegments) ? implode('', $xpathSegments) : null;
}

?>