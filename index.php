<?php

/**
 * ResourceScope: Viewer
 * 
 * Browser access for ResourceScope -> analyze.php
 */

require_once(dirname(__FILE__) . '/config.php');

// Handle AJAX review status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_review') {
    header('Content-Type: application/json');
    $hash = $_POST['file_hash'] ?? '';
    $itemKey = $_POST['item_key'] ?? '';
    $status = $_POST['status'] ?? '';
    
    $filePath = CACHE_DIR . '/' . preg_replace('/[^a-f0-9]/', '', $hash) . '.json';
    if ($hash && $itemKey && file_exists($filePath)) {
        $data = json_decode(file_get_contents($filePath), true);
        if ($data) {
            $data['reviews'][$itemKey] = $status;
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid parameters or file not found.']);
    exit;
}

// Handle JSON Report Export
if (isset($_GET['export'])) {
    $exportHash = preg_replace('/[^a-f0-9]/', '', $_GET['export']);
    $exportFile = CACHE_DIR . '/' . $exportHash . '.json';
    if (file_exists($exportFile)) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="resourcescope-' . $exportHash . '.json"');
        readfile($exportFile);
        exit;
    }
}

// Handle JSON Report Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_json'])) {
    if (is_dir(CACHE_DIR) || mkdir(CACHE_DIR, 0775, true)) {
        $file = $_FILES['import_json'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($file['tmp_name']);
            $decoded = json_decode($content, true);
            // Validate that it's a valid ResourceScope JSON report
            if ($decoded && isset($decoded['hash'])) {
                $importedHash = preg_replace('/[^a-f0-9]/', '', $decoded['hash']);
                file_put_contents(CACHE_DIR . '/' . $importedHash . '.json', $content);
                header('Location: index.php?file=' . $importedHash);
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['urls'])) {
    $urls = trim($_POST['urls']);
    if (!empty($urls)) {
        // Parse URLs to derive the group hash directly for redirection
        $urlList = array_filter(array_map('trim', explode(',', $urls)));
        sort($urlList);
        $expectedHash = md5(implode('|', $urlList));

        // Pass variables cleanly to analyze.php
        $overrideUrls = $urls;
        
        ob_start();
        include __DIR__ . '/analyze.php';
        ob_end_clean();

        header('Location: index.php?file=' . $expectedHash);
        exit;
    }
}

$selectedHash = isset($_GET['file']) ? preg_replace('/[^a-f0-9]/', '', $_GET['file']) : null;

$reports = [];
if (is_dir(CACHE_DIR)) {
    $files = glob(CACHE_DIR . '/*.json');
    foreach ($files as $file) {
        $filename = basename($file, '.json');
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $reports[$filename] = [
                'target_urls' => $data['target_urls'] ?? [$data['target_url'] ?? 'Unknown'],
                'scanned_at'  => $data['analyzed_at'] ?? date('Y-m-d H:i:s', filemtime($file)),
                'mtime'       => filemtime($file)
            ];
        }
    }
    uasort($reports, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

// Deletes json files from the cache and redirects cleanly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    if (is_dir(CACHE_DIR)) {
        foreach (glob(CACHE_DIR . '/*.json') as $file) {
            @unlink($file);
        }
    }
    header('Location: index.php');
    exit;
}

$activeData = null;
if ($selectedHash && isset($reports[$selectedHash])) {
    $activeFile = CACHE_DIR . '/' . $selectedHash . '.json';
    $activeData = json_decode(file_get_contents($activeFile), true);
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResourceScope Dashboard</title>
    <link rel="stylesheet" href="./css/style.css">
    <link rel="icon" type="image/x-icon" href="./favicon.ico">
</head>
<body>
    <aside>
        <div class="sidebar-header"><a href="./index.php">ResourceScope</a></div>
        <form id="analyzer-form" class="analyzer-form" method="POST" action="index.php">
            <textarea id="urls" name="urls" placeholder="Enter URL(s), separated by commas or newlines..." required><?php /*echo !empty($activeData['target_urls']) ? implode(',', $activeData['target_urls']) : '';*/ ?></textarea>
            <button type="submit">Analyze URL Set</button>
        </form>
        <h3>Reports</h3>
        <hr size=1 >
        <div class="file-list">
            <?php if (empty($reports)): ?>
                <div style="padding: 16px; color: var(--text-muted); font-size: 0.85rem;">No reports found in <code>cache/</code>.</div>
            <?php else: ?>
                <?php foreach ($reports as $hash => $info): ?>
                    <a href="?file=<?= $hash ?>" class="file-item <?= $hash === $selectedHash ? 'active' : '' ?>">
                        <div class="file-url">
                            <?= count($info['target_urls']) ?> Page(s): <?= htmlspecialchars($info['target_urls'][0] ?? '') ?>
                        </div>
                        <div class="file-date"><?= htmlspecialchars($info['scanned_at']) ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Side-by-side Project Actions Toolbar -->
        <div style="display: flex; gap: 8px; padding: 12px 16px; background: var(--panel-bg); border-top: 1px solid var(--border-color);">
            <!-- Reset Form -->
            <form id="reset-form" method="POST" action="index.php" style="flex: 1; margin: 0;">
                <input type="hidden" name="reset" value="1">
                <button type="submit" class="action-btn-link" onclick="return confirm('Clear all cached reports?');" style="width: 100%;">Reset</button>
            </form>

            <!-- Export Link (conditional) -->
            <?php if ($selectedHash && isset($reports[$selectedHash])): ?>
                <a href="index.php?export=<?= $selectedHash ?>" class="action-btn-link">Export</a>
            <?php endif; ?>

            <!-- Import Form -->
            <form id="import-form" method="POST" action="index.php" enctype="multipart/form-data" style="flex: 1; margin: 0;">
                <label class="action-btn-link" style="display: block; width: 100%;">
                    Import
                    <input type="file" name="import_json" accept=".json" style="display: none;" onchange="this.form.submit();">
                </label>
            </form>
        </div>
    </aside>
    <main>
        <?php if ($activeData): ?>
            <div class="header" id="head">
                <h1>Target URLs Analyzed (<?= count($activeData['target_urls'] ?? []) ?>)</h1>
                <div style="margin-top: 8px;">
                    <?php foreach (($activeData['target_urls'] ?? []) as $url): ?>
                        <span class="page-tag"><?= htmlspecialchars($url) ?></span>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="reanalyzeUrls()" style="width:300px;">Update Analysis</button>
            </div>
            <a href="#inventorySection">Site Resource Inventory</a> | <a href="#stylesheetSection">Stylesheets & Inline CSS</a> | <a href="#scriptSection">Scripts & Inline JS</a>
            <br><br>
            <!-- Feature 1: Per-Page Summary -->
            <h2 style="margin-bottom: 12px; font-size: 1.1rem;">Per-Page Usage Breakdown</h2>
            <div class="cards">
                <?php foreach (($activeData['per_page_summary'] ?? []) as $pUrl => $pStats): ?>
                    <div class="card">
                        <div class="card-title" style="word-break: break-all; text-transform: none; font-weight: 600; font-size: 0.8rem;">
                            <?= htmlspecialchars($pUrl) ?>
                        </div>
                        <ul style="margin-top: 10px; padding-left: 16px; font-size: 0.82rem; line-height: 1.5;">
                            <li><strong style="color: var(--danger-color);"><?= $pStats['unused_css'] ?></strong> unused CSS selectors</li>
                            <li><strong><?= $pStats['used_css'] ?></strong> used selectors</li>
                            <li><strong style="color: var(--danger-color);"><?= $pStats['unused_js'] ?></strong> unused JS functions</li>
                            <li><strong><?= $pStats['external_resources'] ?></strong> external resources</li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
            <!-- Feature 8: Client-side Search and Multi-Filter Controls -->
            <div class="controls-card">
                <input type="text" id="searchInput" placeholder="Search selector, function, or resource URL..." onkeyup="applyFilters()">
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="ALL">All Statuses</option>
                    <option value="Used">Used Only</option>
                    <option value="Unused">Unused Only</option>
                    <option value="Review">Needs Review / Unsupported</option>
                </select>
                <select id="typeFilter" onchange="applyFilters()">
                    <option value="ALL">All Types (CSS, JS, Inventory)</option>
                    <option value="CSS">CSS Analysis</option>
                    <option value="JS">JavaScript Analysis</option>
                    <option value="Inventory">Resource Inventory</option>
                </select>
                <select id="scopeFilter" onchange="applyFilters()">
                    <option value="ALL">All Scopes</option>
                    <option value="GLOBAL">GLOBAL</option>
                    <option value="SECTION">SECTION</option>
                    <option value="PAGE-SPECIFIC">PAGE-SPECIFIC</option>
                </select>
            </div>
            <!-- Feature 2: Site Resource Inventory -->
            <div id="inventorySection" class="section-block filterable-section" data-type="Inventory">
                <div class="section-title" onclick="toggleCollapse(this)">
                    <span>Site Resource Inventory (<?= count($activeData['inventory'] ?? []) ?>)</span>
                    <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);">Click to toggle</span>
                </div>
                <div class="collapsible-content">
                    <table>
                        <thead>
                            <tr>
                                <th>Resource URL / Key</th>
                                <th>Type</th>
                                <th>Nature</th>
                                <th>Status Code</th>
                                <th>Scope / Pages</th>
                                <th>Duplicate Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($activeData['inventory'] ?? []) as $key => $item): 
                                $pageCount = count($item['found_on'] ?? []);
                                $scope = $pageCount === count($activeData['target_urls']) ? 'GLOBAL' : ($pageCount > 1 ? 'SECTION' : 'PAGE-SPECIFIC');
                                $statusCode = $item['status_code'] ?? 200;
                                $statusClass = ($statusCode >= 400 || $statusCode === 0) ? 'badge-unused' : 'badge-used';
                            ?>
                                <tr class="data-row" data-scope="<?= $scope ?>" data-status="Used">
                                    <td><code class="asset-key"><?= htmlspecialchars($key) ?></code></td>
                                    <td><span class="badge" style="background:#e9ecef; color:#495057;"><?= htmlspecialchars($item['type']) ?></span></td>
                                    <td><?= htmlspecialchars($item['nature']) ?></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= $statusCode ?></span></td>
                                    <td>
                                        <span class="badge badge-scope"><?= $scope ?></span>
                                        <small style="color: var(--text-muted);">(<?= $pageCount ?> pages)</small>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['duplicate_of'])): ?>
                                            <span class="badge badge-review">Duplicate of <?= htmlspecialchars($item['duplicate_of']) ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">Unique</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Feature 5 & 6: CSS Utilization & Safety Tiers -->
            <h2 id="stylesheetSection" style="margin-top: 32px; margin-bottom: 16px;">Stylesheets &amp; Inline CSS</h2>
            <?php foreach (($activeData['css_analysis'] ?? []) as $source => $analysis): ?>
                <div class="section-block filterable-section" data-type="CSS">
                    <div class="section-title" onclick="toggleCollapse(this)">
                        <span class="asset-key"><?= htmlspecialchars($source) ?></span>
                        <?php if (isset($analysis['status']) && $analysis['status'] === 'error'): ?>
                            <span class="badge badge-unused">Error</span>
                        <?php else: ?>
                            <div>
                                <span class="badge badge-used" style="margin-right: 8px;"><?= $analysis['utilization_pct'] ?>% Utilized</span>
                                <span class="badge badge-scope"><?= $analysis['classification'] ?></span>
                                <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-muted); margin-left: 8px;">
                                    <?= $analysis['rules_confirmed'] ?> used / <?= $analysis['rules_unused'] ?> unused / <?= $analysis['rules_unsupported'] ?> unsupported
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!isset($analysis['status'])): ?>
                        <div class="collapsible-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Selector</th>
                                        <th style="width: 130px;">Status</th>
                                        <th style="width: 140px;">Scope / Occurrences</th>
                                        <th>Details / Active Pages</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Confirmed Used -->
                                    <?php foreach (($analysis['used'] ?? []) as $sel => $details): ?>
                                        <tr class="data-row" data-status="Used" data-scope="<?= $details['classification'] ?>">
                                            <td><code><?= htmlspecialchars($sel) ?></code></td>
                                            <td><span class="badge badge-used">Used</span></td>
                                            <td>
                                                <span class="badge badge-scope"><?= $details['classification'] ?></span>
                                                <small>(<?= $details['count'] ?>x)</small>
                                            </td>
                                            <td>
                                                <?php foreach (($details['pages'] ?? []) as $pUrl): ?>
                                                    <span class="page-tag"><?= htmlspecialchars($pUrl) ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Unused Rules with Confidence Level -->
                                    <?php foreach (($analysis['unused'] ?? []) as $u): ?>
                                        <tr class="data-row" data-status="Unused" data-scope="UNUSED">
                                            <td><code><?= (!empty($u['selector']) ? htmlspecialchars($u['selector']) : '') ?></code></td>
                                            <td><span class="badge badge-unused">Unused</span></td>
                                            <td><span class="badge badge-review"><?= htmlspecialchars($u['confidence']) ?></span></td>
                                            <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($u['reason']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Unsupported Syntax Rules -->
                                    <?php foreach (($analysis['unsupported'] ?? []) as $unsup): ?>
                                        <tr class="data-row" data-status="Review" data-scope="UNKNOWN">
                                            <td><code><?= htmlspecialchars($unsup['selector']) ?></code></td>
                                            <td><span class="badge badge-review">Needs Review</span>
                                        </td>
                                            <td><span style="color: var(--text-muted);">-</span></td>
                                            <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($unsup['reason']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                               </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="totop">[<a href="#head">Top</a>]</div><br>
            <?php endforeach; ?>
            <!-- Feature 7: JS Function & Event Analysis -->
            <h2 id="scriptSection" style="margin-top: 32px; margin-bottom: 16px;">Scripts &amp; Inline JS</h2>
            <?php foreach (($activeData['js_analysis'] ?? []) as $source => $analysis): ?>
                <div class="section-block filterable-section" data-type="JS">
                    <div class="section-title" onclick="toggleCollapse(this)">
                        <span class="asset-key"><?= htmlspecialchars($source) ?></span>
                        <?php if (isset($analysis['status']) && $analysis['status'] === 'error'): ?>
                            <span class="badge badge-unused">Error</span>
                        <?php else: ?>
                            <div>
                                <span class="badge badge-scope" style="margin-right: 8px;"><?= $analysis['classification'] ?></span>
                                <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-muted);">
                                    <?= $analysis['unused_count'] ?> unused / <?= $analysis['total_functions'] ?> functions
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!isset($analysis['status'])): ?>
                        <div class="collapsible-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Function Name</th>
                                        <th style="width: 120px;">Status</th>
                                        <th style="width: 140px;">Scope / Matches</th>
                                        <th>Event Bindings &amp; Active Pages</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($analysis['used'] ?? []) as $func => $details): ?>
                                        <tr class="data-row" data-status="Used" data-scope="<?= $details['classification'] ?>">
                                            <td><code><?= htmlspecialchars($func) ?>()</code></td>
                                            <td><span class="badge badge-used">Used</span></td>
                                            <td>
                                                <span class="badge badge-scope"><?= $details['classification'] ?></span>
                                                <small>(<?= $details['count'] ?> ref)</small>
                                            </td>
                                            <td>
                                            <?php foreach (($details['event_bindings'] ?? []) as $evt): ?>
                                                <span class="badge badge-review" style="margin-right: 4px;"><?= htmlspecialchars($evt) ?></span>
                                            <?php endforeach; ?>
                                            <?php foreach (($details['pages'] ?? []) as $pUrl): ?>
                                                <span class="page-tag"><?= htmlspecialchars($pUrl) ?></span>
                                            <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach (($analysis['unused'] ?? []) as $func): ?>
                                        <tr class="data-row" data-status="Unused" data-scope="UNUSED">
                                            <td><code><?= htmlspecialchars($func) ?>()</code></td>
                                            <td><span class="badge badge-unused">Unused</span></td>
                                            <td><span style="color: var(--text-muted);">-</span></td>
                                            <td style="color: var(--text-muted);">-</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="totop">[<a href="#head">Top</a>]</div><br>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-state">
                <h1>Resource<span style="color:var(--accent-blue)">Scope</span></h1>
                <p>An advanced, multi-page web resource and inventory auditor designed for developer workflows to analyze code hygiene, asset utilization, and site structure.</p>
                <ul>
                    <li>Accepts multiple target URLs from CLI or HTTP requests</li>
                    <li>Discovers external, inline, and duplicate CSS and JavaScript assets</li>
                    <li>Tracks real-time HTTP status codes to identify broken or missing resources</li>
                    <li>Translates CSS selectors into XPath queries for DOM usage verification</li>
                    <li>Audits JavaScript functions, DOM event attributes, and event listeners</li>
                    <li>Persists comprehensive analysis reports as portable JSON files</li>
                    <li>Supports review tracking, report exporting, and report importing</li>
                    <li>Features live client-side searching and multi-parameter filtering</li>
                </ul>
                <div class="empty-state-cta" onclick="hlight()">
                    <h2>Select or analyze a URL set</h2>
                    <p style="margin-top: 8px;">Enter one or more comma-separated URLs in the sidebar to run your first code and inventory audit.</p>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <footer class="footer">
        <p>&copy; 2026 <a href="./index.php">ResourceScope</a>. All rights reserved.</p>
    </footer>
    <script>
    function reanalyzeUrls() {
        document.querySelector('[name="urls"]').value = <?= !empty($activeData['target_urls']) ? json_encode(implode(',', $activeData['target_urls'])) : '""'; ?>;
        document.querySelector('#analyzer-form').submit();
    }
    </script>
    <script src="js/main.js" type="text/javascript"></script>
</body>
</html>