<?php
/**
 * Plugin Name: IDX Element Scanner
 * Description: Scans for IDX Broker elements — current page, site-wide crawler, visual highlighter, CSV export, shortcode detector, and external script detector.
 * Version: 2.3
 * Author: You
 */

if (!defined('ABSPATH')) exit;

// ── Admin Menu ─────────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_management_page(
        'IDX Scanner',
        'IDX Scanner',
        'manage_options',
        'idx-scanner',
        'idx_scanner_page'
    );
});

// ── AJAX: Get all published URLs ───────────────────────────────────────────────
add_action('wp_ajax_idx_get_site_urls', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    $posts = get_posts([
        'post_type'   => ['page', 'post'],
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    $urls = [];
    foreach ($posts as $id) {
        $urls[] = [
            'id'    => $id,
            'url'   => get_permalink($id),
            'title' => get_the_title($id),
        ];
    }
    wp_send_json_success($urls);
});

// ── AJAX: Scan a single URL server-side ───────────────────────────────────────
add_action('wp_ajax_idx_scan_url', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    $url = esc_url_raw($_POST['url'] ?? '');
    if (!$url) wp_send_json_error('No URL provided.');

    $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false]);
    if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

    $html = wp_remote_retrieve_body($response);
    $patterns = [
        'id_prefix'      => '/id=["\']IDX-[^"\']+["\']/i',
        'class_idx'      => '/class=["\'][^"\']*\bidx[-_][^"\']*["\']/i',
        'src_idxbroker'  => '/src=["\'][^"\']*idxbroker[^"\']*["\']/i',
        'href_idxbroker' => '/href=["\'][^"\']*idxbroker[^"\']*["\']/i',
        'data_idx'       => '/data-idx(?:-\w+)?=["\'][^"\']*["\']/i',
    ];

    $found = [];
    foreach ($patterns as $type => $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[0] as $match) {
                $found[] = ['type' => $type, 'snippet' => substr($match, 0, 200)];
            }
        }
    }
    wp_send_json_success(['url' => $url, 'count' => count($found), 'elements' => $found]);
});

// ── AJAX: Scan DB for IDX shortcodes ──────────────────────────────────────────
add_action('wp_ajax_idx_scan_shortcodes', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_type
         FROM {$wpdb->posts}
         WHERE post_status = 'publish'
           AND (post_content LIKE '%[IDX%' OR post_content LIKE '%[idx%')",
        ARRAY_A
    );

    $found = [];
    foreach ($posts as $post) {
        $content = get_post_field('post_content', $post['ID']);
        preg_match_all('/\[(IDX|idx)[^\]]*\]/i', $content, $matches);
        if (!empty($matches[0])) {
            $found[] = [
                'id'         => $post['ID'],
                'title'      => $post['post_title'],
                'type'       => $post['post_type'],
                'url'        => get_permalink($post['ID']),
                'shortcodes' => array_values(array_unique($matches[0])),
            ];
        }
    }
    wp_send_json_success($found);
});

// ── AJAX: Detect external IDX scripts/styles ──────────────────────────────────
add_action('wp_ajax_idx_scan_scripts', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $domains        = ['idxbroker.com', 'idxre.com', 'mlsfinder.com'];
    $found_scripts  = [];
    $found_styles   = [];
    $inline_results = [];

    // Registered WP scripts
    $all_scripts = wp_scripts();
    foreach ($all_scripts->registered as $handle => $script) {
        foreach ($domains as $domain) {
            if (!empty($script->src) && strpos($script->src, $domain) !== false) {
                $found_scripts[] = ['handle' => $handle, 'src' => $script->src];
            }
        }
    }

    // Registered WP styles
    $all_styles = wp_styles();
    foreach ($all_styles->registered as $handle => $style) {
        foreach ($domains as $domain) {
            if (!empty($style->src) && strpos($style->src, $domain) !== false) {
                $found_styles[] = ['handle' => $handle, 'src' => $style->src];
            }
        }
    }

    // Inline post content referencing IDX domains
    foreach ($domains as $domain) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_content LIKE %s",
                '%' . $wpdb->esc_like($domain) . '%'
            ),
            ARRAY_A
        );
        foreach ($rows as $row) {
            $inline_results[] = [
                'id'     => $row['ID'],
                'title'  => $row['post_title'],
                'type'   => $row['post_type'],
                'url'    => get_permalink($row['ID']),
                'domain' => $domain,
            ];
        }
    }

    wp_send_json_success([
        'scripts' => $found_scripts,
        'styles'  => $found_styles,
        'inline'  => $inline_results,
    ]);
});

// ── AJAX: Scan wp_options widget data for IDX content ─────────────────────────
add_action('wp_ajax_idx_scan_widgets', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $idx_terms = ['idxbroker', 'idxre.com', 'mlsfinder.com', '[IDX', '[idx', 'IDX-', 'idx-', 'data-idx'];

    // Build sidebar assignment map: "text-2" => "sidebar-1"
    $sidebars_widgets = get_option('sidebars_widgets', []);
    $widget_sidebar_map = [];
    foreach ((array) $sidebars_widgets as $sidebar_id => $widget_ids) {
        if (!is_array($widget_ids)) continue;
        foreach ($widget_ids as $widget_id) {
            $widget_sidebar_map[$widget_id] = $sidebar_id;
        }
    }

    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\_%'",
        ARRAY_A
    );

    $found = [];

    foreach ($rows as $row) {
        $option_name = $row['option_name'];
        $data = maybe_unserialize($row['option_value']);
        if (!is_array($data)) continue;

        $type_slug = preg_replace('/^widget_/', '', $option_name);

        foreach ($data as $instance_id => $instance) {
            if ($instance_id === '_multiwidget' || !is_array($instance)) continue;

            $text_parts = [];
            array_walk_recursive($instance, function ($val) use (&$text_parts) {
                if (is_string($val)) $text_parts[] = $val;
            });
            $content = implode(' ', $text_parts);

            $matched_terms = [];
            foreach ($idx_terms as $term) {
                if (stripos($content, $term) !== false) {
                    $matched_terms[] = $term;
                }
            }

            if (!empty($matched_terms)) {
                $sidebar_widget_id = $type_slug . '-' . $instance_id;
                $sidebar = $widget_sidebar_map[$sidebar_widget_id] ?? 'unassigned';

                $found[] = [
                    'widget_type' => $option_name,
                    'instance_id' => $instance_id,
                    'sidebar'     => $sidebar,
                    'title'       => $instance['title'] ?? '(no title)',
                    'content'     => substr(wp_strip_all_tags($content), 0, 300),
                    'matched'     => $matched_terms,
                ];
            }
        }
    }

    wp_send_json_success($found);
});

// ── AJAX: Scan nav menus for IDX links ────────────────────────────────────────
add_action('wp_ajax_idx_scan_navmenus', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');

    $idx_terms = ['idxbroker', 'idxre.com', 'mlsfinder.com', 'IDX-', 'idx-', '[IDX', '[idx', 'data-idx'];
    $menus     = wp_get_nav_menus();
    $found     = [];

    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        if (!$items) continue;
        foreach ($items as $item) {
            $parts = [
                $item->url ?? '',
                $item->title ?? '',
                $item->attr_title ?? '',
                $item->classes ? implode(' ', (array) $item->classes) : '',
                $item->description ?? '',
            ];
            $content = implode(' ', $parts);
            $matched = [];
            foreach ($idx_terms as $term) {
                if (stripos($content, $term) !== false) $matched[] = $term;
            }
            if (!empty($matched)) {
                $found[] = [
                    'menu'    => $menu->name,
                    'item'    => $item->title,
                    'url'     => $item->url,
                    'matched' => $matched,
                ];
            }
        }
    }
    wp_send_json_success($found);
});

// ── Admin Page ─────────────────────────────────────────────────────────────────
function idx_scanner_page() {
    $nonce    = wp_create_nonce('idx_scanner_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <div class="wrap">
        <h1>IDX Element Scanner <span style="font-size:13px;color:#999;font-weight:normal;">v2.3</span></h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a class="nav-tab nav-tab-active" onclick="switchTab('page',this);return false;" href="#">Current Page</a>
            <a class="nav-tab" onclick="switchTab('crawler',this);return false;" href="#">Site Crawler</a>
            <a class="nav-tab" onclick="switchTab('shortcode',this);return false;" href="#">Shortcodes</a>
            <a class="nav-tab" onclick="switchTab('scripts',this);return false;" href="#">External Scripts</a>
            <a class="nav-tab" onclick="switchTab('widgets',this);return false;" href="#">Widgets / Sidebar</a>
            <a class="nav-tab" onclick="switchTab('navmenus',this);return false;" href="#">Nav Menus</a>
        </nav>

        <!-- ── Tab: Current Page ── -->
        <div id="tab-page" class="idx-tab">
            <p>Scans the DOM of the current page for IDX Broker elements. Use the bookmarklet below to scan any front-end page.</p>
            <button id="idx-scan-btn" class="button button-primary">Scan This Page</button>
            <button id="idx-highlight-btn" class="button" style="margin-left:8px;">Highlight Elements</button>
            <button id="idx-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <pre id="idx-results" style="margin-top:16px;background:#f7f7f7;padding:15px;border:1px solid #ddd;max-height:400px;overflow:auto;white-space:pre-wrap;"></pre>

            <h3>Front-end Bookmarklet</h3>
            <p>Drag this link to your bookmarks bar, then click it on any page of your site to scan and highlight IDX elements:</p>
            <a id="idx-bookmarklet"
               href="javascript:(function(){var s=['[id^=&quot;IDX-&quot;]','[class*=&quot;idx-&quot;]','[class*=&quot;IDX-&quot;]','[id*=&quot;idx&quot;]','[src*=&quot;idxbroker&quot;]','[href*=&quot;idxbroker&quot;]','[data-idx]','[data-idx-id]','[data-idx-widget]','[data-idx-page]'];var f=new Set();s.forEach(function(q){document.querySelectorAll(q).forEach(function(e){f.add(e);e.style.outline='3px solid #d63638';e.style.background='rgba(214,54,56,0.12)';});});alert('IDX elements found: '+f.size);})();"
               style="display:inline-block;padding:7px 14px;background:#0073aa;color:#fff;border-radius:3px;text-decoration:none;font-size:13px;cursor:move;">
                &#128269; IDX Scan
            </a>
        </div>

        <!-- ── Tab: Site Crawler ── -->
        <div id="tab-crawler" class="idx-tab" style="display:none;">
            <p>Crawls every published page and post on your site and counts IDX elements in each.</p>
            <button id="crawler-start-btn" class="button button-primary">Start Site Crawl</button>
            <button id="crawler-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>

            <div id="crawler-progress" style="margin-top:16px;display:none;">
                <progress id="crawler-bar" value="0" max="100" style="width:100%;height:18px;"></progress>
                <p id="crawler-status" style="margin:6px 0;color:#555;font-style:italic;"></p>
            </div>

            <table id="crawler-table" class="widefat striped" style="margin-top:16px;display:none;">
                <thead>
                    <tr>
                        <th>Page Title</th>
                        <th>URL</th>
                        <th style="text-align:center;">IDX Elements</th>
                    </tr>
                </thead>
                <tbody id="crawler-tbody"></tbody>
            </table>
        </div>

        <!-- ── Tab: Shortcodes ── -->
        <div id="tab-shortcode" class="idx-tab" style="display:none;">
            <p>Searches the database for posts/pages that contain IDX shortcodes like <code>[IDX-listings]</code> or <code>[idx-wrapper]</code>.</p>
            <button id="shortcode-scan-btn" class="button button-primary">Scan for Shortcodes</button>
            <button id="shortcode-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="shortcode-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: External Scripts ── -->
        <div id="tab-scripts" class="idx-tab" style="display:none;">
            <p>Detects WordPress-registered scripts/styles and post content referencing IDX domains: <code>idxbroker.com</code>, <code>idxre.com</code>, <code>mlsfinder.com</code>.</p>
            <button id="scripts-scan-btn" class="button button-primary">Detect External IDX Scripts</button>
            <button id="scripts-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="scripts-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: Widgets / Sidebar ── -->
        <div id="tab-widgets" class="idx-tab" style="display:none;">
            <p>Scans all WordPress widget instances stored in <code>wp_options</code> for IDX Broker URLs, saved links, and shortcodes — covering sidebars, footers, and any other registered widget areas.</p>
            <button id="widgets-scan-btn" class="button button-primary">Scan Widgets &amp; Sidebars</button>
            <button id="widgets-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="widgets-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: Nav Menus ── -->
        <div id="tab-navmenus" class="idx-tab" style="display:none;">
            <p>Scans all WordPress navigation menus for IDX Broker URLs, shortcodes, or class names — a common source of IDX appearing on every page.</p>
            <button id="navmenus-scan-btn" class="button button-primary">Scan Nav Menus</button>
            <button id="navmenus-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="navmenus-results" style="margin-top:16px;"></div>
        </div>
    </div>

    <style>
        .idx-tab .widefat th { background:#0073aa; color:#fff; }
        .idx-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:bold; color:#fff; }
        .idx-badge.found { background:#d63638; }
        .idx-badge.none  { background:#00a32a; }
    </style>

    <script>
    const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
    const nonce   = '<?php echo esc_js($nonce); ?>';

    // ── Tab switching ──────────────────────────────────────────────────────────
    function switchTab(id, el) {
        document.querySelectorAll('.idx-tab').forEach(t => t.style.display = 'none');
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
        document.getElementById('tab-' + id).style.display = '';
        el.classList.add('nav-tab-active');
    }

    // ── Current Page Scanner ───────────────────────────────────────────────────
    const IDX_SELECTORS = [
        '[id^="IDX-"]','[class*="idx-"]','[class*="IDX-"]','[id*="idx"]',
        '[src*="idxbroker"]','[href*="idxbroker"]',
        '[data-idx]','[data-idx-id]','[data-idx-widget]','[data-idx-page]'
    ];

    let pageResults  = [];
    let highlightOn  = false;

    document.getElementById('idx-scan-btn').addEventListener('click', function () {
        const found = new Set();
        IDX_SELECTORS.forEach(s => document.querySelectorAll(s).forEach(el => found.add(el)));

        pageResults = [];
        const lines = [
            'Total IDX elements found: ' + found.size,
            '─'.repeat(44)
        ];

        [...found].forEach((el, i) => {
            const snippet = el.outerHTML.substring(0, 200) + (el.outerHTML.length > 200 ? '…' : '');
            lines.push((i + 1) + '.  ' + snippet);
            pageResults.push({
                tag:     el.tagName,
                id:      el.id || '',
                classes: typeof el.className === 'string' ? el.className : '',
                snippet
            });
        });

        document.getElementById('idx-results').textContent = lines.join('\n');
        document.getElementById('idx-export-btn').disabled = found.size === 0;
    });

    document.getElementById('idx-highlight-btn').addEventListener('click', function () {
        highlightOn = !highlightOn;
        const found = new Set();
        IDX_SELECTORS.forEach(s => document.querySelectorAll(s).forEach(el => found.add(el)));
        [...found].forEach(el => {
            el.style.outline    = highlightOn ? '3px solid #d63638' : '';
            el.style.background = highlightOn ? 'rgba(214,54,56,0.12)' : '';
        });
        this.textContent = highlightOn ? 'Remove Highlights' : 'Highlight Elements';
    });

    document.getElementById('idx-export-btn').addEventListener('click', function () {
        exportCSV(
            pageResults.map(r => [r.tag, r.id, r.classes, r.snippet]),
            ['Tag', 'ID', 'Classes', 'HTML Snippet'],
            'idx-page-scan.csv'
        );
    });

    // ── Site Crawler ───────────────────────────────────────────────────────────
    let crawlerResults = [];

    document.getElementById('crawler-start-btn').addEventListener('click', async function () {
        this.disabled = true;
        crawlerResults = [];
        document.getElementById('crawler-table').style.display   = 'none';
        document.getElementById('crawler-tbody').innerHTML        = '';
        document.getElementById('crawler-progress').style.display = '';
        document.getElementById('crawler-export-btn').disabled    = true;
        document.getElementById('crawler-status').textContent     = 'Fetching list of pages…';

        const urlRes = await ajax('idx_get_site_urls');
        if (!urlRes.success) {
            alert('Error: ' + urlRes.data);
            this.disabled = false;
            return;
        }

        const urls = urlRes.data;
        const bar  = document.getElementById('crawler-bar');
        bar.max    = urls.length;
        bar.value  = 0;

        for (let i = 0; i < urls.length; i++) {
            const item = urls[i];
            document.getElementById('crawler-status').textContent =
                'Scanning ' + (i + 1) + ' / ' + urls.length + ' — ' + item.title;

            const res   = await ajax('idx_scan_url', { url: item.url });
            const count = res.success ? res.data.count : 0;
            crawlerResults.push({ title: item.title, url: item.url, count });

            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td><a href="' + h(item.url) + '" target="_blank">' + h(item.title) + '</a></td>' +
                '<td><code>' + h(item.url) + '</code></td>' +
                '<td style="text-align:center"><span class="idx-badge ' + (count > 0 ? 'found' : 'none') + '">' + count + '</span></td>';
            document.getElementById('crawler-tbody').appendChild(tr);
            bar.value = i + 1;
        }

        document.getElementById('crawler-table').style.display = '';
        document.getElementById('crawler-status').textContent  =
            'Done — ' + urls.length + ' pages scanned.';
        document.getElementById('crawler-export-btn').disabled = false;
        this.disabled = false;
    });

    document.getElementById('crawler-export-btn').addEventListener('click', function () {
        exportCSV(
            crawlerResults.map(r => [r.title, r.url, r.count]),
            ['Page Title', 'URL', 'IDX Element Count'],
            'idx-site-crawl.csv'
        );
    });

    // ── Shortcode Scanner ──────────────────────────────────────────────────────
    let shortcodeResults = [];

    document.getElementById('shortcode-scan-btn').addEventListener('click', async function () {
        this.disabled = true;
        shortcodeResults = [];
        const div = document.getElementById('shortcode-results');
        div.innerHTML = '<em>Scanning database…</em>';

        const res = await ajax('idx_scan_shortcodes');
        this.disabled = false;

        if (!res.success) {
            div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>';
            return;
        }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX shortcodes found in any published content.</p>';
            return;
        }

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Post Title</th><th>Type</th><th>Shortcodes Found</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(p => {
            const codes = p.shortcodes.join(', ');
            shortcodeResults.push({ title: p.title, type: p.type, url: p.url, shortcodes: codes });
            html +=
                '<tr>' +
                '<td><a href="' + h(p.url) + '" target="_blank">' + h(p.title) + '</a></td>' +
                '<td>' + h(p.type) + '</td>' +
                '<td><code>' + h(codes) + '</code></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('shortcode-export-btn').disabled = false;
    });

    document.getElementById('shortcode-export-btn').addEventListener('click', function () {
        exportCSV(
            shortcodeResults.map(r => [r.title, r.type, r.url, r.shortcodes]),
            ['Post Title', 'Type', 'URL', 'Shortcodes'],
            'idx-shortcodes.csv'
        );
    });

    // ── External Scripts Scanner ───────────────────────────────────────────────
    let scriptsResults = [];

    document.getElementById('scripts-scan-btn').addEventListener('click', async function () {
        this.disabled = true;
        scriptsResults = [];
        const div = document.getElementById('scripts-results');
        div.innerHTML = '<em>Scanning…</em>';

        const res = await ajax('idx_scan_scripts');
        this.disabled = false;

        if (!res.success) {
            div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>';
            return;
        }

        const { scripts, styles, inline } = res.data;
        let html = '';

        // Registered assets
        if (scripts.length || styles.length) {
            html += '<h3>Registered WordPress Scripts / Styles</h3>' +
                '<table class="widefat striped"><thead><tr>' +
                '<th>Type</th><th>Handle</th><th>Source URL</th>' +
                '</tr></thead><tbody>';
            scripts.forEach(s => {
                scriptsResults.push({ type: 'Script', handle: s.handle, src: s.src, post: '', url: '' });
                html += '<tr><td>Script</td><td><code>' + h(s.handle) + '</code></td><td><code>' + h(s.src) + '</code></td></tr>';
            });
            styles.forEach(s => {
                scriptsResults.push({ type: 'Style', handle: s.handle, src: s.src, post: '', url: '' });
                html += '<tr><td>Style</td><td><code>' + h(s.handle) + '</code></td><td><code>' + h(s.src) + '</code></td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="color:#00a32a;font-weight:bold;">No registered WP assets found loading from IDX domains.</p>';
        }

        // Inline content
        if (inline.length) {
            html += '<h3>Posts / Pages with Inline IDX Domain References</h3>' +
                '<table class="widefat striped"><thead><tr>' +
                '<th>Post Title</th><th>Type</th><th>Domain Detected</th>' +
                '</tr></thead><tbody>';
            inline.forEach(p => {
                scriptsResults.push({ type: 'Inline', handle: '', src: p.domain, post: p.title, url: p.url });
                html +=
                    '<tr>' +
                    '<td><a href="' + h(p.url) + '" target="_blank">' + h(p.title) + '</a></td>' +
                    '<td>' + h(p.type) + '</td>' +
                    '<td><code>' + h(p.domain) + '</code></td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="color:#00a32a;font-weight:bold;">No inline IDX domain references found in post content.</p>';
        }

        div.innerHTML = html;
        document.getElementById('scripts-export-btn').disabled = scriptsResults.length === 0;
    });

    document.getElementById('scripts-export-btn').addEventListener('click', function () {
        exportCSV(
            scriptsResults.map(r => [r.type, r.handle, r.src, r.post, r.url]),
            ['Type', 'Handle', 'Source / Domain', 'Post Title', 'Post URL'],
            'idx-external-scripts.csv'
        );
    });

    // ── Widgets / Sidebar Scanner ──────────────────────────────────────────────
    let widgetsResults = [];

    document.getElementById('widgets-scan-btn').addEventListener('click', async function () {
        this.disabled = true;
        widgetsResults = [];
        const div = document.getElementById('widgets-results');
        div.innerHTML = '<em>Scanning widget data in wp_options…</em>';

        const res = await ajax('idx_scan_widgets');
        this.disabled = false;

        if (!res.success) {
            div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>';
            return;
        }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX content found in any widget areas.</p>';
            return;
        }

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Widget Type</th><th>Sidebar / Area</th><th>Widget Title</th>' +
            '<th>Matched Terms</th><th>Content Preview</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(w => {
            widgetsResults.push({
                type:     w.widget_type,
                instance: w.instance_id,
                sidebar:  w.sidebar,
                title:    w.title,
                matched:  w.matched.join(', '),
                content:  w.content,
            });
            html +=
                '<tr>' +
                '<td><code>' + h(w.widget_type) + '</code></td>' +
                '<td><code>' + h(w.sidebar) + '</code></td>' +
                '<td>' + h(w.title) + '</td>' +
                '<td>' + w.matched.map(t => '<code>' + h(t) + '</code>').join(', ') + '</td>' +
                '<td style="max-width:300px;word-break:break-word;font-size:11px;">' + h(w.content) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('widgets-export-btn').disabled = false;
    });

    document.getElementById('widgets-export-btn').addEventListener('click', function () {
        exportCSV(
            widgetsResults.map(r => [r.type, r.instance, r.sidebar, r.title, r.matched, r.content]),
            ['Widget Type', 'Instance ID', 'Sidebar Area', 'Widget Title', 'Matched Terms', 'Content Preview'],
            'idx-widgets.csv'
        );
    });

    // ── Nav Menus Scanner ──────────────────────────────────────────────────────
    let navmenusResults = [];

    document.getElementById('navmenus-scan-btn').addEventListener('click', async function () {
        this.disabled = true;
        navmenusResults = [];
        const div = document.getElementById('navmenus-results');
        div.innerHTML = '<em>Scanning navigation menus…</em>';

        const res = await ajax('idx_scan_navmenus');
        this.disabled = false;

        if (!res.success) {
            div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>';
            return;
        }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX content found in any navigation menus.</p>';
            return;
        }

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Menu Name</th><th>Item Label</th><th>URL</th><th>Matched Terms</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(m => {
            navmenusResults.push({ menu: m.menu, item: m.item, url: m.url, matched: m.matched.join(', ') });
            html +=
                '<tr>' +
                '<td>' + h(m.menu) + '</td>' +
                '<td>' + h(m.item) + '</td>' +
                '<td><code>' + h(m.url) + '</code></td>' +
                '<td>' + m.matched.map(t => '<code>' + h(t) + '</code>').join(', ') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('navmenus-export-btn').disabled = false;
    });

    document.getElementById('navmenus-export-btn').addEventListener('click', function () {
        exportCSV(
            navmenusResults.map(r => [r.menu, r.item, r.url, r.matched]),
            ['Menu Name', 'Item Label', 'URL', 'Matched Terms'],
            'idx-nav-menus.csv'
        );
    });

    // ── Utilities ──────────────────────────────────────────────────────────────
    function ajax(action, data = {}) {
        const body = new URLSearchParams({ action, nonce, ...data });
        return fetch(ajaxUrl, { method: 'POST', body }).then(r => r.json());
    }

    function h(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function exportCSV(rows, headers, filename) {
        const csv = [headers, ...rows]
            .map(row => row.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(','))
            .join('\n');
        const a   = document.createElement('a');
        a.href    = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
        a.download = filename;
        a.click();
    }
    </script>
    <?php
}
