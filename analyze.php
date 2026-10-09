<?php

/**
 * ResourceScope: Multi-Page Web Resource & Inventory Analyzer
 * 
 * Usage: php analyze.php "http://example.com/page1,http://example.com/page2"
 *    OR: POST / GET via web server
 */

require_once(dirname(__FILE__) . '/config.php');

// Ensure cache directory exists and is writable
if (!is_dir(CACHE_DIR)) {
    if (!@mkdir(CACHE_DIR, 0775, true)) {
        $err = error_get_last();
        $errPayload = json_encode(['error' => 'Permission Error: Cannot create cache directory. ' . ($err['message'] ?? 'Check local permissions.')], JSON_PRETTY_PRINT);
        if (php_sapi_name() === 'cli') {
            echo $errPayload . "\n";
            exit(1);
        } else {
            header('Content-Type: application/json');
            echo $errPayload;
            exit;
        }
    }
} elseif (!is_writable(CACHE_DIR)) {
    $errPayload = json_encode(['error' => 'Permission Error: The cache/ directory is not writable. Please check file permissions on your dev machine.'], JSON_PRETTY_PRINT);
    if (php_sapi_name() === 'cli') {
        echo $errPayload . "\n";
        exit(1);
    } else {
        header('Content-Type: application/json');
        echo $errPayload;
        exit;
    }
}

require_once __DIR__ . '/functions.php';

// -----------------------------------------------------------------------------
// Input Handling & Setup
// -----------------------------------------------------------------------------
$rawInputs = [];

if (isset($overrideUrls)) {
    // Passed directly via include in index.php
    $rawInputs = (array) $overrideUrls;
} elseif (php_sapi_name() === 'cli') {
    $rawInputs = array_slice($argv ?? [], 1);
} else {
    $rawInput = $_REQUEST['urls'] ?? $_REQUEST['url'] ?? '';
    if (!empty($rawInput)) {
        $rawInputs = (array) $rawInput;
    }
}

$targetUrls = [];
foreach ($rawInputs as $input) {
    $parts = explode(',', $input);
    foreach ($parts as $url) {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $targetUrls[] = $url;
        }
    }
}

$targetUrls = array_values(array_unique($targetUrls));
$totalPageCount = count($targetUrls);

if ($totalPageCount === 0) {
    $errPayload = json_encode(['error' => 'No valid URLs provided.'], JSON_PRETTY_PRINT);
    if (php_sapi_name() === 'cli') {
        echo $errPayload . "\n";
        exit(1);
    } else {
        header('Content-Type: application/json');
        echo $errPayload;
        exit;
    }
}
 
sort($targetUrls);
$groupHash = md5(implode('|', $targetUrls));

// -----------------------------------------------------------------------------
// Fetch & Parse DOMs & Resource Inventory across All Target URLs
// -----------------------------------------------------------------------------
$parsedPages = [];
$inventory   = [];

foreach ($targetUrls as $url) {
    $response = fetchUrl($url);
    
    if (!$response['success'] || empty(trim($response['content']))) {
        // Log the failure in the output instead of creating an empty page entry
        error_log("ResourceScope Error: Failed to fetch {$url} - Status: {$response['status']} - Error: {$response['error']}");
        continue;
    }

    $htmlContent = $response['content'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // Use MB_CONVERT if necessary, handle HTML5 elements gracefully
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Register HTML Page itself in Inventory
    registerResource($inventory, $url, 'HTML', 'external', $url, $htmlContent);

    // Extract inline & external Stylesheets
    $styles = $dom->getElementsByTagName('style');
    foreach ($styles as $style) {
        $content = trim($style->nodeValue);
        if (!empty($content)) {
            $key = "inline_css_hash_" . md5($content);
            registerResource($inventory, $key, 'CSS', 'inline', $url, $content);
        }
    }

    $links = $dom->getElementsByTagName('link');
    foreach ($links as $link) {
        $rel = strtolower($link->getAttribute('rel'));
        $href = $link->getAttribute('href');
        if ($href) {
            $resolved = resolveUrl($href, $url);
            $type = ($rel === 'stylesheet') ? 'CSS' : (in_array($rel, ['icon', 'shortcut icon', 'apple-touch-icon']) || str_contains($rel, 'image') ? 'Images' : 'Other');
            registerResource($inventory, $resolved, $type, 'external', $url);
        }
    }

    // Extract Scripts
    $scripts = $dom->getElementsByTagName('script');
    foreach ($scripts as $script) {
        $src = $script->getAttribute('src');
        if ($src) {
            $resolved = resolveUrl($src, $url);
            registerResource($inventory, $resolved, 'JavaScript', 'external', $url);
        } else {
            $content = trim($script->nodeValue);
            if (!empty($content)) {
                $key = "inline_js_hash_" . md5($content);
                registerResource($inventory, $key, 'JavaScript', 'inline', $url, $content);
            }
        }
    }

    // Extract Images
    $images = $dom->getElementsByTagName('img');
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if ($src) {
            $resolved = resolveUrl($src, $url);
            registerResource($inventory, $resolved, 'Images', 'external', $url);
        }
    }

    $parsedPages[$url] = [
        'xpath' => $xpath,
        'html'  => $htmlContent,
        'dom'   => $dom
    ];
    
    // Explicitly free libxml memory buffers
    libxml_clear_errors();
    gc_collect_cycles();

}

// Guard against writing empty output if zero pages parsed successfully
if (empty($parsedPages)) {
    die("ResourceScope Error: None of the target URLs returned valid HTML content. Check local server logs or URL access permissions.");
}

// -----------------------------------------------------------------------------
// Fetch External Content & Detect Duplicate Resources via SHA-256 Hashes
// -----------------------------------------------------------------------------
$contentHashes = []; // sha256 => canonical_key
$duplicates    = []; // duplicate_url => canonical_url

foreach ($inventory as $key => &$res) {
    if ($res['nature'] === 'external') {
        $fetchResult = fetchUrl($key);
        $res['status_code'] = $fetchResult['status'] ?? 0;
        if ($fetchResult['success']) {
            $res['code'] = $fetchResult['content'];
            $res['hash'] = hash('sha256', $fetchResult['content']);
            if ($res['type'] === 'Other') {
                $res['type'] = categorizeResourceType($key, $fetchResult['content_type']);
            }
        }
    } else {
        $res['status_code'] = 200; // Inline assets are local/internal
    }
}
unset($res);

// -----------------------------------------------------------------------------
// CSS Analysis (Utilization %, Classification, Safety Confidence)
// -----------------------------------------------------------------------------
$allJsCodeConcat = '';
foreach ($inventory as $k => $v) {
    if ($v['type'] === 'JavaScript' && !empty($v['code'])) {
        $allJsCodeConcat .= "\n" . $v['code'];
    }
}

$cssReport = [];

foreach ($inventory as $sourceKey => $info) {
    if ($info['type'] !== 'CSS') continue;

    $cssCode = $info['code'] ?? '';
    if (empty($cssCode)) {
        $cssReport[$sourceKey] = [
            'status'  => 'error',
            'message' => 'Asset content empty or failed to fetch'
        ];
        continue;
    }

    $cleanCss = sanitizeCssForParsing($cssCode);
    $extractedRules = extractCssRules($cleanCss);

    $used        = [];
    $unused      = [];
    $unsupported = [];
    $totalRules  = 0;

    foreach ($extractedRules as $rule) {
        $selectors = explode(',', $rule['selector']);
        
        foreach ($selectors as $selector) {
            $selector = trim($selector);
            if ($selector === '' || $selector[0] === '@') continue;

            $totalRules++;

            if (!isSupportedSelector($selector)) {
                $unsupported[] = [
                    'selector' => $selector,
                    'reason'   => 'Contains unsupported pseudo-class, pseudo-element, or nesting construct'
                ];
                continue;
            }

            $xPathQuery = cssToXpath($selector);
            if (!$xPathQuery) {
                $unsupported[] = [
                    'selector' => $selector,
                    'reason'   => 'CSS selector could not be translated to XPath'
                ];
                continue;
            }

            $totalMatchesForSelector = 0;
            $matchedPages = [];

            foreach ($parsedPages as $pageUrl => $pageData) {
                $nodes = @$pageData['xpath']->query($xPathQuery);
                if ($nodes !== false && $nodes->length > 0) {
                    $totalMatchesForSelector += $nodes->length;
                    $matchedPages[] = $pageUrl;
                }
            }

            if ($totalMatchesForSelector > 0) {
                $uniquePages = array_values(array_unique($matchedPages));
                $used[$selector] = [
                    'count'          => $totalMatchesForSelector,
                    'pages'          => $uniquePages,
                    'classification' => classifyScope(count($uniquePages), $totalPageCount)
                ];
            } else {
                // Safety Confidence Classification
                $cleanToken = preg_replace('/[.#\[\]"\'=]/', '', $selector);
                $isDynamic = ($cleanToken !== '' && str_contains($allJsCodeConcat, $cleanToken));

                $confidence = $isDynamic ? 'Possibly dynamic' : 'High confidence unused';
                $reason = $isDynamic 
                    ? 'Matched 0 DOM elements, but selector token was found in JavaScript code' 
                    : 'Parsed cleanly and matched 0 DOM elements across all analyzed pages';

                $unused[] = [
                    'selector'   => $selector,
                    'confidence' => $confidence,
                    'reason'     => $reason
                ];
            }
        }
    }

    $rulesConfirmed = count($used);
    $rulesUnused = count($unused);
    $rulesUnsupported = count($unsupported);
    $utilizationPct = $totalRules > 0 ? round(($rulesConfirmed / $totalRules) * 100, 1) : 0;
    $foundOnPages = array_values(array_unique($info['found_on']));

    $cssReport[$sourceKey] = [
        'found_on_pages'    => $foundOnPages,
        'classification'    => classifyScope(count($foundOnPages), $totalPageCount),
        'canonical_url'     => $duplicates[$sourceKey] ?? null,
        'rules_analyzed'    => $totalRules,
        'rules_confirmed'   => $rulesConfirmed,
        'rules_unused'      => $rulesUnused,
        'rules_unsupported' => $rulesUnsupported,
        'utilization_pct'   => $utilizationPct,
        'unused'            => $unused,
        'unsupported'       => $unsupported,
        'used'              => $used
    ];
}

// -----------------------------------------------------------------------------
// JavaScript Function & Event Listener Analysis
// -----------------------------------------------------------------------------
$jsEvents = []; // Collect DOM inline event attributes (e.g. onclick="foo()")

foreach ($parsedPages as $pageUrl => $pageData) {
    $eventNodes = @$pageData['xpath']->query('//@*[starts-with(name(), "on")]');
    if ($eventNodes) {
        foreach ($eventNodes as $attr) {
            if (preg_match('/([a-zA-Z0-9_\$]+)\s*\(/', $attr->nodeValue, $m)) {
                $jsEvents[] = [
                    'function'  => $m[1],
                    'event'     => $attr->nodeName,
                    'page'      => $pageUrl
                ];
            }
        }
    }
}

$allSearchableSources = [];
foreach ($parsedPages as $pageUrl => $pageData) {
    $allSearchableSources['page:' . $pageUrl] = $pageData['html'];
}

$jsCodeCache = [];
foreach ($inventory as $sourceKey => $info) {
    if ($info['type'] !== 'JavaScript' || empty($info['code'])) continue;
    $jsCodeCache[$sourceKey] = $info['code'];
    $allSearchableSources['js:' . $sourceKey] = $info['code'];
}

$jsReport = [];

foreach ($inventory as $sourceKey => $info) {
    if ($info['type'] !== 'JavaScript') continue;

    if (!isset($jsCodeCache[$sourceKey])) {
        $jsReport[$sourceKey] = [
            'status'  => 'error',
            'message' => 'Could not fetch script asset or content is empty'
        ];
        continue;
    }

    $jsCode = $jsCodeCache[$sourceKey];
    $functions = extractJsFunctions($jsCode);

    $used       = [];
    $unused     = [];
    $totalFuncs = count($functions);

    foreach ($functions as $func) {
        $pattern = '/\b' . preg_quote($func, '/') . '\b/';
        $totalOccurrences = 0;
        $matchedContexts  = [];
        $eventBindings    = [];

        // Check DOM event triggers
        foreach ($jsEvents as $ev) {
            if ($ev['function'] === $func) {
                $eventBindings[] = "{$ev['event']} on {$ev['page']}";
                $matchedContexts[] = 'page:' . $ev['page'];
                $totalOccurrences++;
            }
        }

        // Check addEventListener occurrences in JS
        if (preg_match_all('/addEventListener\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*' . preg_quote($func, '/') . '\b/', $jsCode, $m)) {
            foreach ($m[1] as $evtName) {
                $eventBindings[] = "addEventListener('{$evtName}')";
            }
        }

        foreach ($allSearchableSources as $contextKey => $sourceContent) {
            $count = preg_match_all($pattern, $sourceContent);
            if ($count === 0) continue;

            if ($contextKey === 'js:' . $sourceKey) {
                $count--; // Exclude the function's own declaration
            }

            if ($count > 0) {
                $totalOccurrences += $count;
                $matchedContexts[] = $contextKey;
            }
        }

        $matchedPages = [];
        foreach ($matchedContexts as $ctx) {
            if (str_starts_with($ctx, 'page:')) {
                $matchedPages[] = substr($ctx, 5);
            }
        }
        $matchedPages = array_values(array_unique($matchedPages));

        if ($totalOccurrences > 0 || !empty($matchedPages)) {
            $used[$func] = [
                'count'          => $totalOccurrences,
                'pages'          => $matchedPages,
                'event_bindings' => array_values(array_unique($eventBindings)),
                'classification' => classifyScope(count($matchedPages), $totalPageCount)
            ];
        } else {
            $unused[] = $func;
        }
    }

    $foundOnPages = array_values(array_unique($info['found_on']));

    $jsReport[$sourceKey] = [
        'found_on_pages'  => $foundOnPages,
        'classification'  => classifyScope(count($foundOnPages), $totalPageCount),
        'canonical_url'   => $duplicates[$sourceKey] ?? null,
        'total_functions' => $totalFuncs,
        'used_count'      => count($used),
        'unused_count'    => count($unused),
        'unused'          => $unused,
        'used'            => $used
    ];
}

// -----------------------------------------------------------------------------
// Build Per-Page Usage Summary
// -----------------------------------------------------------------------------
$perPageSummary = [];

foreach ($targetUrls as $pageUrl) {
    $unusedCssCount = 0;
    $usedCssCount = 0;
    $unusedJsCount = 0;
    $externalResourceCount = 0;

    foreach ($cssReport as $cData) {
        if (isset($cData['found_on_pages']) && in_array($pageUrl, $cData['found_on_pages'], true)) {
            $unusedCssCount += $cData['rules_unused'] ?? 0;
            $usedCssCount   += $cData['rules_confirmed'] ?? 0;
        }
    }

    foreach ($jsReport as $jData) {
        if (isset($jData['found_on_pages']) && in_array($pageUrl, $jData['found_on_pages'], true)) {
            $unusedJsCount += $jData['unused_count'] ?? 0;
        }
    }

    foreach ($inventory as $iData) {
        if ($iData['nature'] === 'external' && in_array($pageUrl, $iData['found_on'], true)) {
            $externalResourceCount++;
        }
    }

    $perPageSummary[$pageUrl] = [
        'unused_css'         => $unusedCssCount,
        'used_css'           => $usedCssCount,
        'unused_js'          => $unusedJsCount,
        'external_resources' => $externalResourceCount
    ];
}

// -----------------------------------------------------------------------------
// Output Generation & Persist
// -----------------------------------------------------------------------------
$output = [
    'hash'             => $groupHash,
    'target_urls'      => $targetUrls,
    'analyzed_at'      => date('Y-m-d H:i:s'),
    'reviews'          => [], // Tracks user review statuses (key => status)
    'summary'          => [
        'pages_scanned'     => count($targetUrls),
        'total_css_sources' => count($cssReport),
        'total_js_sources'  => count($jsReport),
        'total_inventory'   => count($inventory),
        'duplicates_found'  => count($duplicates)
    ],
    'per_page_summary' => $perPageSummary,
    'inventory'        => $inventory,
    'css_analysis'     => $cssReport,
    'js_analysis'      => $jsReport
];

// Clean non-UTF8 binary noise if present
array_walk_recursive($output, function (&$item) {
    if (is_string($item)) {
        $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
    }
});

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

if ($json === false || empty($json)) {
    $jsonError = json_last_error_msg();
    die("ResourceScope Error: JSON encoding failed: {$jsonError}");
}

$filePath = CACHE_DIR . '/' . $groupHash . '.json';

if (file_put_contents($filePath, $json) === false) {
    $err = error_get_last();
    die('Error writing JSON file: ' . ($err['message'] ?? 'Permission denied'));
}


if (php_sapi_name() === 'cli') {
    echo "Processed " . count($targetUrls) . " URL(s). Report hash: {$groupHash}\n";
} else {
    header('Location: index.php?file=' . $groupHash);
    exit;
}

?>