<?php
/**
 * Plugin Name: IDX Element Scanner
 * Description: Scans for IDX Broker elements — pages, posts, shortcodes, widgets, sidebar areas, and nav menus.
 * Version: 3.0
 * Author: You
 */

if (!defined('ABSPATH')) exit;

if (defined('IDX_SCANNER_LOADED')) return;
define('IDX_SCANNER_LOADED', true);

// ── Admin Menu ──────────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_management_page('IDX Scanner', 'IDX Scanner', 'manage_options', 'idx-scanner', 'idx_scanner_page');
});

// ── AJAX: Get all published pages ───────────────────────────────────────────────
add_action('wp_ajax_idx_get_pages', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    $pages = get_posts([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    $result = [];
    foreach ($pages as $id) {
        $result[] = ['id' => $id, 'url' => get_permalink($id), 'title' => get_the_title($id)];
    }
    wp_send_json_success($result);
});

// ── AJAX: Scan a single page URL for IDX broker links (HTTP fetch) ──────────────
add_action('wp_ajax_idx_scan_page_links', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    $url = esc_url_raw($_POST['url'] ?? '');
    if (!$url) wp_send_json_error('No URL provided.');

    $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false]);
    if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

    $html  = wp_remote_retrieve_body($response);
    $links = [];

    // <a href="...idxbroker.com/..."> or idxre.com or mlsfinder.com
    if (preg_match_all(
        '/<a\b[^>]*\bhref=["\']([^"\']*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^"\']*)["\'][^>]*>(.*?)<\/a>/is',
        $html, $m, PREG_SET_ORDER
    )) {
        foreach ($m as $match) {
            $links[] = ['url' => $match[1], 'text' => trim(wp_strip_all_tags($match[2])) ?: '(no text)'];
        }
    }

    // <a href="/idx/..."> — internal IDX path links
    if (preg_match_all(
        '/<a\b[^>]*\bhref=["\']([^"\']*\/idx\/[^"\']*)["\'][^>]*>(.*?)<\/a>/is',
        $html, $m, PREG_SET_ORDER
    )) {
        foreach ($m as $match) {
            $links[] = ['url' => $match[1], 'text' => trim(wp_strip_all_tags($match[2])) ?: '(no text)'];
        }
    }

    // Deduplicate by URL
    $seen = []; $unique = [];
    foreach ($links as $link) {
        if (!isset($seen[$link['url']])) { $seen[$link['url']] = true; $unique[] = $link; }
    }

    wp_send_json_success(['url' => $url, 'links' => $unique]);
});

// ── AJAX: Scan posts & all CPTs for IDX broker links (DB) ──────────────────────
add_action('wp_ajax_idx_scan_post_links', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $excluded = [
        'page','attachment','revision','nav_menu_item','custom_css','customize_changeset',
        'oembed_cache','user_request','wp_block','wp_template','wp_template_part',
        'wp_global_styles','wp_navigation','wp_font_face','wp_font_family',
    ];

    $all_types = array_values(get_post_types(['public' => true], 'names'));
    $queryable = array_values(get_post_types(['publicly_queryable' => true], 'names'));
    $post_types = array_values(array_diff(array_unique(array_merge($all_types, $queryable)), $excluded));

    if (empty($post_types)) { wp_send_json_success([]); return; }

    $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_type, post_parent
             FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders)
               AND post_status IN ('publish','inherit')
               AND (  post_content LIKE '%idxbroker%'
                   OR post_content LIKE '%idxre.com%'
                   OR post_content LIKE '%mlsfinder%'
                   OR post_content LIKE '%/idx/%')",
            ...$post_types
        ),
        ARRAY_A
    );

    $found = [];
    foreach ($rows as $row) {
        $content = get_post_field('post_content', $row['ID']);
        $links = [];

        if (preg_match_all('/href=["\']([^"\']*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^"\']*)["\']/', $content, $m))
            foreach ($m[1] as $u) $links[] = $u;
        if (preg_match_all('/href=["\']([^"\']*\/idx\/[^"\']*)["\']/', $content, $m))
            foreach ($m[1] as $u) $links[] = $u;
        if (preg_match_all('/https?:\/\/[^\s"\'<>]*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^\s"\'<>]*/i', $content, $m))
            foreach ($m[0] as $u) $links[] = $u;

        $links = array_values(array_unique($links));
        if (empty($links)) continue;

        $parent_info = null;
        if (!empty($row['post_parent'])) {
            $parent = get_post($row['post_parent']);
            if ($parent) $parent_info = [
                'id' => $parent->ID, 'title' => $parent->post_title, 'url' => get_permalink($parent->ID),
            ];
        }

        $found[] = [
            'id'     => $row['ID'],
            'title'  => $row['post_title'],
            'type'   => $row['post_type'],
            'url'    => get_permalink($row['ID']),
            'parent' => $parent_info,
            'links'  => $links,
        ];
    }
    wp_send_json_success($found);
});

// ── AJAX: Scan all post types for IDX shortcodes (DB) ──────────────────────────
add_action('wp_ajax_idx_scan_shortcodes', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_parent
         FROM {$wpdb->posts}
         WHERE post_status IN ('publish','inherit','private')
           AND (post_content LIKE '%[IDX%' OR post_content LIKE '%[idx%')",
        ARRAY_A
    );

    $found = [];
    foreach ($rows as $row) {
        $content = get_post_field('post_content', $row['ID']);
        preg_match_all('/\[(IDX|idx)[^\]]*\]/i', $content, $sc);
        $shortcodes = array_values(array_unique($sc[0]));
        if (empty($shortcodes)) continue;

        $parent_info = null;
        if (!empty($row['post_parent'])) {
            $parent = get_post($row['post_parent']);
            if ($parent) $parent_info = [
                'id' => $parent->ID, 'title' => $parent->post_title, 'url' => get_permalink($parent->ID),
            ];
        }

        $found[] = [
            'id'         => $row['ID'],
            'title'      => $row['post_title'],
            'type'       => $row['post_type'],
            'url'        => get_permalink($row['ID']),
            'parent'     => $parent_info,
            'shortcodes' => $shortcodes,
        ];
    }
    wp_send_json_success($found);
});

// ── AJAX: Scan widget instances for IDX content + build full sidebar map ─────────
add_action('wp_ajax_idx_scan_widgets', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb, $wp_registered_sidebars;

    $idx_terms = ['idxbroker', 'idxre.com', 'mlsfinder.com', '[IDX', '[idx', 'IDX-', 'data-idx', '/idx/'];

    $sidebars_widgets = get_option('sidebars_widgets', []);

    // widget_id => sidebar_id
    $widget_sidebar_map = [];
    foreach ((array) $sidebars_widgets as $sid => $wids) {
        if (!is_array($wids)) continue;
        foreach ($wids as $wid) $widget_sidebar_map[$wid] = $sid;
    }

    // sidebar_id => human name
    $sidebar_names = [];
    foreach ((array) ($wp_registered_sidebars ?? []) as $id => $info) {
        $sidebar_names[$id] = $info['name'] ?? $id;
    }

    // Load all widget option rows
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\_%'",
        ARRAY_A
    );

    $idx_widgets    = []; // IDX-containing instances only
    $all_widget_data = []; // widget_key => {type, title} — every instance

    foreach ($rows as $row) {
        $data = maybe_unserialize($row['option_value']);
        if (!is_array($data)) continue;
        $type_slug = preg_replace('/^widget_/', '', $row['option_name']);

        foreach ($data as $instance_id => $instance) {
            if ($instance_id === '_multiwidget' || !is_array($instance)) continue;

            $wkey = $type_slug . '-' . $instance_id;
            $title = ($instance['title'] ?? '') ?: $type_slug;
            $all_widget_data[$wkey] = ['type' => $type_slug, 'title' => $title];

            // Flatten all string values to check for IDX content
            $parts = [];
            array_walk_recursive($instance, function ($v) use (&$parts) { if (is_string($v)) $parts[] = $v; });
            $flat = implode(' ', $parts);

            $matched = [];
            foreach ($idx_terms as $term) {
                if (stripos($flat, $term) !== false) $matched[] = $term;
            }
            if (stripos($row['option_name'], 'idx') !== false) $matched[] = 'widget-type:' . $type_slug;

            if (!empty($matched)) {
                $sid = $widget_sidebar_map[$wkey] ?? 'unassigned';

                // Extract actual IDX broker link URLs from the widget content
                $links = [];
                if (preg_match_all('/href=["\']([^"\']*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^"\']*)["\']/', $flat, $lm))
                    foreach ($lm[1] as $u) $links[] = $u;
                if (preg_match_all('/href=["\']([^"\']*\/idx\/[^"\']*)["\']/', $flat, $lm))
                    foreach ($lm[1] as $u) $links[] = $u;
                if (preg_match_all('/https?:\/\/[^\s"\'<>]*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^\s"\'<>]*/i', $flat, $lm))
                    foreach ($lm[0] as $u) $links[] = $u;

                $idx_widgets[] = [
                    'widget_key'   => $wkey,
                    'type'         => $type_slug,
                    'sidebar_id'   => $sid,
                    'sidebar_name' => $sidebar_names[$sid] ?? $sid,
                    'title'        => $title,
                    'matched'      => $matched,
                    'links'        => array_values(array_unique($links)),
                ];
            }
        }
    }

    // ── Theme file scan: sidebar_id => template files that call dynamic_sidebar() ──
    $theme_dir  = get_stylesheet_directory();
    $parent_dir = get_template_directory();

    // Normalize to forward slashes for consistent string matching
    $theme_dir_n  = rtrim(str_replace('\\', '/', $theme_dir), '/');
    $parent_dir_n = rtrim(str_replace('\\', '/', $parent_dir), '/');

    $file_sidebars       = []; // rel => [sidebar_ids called in this file]
    $file_includes       = []; // rel => [partial paths loaded via get_template_part/include]
    $get_sidebar_callers = []; // files that call get_sidebar()

    foreach (array_unique([$theme_dir_n, $parent_dir_n]) as $dir) {
        if (!is_dir($dir)) continue;
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->getExtension() !== 'php') continue;
                $src = @file_get_contents($file->getPathname());
                if (!$src) continue;
                $norm = str_replace('\\', '/', $file->getPathname());
                $rel  = ltrim(str_replace([$theme_dir_n, $parent_dir_n], '', $norm), '/');

                // dynamic_sidebar() calls
                preg_match_all("/dynamic_sidebar\s*\(\s*['\"]([^'\"]+)['\"]/", $src, $dm);
                if ($dm[1]) {
                    foreach ($dm[1] as $sid) $file_sidebars[$rel][] = $sid;
                }

                // get_sidebar() calls
                if (preg_match('/\bget_sidebar\s*\(/', $src)) $get_sidebar_callers[] = $rel;

                // get_template_part('path/name') — records what parts this file loads
                preg_match_all("/get_template_part\s*\(\s*['\"]([^'\"]+)['\"]/", $src, $gtp);
                foreach ($gtp[1] as $part) $file_includes[$rel][] = $part;

                // include/require with relative paths
                preg_match_all(
                    "/(?:include|require)(?:_once)?\s*[\(\s]*(?:get_(?:template|stylesheet)_directory\(\)\s*\.\s*['\"]\/?)?" .
                    "['\"]([^'\"]+\.php)['\"]/",
                    $src, $inc
                );
                foreach ($inc[1] as $inc_file) {
                    $file_includes[$rel][] = ltrim(str_replace('\\', '/', $inc_file), '/');
                }
            }
        } catch (Exception $e) {}
    }

    // Build sidebar_templates: sidebar_id => direct files
    $sidebar_templates = [];
    foreach ($file_sidebars as $rel => $sids) {
        foreach ($sids as $sid) $sidebar_templates[$sid][] = $rel;
    }

    // Propagate get_sidebar() callers into sidebars rendered via sidebar.php
    foreach ($sidebar_templates as $sid => $templates) {
        if (in_array('sidebar.php', $templates, true)) {
            $sidebar_templates[$sid] = array_values(array_unique(
                array_merge($templates, $get_sidebar_callers)
            ));
        }
    }

    // Expand: for sidebars found only in template parts (non-root files),
    // trace back one level to find the root templates that load those parts.
    foreach ($sidebar_templates as $sid => $direct_files) {
        $extra = [];
        foreach ($direct_files as $rel) {
            // If this file is in a subdirectory it's a template part — find who includes it
            if (strpos($rel, '/') === false) continue; // already a root file
            $rel_no_ext = preg_replace('/\.php$/', '', $rel);
            foreach ($file_includes as $includer => $parts) {
                foreach ($parts as $part) {
                    $part_norm = ltrim(str_replace('\\', '/', $part), '/');
                    // Match by base path (get_template_part uses path without .php)
                    if ($part_norm === $rel_no_ext || $part_norm === $rel ||
                        basename($part_norm) === basename($rel_no_ext)) {
                        $extra[] = $includer;
                    }
                }
            }
        }
        if ($extra) {
            $sidebar_templates[$sid] = array_values(array_unique(array_merge($direct_files, $extra)));
        }
    }

    // For any sidebar still without page mapping (dynamic_sidebar not found in theme),
    // fall back to querying pages that use the default template if the sidebar name
    // suggests it is the primary or global sidebar.
    $fallback_pages = null; // lazy-loaded

    // Helper: map a template file to pages
    $tpl_to_pages = function ($rel) use ($wpdb, $theme_dir, $parent_dir) {
        $base = basename($rel);
        if ($base === 'front-page.php') {
            $id = (int) get_option('page_on_front');
            return $id ? [['title' => get_the_title($id), 'url' => get_permalink($id)]]
                       : [['title' => 'Front page', 'url' => home_url('/')]];
        }
        if ($base === 'home.php') {
            $id = (int) get_option('page_for_posts');
            return $id ? [['title' => get_the_title($id), 'url' => get_permalink($id)]]
                       : [['title' => 'Blog index', 'url' => home_url('/')]];
        }
        if (in_array($base, ['page.php', 'index.php', 'sidebar.php',
                             'archive.php', 'category.php', 'tag.php',
                             'author.php', 'search.php', '404.php'], true))
            return [['title' => 'All pages (sitewide)', 'url' => '']];
        if ($base === 'single.php')
            return [['title' => 'All single posts', 'url' => '']];
        if (preg_match('/^page-(.+)\.php$/', $base, $pm)) {
            $p = is_numeric($pm[1]) ? get_post((int) $pm[1]) : get_page_by_path($pm[1]);
            if ($p) return [['title' => $p->post_title, 'url' => get_permalink($p->ID)]];
        }
        if (preg_match('/^single-(.+)\.php$/', $base, $pm))
            return [['title' => 'All ' . $pm[1] . ' posts', 'url' => '']];

        // Check for "Template Name:" header (custom page templates)
        $full = file_exists("$theme_dir/$rel") ? "$theme_dir/$rel"
              : (file_exists("$parent_dir/$rel") ? "$parent_dir/$rel" : '');
        if ($full) {
            $hdrs = get_file_data($full, ['Template Name' => 'Template Name']);
            if (!empty($hdrs['Template Name'])) {
                $uses = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                         WHERE pm.meta_key = '_wp_page_template' AND pm.meta_value = %s
                           AND p.post_status = 'publish'",
                        $rel
                    ), ARRAY_A
                );
                $result = [];
                foreach ($uses as $r) $result[] = ['title' => $r['post_title'], 'url' => get_permalink($r['ID'])];
                return $result ?: [['title' => 'Template: ' . $hdrs['Template Name'] . ' (no pages assigned)', 'url' => '']];
            }
        }
        return [['title' => 'Via: ' . $rel, 'url' => '']];
    };

    // Build the ordered list of all sidebar IDs to show
    $all_sidebar_ids = array_keys($sidebar_names);
    foreach ((array) $sidebars_widgets as $sid => $_) {
        if (strpos($sid, 'orphaned_widgets') !== 0 && $sid !== 'wp_inactive_widgets'
            && !in_array($sid, $all_sidebar_ids, true)) {
            $all_sidebar_ids[] = $sid;
        }
    }
    $all_sidebar_ids = array_values(array_filter(
        $all_sidebar_ids,
        function ($s) { return $s !== 'wp_inactive_widgets' && strpos($s, 'orphaned_widgets') !== 0; }
    ));

    // Build sidebar output: all areas with all widget instances + page mappings
    $sidebars_out = [];
    foreach ($all_sidebar_ids as $sid) {
        $wids_in = (array) ($sidebars_widgets[$sid] ?? []);
        $widgets_here = [];
        foreach ($wids_in as $wid) {
            if (isset($all_widget_data[$wid])) {
                $widgets_here[] = [
                    'id'    => $wid,
                    'type'  => $all_widget_data[$wid]['type'],
                    'title' => $all_widget_data[$wid]['title'],
                ];
            } else {
                // Block widget or unregistered — surface what we know from the widget ID
                $widgets_here[] = [
                    'id'    => $wid,
                    'type'  => preg_replace('/-\d+$/', '', $wid),
                    'title' => $wid,
                ];
            }
        }

        $templates = $sidebar_templates[$sid] ?? [];
        $pages     = [];
        foreach ($templates as $tpl) {
            foreach ($tpl_to_pages($tpl) as $pg) {
                $pages[$pg['url'] ?: $pg['title']] = $pg;
            }
        }

        // If theme scan found nothing useful, fall back to heuristics
        if (empty($pages)) {
            $name_lc = strtolower($sidebar_names[$sid] ?? $sid);
            if (preg_match('/footer/', $name_lc)) {
                $pages['sitewide'] = ['title' => 'Sitewide (footer)', 'url' => ''];
            } elseif (preg_match('/header/', $name_lc)) {
                $pages['sitewide'] = ['title' => 'Sitewide (header)', 'url' => ''];
            } else {
                // Query pages using the default template as the best available signal
                if ($fallback_pages === null) {
                    $rows = $wpdb->get_results(
                        "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                         LEFT JOIN {$wpdb->postmeta} pm
                           ON pm.post_id = p.ID AND pm.meta_key = '_wp_page_template'
                         WHERE p.post_status = 'publish' AND p.post_type = 'page'
                           AND (pm.meta_value IS NULL OR pm.meta_value IN ('default',''))",
                        ARRAY_A
                    );
                    $fallback_pages = [];
                    foreach ($rows as $r) {
                        $fallback_pages[] = ['title' => $r['post_title'], 'url' => get_permalink($r['ID'])];
                    }
                }
                foreach ($fallback_pages as $pg) $pages[$pg['url']] = $pg;
            }
        }

        $sidebars_out[] = [
            'id'      => $sid,
            'name'    => $sidebar_names[$sid] ?? $sid,
            'widgets' => $widgets_here,
            'pages'   => array_values($pages),
        ];
    }

    wp_send_json_success(['idx_widgets' => $idx_widgets, 'sidebars' => $sidebars_out]);
});

// ── AJAX: Scan nav menus for IDX broker URLs ─────────────────────────────────────
add_action('wp_ajax_idx_scan_navmenus', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');

    $menus = wp_get_nav_menus();
    $found = [];

    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        if (!$items) continue;
        foreach ($items as $item) {
            $url = $item->url ?? '';
            if (preg_match('/idxbroker\.com|idxre\.com|mlsfinder\.com|\/idx\//i', $url)) {
                $found[] = ['menu' => $menu->name, 'item' => $item->title, 'url' => $url];
            }
        }
    }
    wp_send_json_success($found);
});

// ── Admin Page ──────────────────────────────────────────────────────────────────
function idx_scanner_page() {
    $nonce    = wp_create_nonce('idx_scanner_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <div class="wrap">
        <h1>IDX Element Scanner <span style="font-size:13px;color:#999;font-weight:normal;">v3.0</span></h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a class="nav-tab nav-tab-active" onclick="switchTab('page',this);return false;" href="#">Current Page</a>
            <a class="nav-tab" onclick="switchTab('pages',this);return false;" href="#">Pages</a>
            <a class="nav-tab" onclick="switchTab('posts',this);return false;" href="#">Posts</a>
            <a class="nav-tab" onclick="switchTab('shortcodes',this);return false;" href="#">Shortcodes</a>
            <a class="nav-tab" onclick="switchTab('widgets',this);return false;" href="#">Widgets</a>
            <a class="nav-tab" onclick="switchTab('sidebars',this);return false;" href="#">Sidebar Areas</a>
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
            <p>Drag this link to your bookmarks bar, then click it on any page to scan and highlight IDX elements:</p>
            <a id="idx-bookmarklet"
               href="javascript:(function(){var s=['[id^=&quot;IDX-&quot;]','[class*=&quot;idx-&quot;]','[class*=&quot;IDX-&quot;]','[id*=&quot;idx&quot;]','[src*=&quot;idxbroker&quot;]','[href*=&quot;idxbroker&quot;]','[data-idx]','[data-idx-id]','[data-idx-widget]','[data-idx-page]'];var f=new Set();s.forEach(function(q){document.querySelectorAll(q).forEach(function(e){f.add(e);e.style.outline='3px solid #d63638';e.style.background='rgba(214,54,56,0.12)';});});alert('IDX elements found: '+f.size);})();"
               style="display:inline-block;padding:7px 14px;background:#0073aa;color:#fff;border-radius:3px;text-decoration:none;font-size:13px;cursor:move;">
                &#128269; IDX Scan
            </a>
        </div>

        <!-- ── Tab: Pages ── -->
        <div id="tab-pages" class="idx-tab" style="display:none;">
            <p>Fetches every published page and lists any IDX Broker links found — including rendered output from shortcodes and widgets.</p>
            <button id="pages-start-btn" class="button button-primary">Scan Pages</button>
            <button id="pages-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="pages-progress" style="margin-top:16px;display:none;">
                <progress id="pages-bar" value="0" max="100" style="width:100%;height:18px;"></progress>
                <p id="pages-status" style="margin:6px 0;color:#555;font-style:italic;"></p>
            </div>
            <div id="pages-summary" style="margin-top:12px;display:none;"></div>
            <table id="pages-table" class="widefat striped" style="margin-top:12px;display:none;">
                <thead>
                    <tr>
                        <th style="width:22%">Page</th>
                        <th style="width:28%">Page URL</th>
                        <th>IDX Link</th>
                        <th style="width:18%">Link Text</th>
                    </tr>
                </thead>
                <tbody id="pages-tbody"></tbody>
            </table>
        </div>

        <!-- ── Tab: Posts ── -->
        <div id="tab-posts" class="idx-tab" style="display:none;">
            <p>Searches <code>post_content</code> across all posts and custom post types for IDX Broker links.</p>
            <button id="posts-scan-btn" class="button button-primary">Scan Posts</button>
            <button id="posts-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="posts-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: Shortcodes ── -->
        <div id="tab-shortcodes" class="idx-tab" style="display:none;">
            <p>Searches <code>post_content</code> across all pages, posts, and custom post types for IDX shortcodes like <code>[IDX-listings]</code>.</p>
            <button id="shortcodes-scan-btn" class="button button-primary">Scan Shortcodes</button>
            <button id="shortcodes-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="shortcodes-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: Widgets ── -->
        <div id="tab-widgets" class="idx-tab" style="display:none;">
            <p>Scans all WordPress widget instances for IDX Broker content. Shows the page the widget appears on, the widget being used, and where on the page it is displayed.</p>
            <button id="widgets-scan-btn" class="button button-primary">Scan Widgets</button>
            <button id="widgets-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="widgets-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: Sidebar Areas ── -->
        <div id="tab-sidebars" class="idx-tab" style="display:none;">
            <p>Lists every registered sidebar / widget area, showing all widgets it contains and the pages it displays on.</p>
            <button id="sidebars-scan-btn" class="button button-primary">Scan Sidebar Areas</button>
            <button id="sidebars-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="sidebars-results" style="margin-top:16px;"></div>
        </div>

        <!-- ── Tab: Nav Menus ── -->
        <div id="tab-navmenus" class="idx-tab" style="display:none;">
            <p>Scans all navigation menus for IDX Broker URLs.</p>
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
        .idx-tag { display:inline-block; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:600; margin:1px; }
        .idx-tag.red  { background:#d63638; color:#fff; }
        .idx-tag.blue { background:#0073aa; color:#fff; }
        .idx-tag.grey { background:#ddd; color:#555; }
    </style>

    <script>
    const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
    const nonce   = '<?php echo esc_js($nonce); ?>';

    // ── Tab switching ───────────────────────────────────────────────────────────
    function switchTab(id, el) {
        document.querySelectorAll('.idx-tab').forEach(t => t.style.display = 'none');
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
        document.getElementById('tab-' + id).style.display = '';
        el.classList.add('nav-tab-active');
    }

    // ── Current Page Scanner ────────────────────────────────────────────────────
    const IDX_SELECTORS = [
        '[id^="IDX-"]','[class*="idx-"]','[class*="IDX-"]','[id*="idx"]',
        '[src*="idxbroker"]','[href*="idxbroker"]',
        '[data-idx]','[data-idx-id]','[data-idx-widget]','[data-idx-page]'
    ];

    let pageResults = [];
    let highlightOn = false;

    document.getElementById('idx-scan-btn').addEventListener('click', function () {
        const found = new Set();
        IDX_SELECTORS.forEach(s => document.querySelectorAll(s).forEach(el => found.add(el)));

        pageResults = [];
        const lines = ['Total IDX elements found: ' + found.size, '─'.repeat(44)];

        [...found].forEach((el, i) => {
            const snippet = el.outerHTML.substring(0, 200) + (el.outerHTML.length > 200 ? '…' : '');
            lines.push((i + 1) + '.  ' + snippet);
            pageResults.push({ tag: el.tagName, id: el.id || '', classes: typeof el.className === 'string' ? el.className : '', snippet });
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

    // ── Pages Scanner (HTTP crawl) ──────────────────────────────────────────────
    let pagesResults = [];

    document.getElementById('pages-start-btn').addEventListener('click', async function () {
        this.disabled = true;
        pagesResults  = [];
        document.getElementById('pages-table').style.display    = 'none';
        document.getElementById('pages-tbody').innerHTML         = '';
        document.getElementById('pages-progress').style.display  = '';
        document.getElementById('pages-export-btn').disabled     = true;
        document.getElementById('pages-summary').style.display   = 'none';
        document.getElementById('pages-status').textContent      = 'Fetching page list…';

        const urlRes = await ajax('idx_get_pages');
        if (!urlRes.success) { alert('Error: ' + urlRes.data); this.disabled = false; return; }

        const pages = urlRes.data;
        const bar   = document.getElementById('pages-bar');
        bar.max = pages.length;
        bar.value = 0;

        let withLinks = 0, clean = 0;

        for (let i = 0; i < pages.length; i++) {
            const pg = pages[i];
            document.getElementById('pages-status').textContent =
                'Scanning ' + (i + 1) + ' / ' + pages.length + ' — ' + pg.title;

            const res   = await ajax('idx_scan_page_links', { url: pg.url });
            const links = res.success ? (res.data.links || []) : [];

            if (links.length === 0) {
                clean++;
            } else {
                withLinks++;
                const tbody = document.getElementById('pages-tbody');
                links.forEach((lk, li) => {
                    const tr = document.createElement('tr');
                    const pageCell = li === 0
                        ? '<td rowspan="' + links.length + '" style="vertical-align:top;font-weight:600;">' +
                          '<a href="' + h(pg.url) + '" target="_blank">' + h(pg.title) + '</a></td>' +
                          '<td rowspan="' + links.length + '" style="vertical-align:top;font-size:11px;word-break:break-all;">' +
                          '<code>' + h(pg.url) + '</code></td>'
                        : '';
                    tr.innerHTML = pageCell +
                        '<td style="font-size:11px;word-break:break-all;"><code>' + h(lk.url) + '</code></td>' +
                        '<td style="font-size:12px;">' + h(lk.text) + '</td>';
                    tbody.appendChild(tr);
                    pagesResults.push({ page: pg.title, page_url: pg.url, link_url: lk.url, link_text: lk.text });
                });
            }
            bar.value = i + 1;
        }

        const summary = document.getElementById('pages-summary');
        summary.style.display = '';
        summary.innerHTML =
            '<strong>' + pages.length + '</strong> pages scanned — ' +
            '<span style="color:#d63638;font-weight:600;">' + withLinks + ' with IDX links</span>, ' +
            '<span style="color:#00a32a;">' + clean + ' clean</span>.';

        if (withLinks > 0) document.getElementById('pages-table').style.display = '';
        document.getElementById('pages-status').textContent  = 'Done.';
        document.getElementById('pages-export-btn').disabled = pagesResults.length === 0;
        this.disabled = false;
    });

    document.getElementById('pages-export-btn').addEventListener('click', function () {
        exportCSV(
            pagesResults.map(r => [r.page, r.page_url, r.link_url, r.link_text]),
            ['Page', 'Page URL', 'IDX Link', 'Link Text'],
            'idx-pages.csv'
        );
    });

    // ── Posts Scanner (DB) ──────────────────────────────────────────────────────
    let postsResults = [];

    document.getElementById('posts-scan-btn').addEventListener('click', async function () {
        this.disabled = true;
        postsResults  = [];
        const div = document.getElementById('posts-results');
        div.innerHTML = '<em>Scanning database…</em>';

        const res = await ajax('idx_scan_post_links');
        this.disabled = false;

        if (!res.success) { div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>'; return; }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX Broker links found in any posts or custom post types.</p>';
            return;
        }

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Post / Item</th><th>Type</th><th>Parent Page</th><th>IDX Links Found</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(p => {
            const parentCell = p.parent
                ? '<a href="' + h(p.parent.url) + '" target="_blank">' + h(p.parent.title) + '</a>'
                : '—';
            const linksHtml = p.links.map(l => '<div style="font-size:11px;font-family:monospace;word-break:break-all;">' + h(l) + '</div>').join('');
            p.links.forEach(l => postsResults.push({ title: p.title, type: p.type, url: p.url, parent: p.parent ? p.parent.title : '', link: l }));
            html +=
                '<tr>' +
                '<td><a href="' + h(p.url) + '" target="_blank">' + h(p.title) + '</a></td>' +
                '<td><code>' + h(p.type) + '</code></td>' +
                '<td>' + parentCell + '</td>' +
                '<td>' + linksHtml + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('posts-export-btn').disabled = false;
    });

    document.getElementById('posts-export-btn').addEventListener('click', function () {
        exportCSV(
            postsResults.map(r => [r.title, r.type, r.url, r.parent, r.link]),
            ['Post / Item', 'Type', 'URL', 'Parent Page', 'IDX Link'],
            'idx-posts.csv'
        );
    });

    // ── Shortcodes Scanner (DB) ─────────────────────────────────────────────────
    let shortcodesResults = [];

    document.getElementById('shortcodes-scan-btn').addEventListener('click', async function () {
        this.disabled     = true;
        shortcodesResults = [];
        const div = document.getElementById('shortcodes-results');
        div.innerHTML = '<em>Scanning database…</em>';

        const res = await ajax('idx_scan_shortcodes');
        this.disabled = false;

        if (!res.success) { div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>'; return; }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX shortcodes found in any published content.</p>';
            return;
        }

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Page / Post / Item</th><th>Type</th><th>Parent Page</th><th>Shortcodes Found</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(p => {
            const parentCell = p.parent
                ? '<a href="' + h(p.parent.url) + '" target="_blank">' + h(p.parent.title) + '</a>'
                : '—';
            const scHtml = p.shortcodes.map(s => '<code style="display:block;margin:1px 0;">' + h(s) + '</code>').join('');
            shortcodesResults.push({ title: p.title, type: p.type, url: p.url, parent: p.parent ? p.parent.title : '', shortcodes: p.shortcodes.join(' | ') });
            html +=
                '<tr>' +
                '<td><a href="' + h(p.url) + '" target="_blank">' + h(p.title) + '</a></td>' +
                '<td><code>' + h(p.type) + '</code></td>' +
                '<td>' + parentCell + '</td>' +
                '<td>' + scHtml + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('shortcodes-export-btn').disabled = false;
    });

    document.getElementById('shortcodes-export-btn').addEventListener('click', function () {
        exportCSV(
            shortcodesResults.map(r => [r.title, r.type, r.url, r.parent, r.shortcodes]),
            ['Page / Post / Item', 'Type', 'URL', 'Parent Page', 'Shortcodes'],
            'idx-shortcodes.csv'
        );
    });

    // ── Shared widget data fetch (Widgets + Sidebar Areas tabs reuse same request) ─
    let widgetRawData = null;

    async function fetchWidgetData(statusDiv) {
        if (widgetRawData) return widgetRawData;
        statusDiv.innerHTML = '<em>Scanning widgets and sidebar areas…</em>';
        const res = await ajax('idx_scan_widgets');
        if (res.success) widgetRawData = res;
        return res;
    }

    // ── Widgets Scanner ─────────────────────────────────────────────────────────
    let widgetsResults = [];

    document.getElementById('widgets-scan-btn').addEventListener('click', async function () {
        this.disabled  = true;
        widgetsResults = [];
        widgetRawData  = null; // force fresh fetch
        const div = document.getElementById('widgets-results');

        const res = await fetchWidgetData(div);
        this.disabled = false;

        if (!res.success) { div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>'; return; }

        const idxWidgets = res.data.idx_widgets || [];
        const sidebars   = res.data.sidebars    || [];
        const sbPages    = {};
        sidebars.forEach(sb => { sbPages[sb.id] = sb.pages || []; });

        if (idxWidgets.length === 0) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX content found in any widget instances.</p>';
            document.getElementById('widgets-export-btn').disabled = true;
            return;
        }

        // Derive a physical position from the sidebar name/ID
        function widgetPosition(name, id) {
            const s = (name + ' ' + id).toLowerCase();
            if (/header|top[\s_-]bar|top[\s_-]nav/.test(s)) return 'Header';
            if (/footer/.test(s))                            return 'Footer';
            if (/right/.test(s))                             return 'Right side';
            if (/left/.test(s))                              return 'Left side';
            if (/top/.test(s))                               return 'Top';
            if (/bottom/.test(s))                            return 'Bottom';
            if (/content|main[\s_-]content/.test(s))         return 'Content area';
            // Strip generic suffixes and return the cleaned name
            return name.replace(/\b(widget\s*area|widget\s*zone|widgets?)\b/gi, '').trim() || name || id;
        }

        // Build one row per page+widget combination, sorted by page title
        const rows = [];
        idxWidgets.forEach(w => {
            const pages    = sbPages[w.sidebar_id] || [];
            const position = widgetPosition(w.sidebar_name || '', w.sidebar_id || '');
            if (pages.length) {
                pages.forEach(pg => rows.push({ page_title: pg.title, page_url: pg.url || '', widget: w.title, position }));
            } else {
                rows.push({ page_title: '', page_url: '', widget: w.title, position });
            }
        });
        rows.sort((a, b) => a.page_title.localeCompare(b.page_title));
        widgetsResults = rows;

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th style="width:30%">Page</th>' +
            '<th style="width:35%">Widget</th>' +
            '<th>Where on Page</th>' +
            '</tr></thead><tbody>';

        rows.forEach(r => {
            const pageCell = r.page_url
                ? '<a href="' + h(r.page_url) + '" target="_blank"><strong>' + h(r.page_title) + '</strong></a>'
                : r.page_title
                    ? '<span style="color:#888;">' + h(r.page_title) + '</span>'
                    : '<em style="color:#aaa;">Could not detect page — check Sidebar Areas tab</em>';
            html +=
                '<tr>' +
                '<td>' + pageCell + '</td>' +
                '<td><strong>' + h(r.widget) + '</strong></td>' +
                '<td>' + h(r.position) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('widgets-export-btn').disabled = false;
    });

    document.getElementById('widgets-export-btn').addEventListener('click', function () {
        exportCSV(
            widgetsResults.map(r => [r.page_title, r.page_url, r.widget, r.position]),
            ['Page', 'Page URL', 'Widget', 'Where on Page'],
            'idx-widgets.csv'
        );
    });

    // ── Sidebar Areas Scanner ───────────────────────────────────────────────────
    let sidebarsResults = [];

    document.getElementById('sidebars-scan-btn').addEventListener('click', async function () {
        this.disabled   = true;
        sidebarsResults = [];
        const div = document.getElementById('sidebars-results');

        const res = await fetchWidgetData(div);
        this.disabled = false;

        if (!res.success) { div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>'; return; }

        const sidebars   = res.data.sidebars    || [];
        const idxWidgets = res.data.idx_widgets  || [];

        if (sidebars.length === 0) {
            div.innerHTML = '<p style="color:#888;"><em>No registered sidebar areas found.</em></p>';
            return;
        }

        // Build a map: sidebar_id => idx links found in its widgets
        const sbIdxLinks = {};
        idxWidgets.forEach(w => {
            if (!sbIdxLinks[w.sidebar_id]) sbIdxLinks[w.sidebar_id] = [];
            (w.links || []).forEach(l => {
                if (!sbIdxLinks[w.sidebar_id].includes(l)) sbIdxLinks[w.sidebar_id].push(l);
            });
        });

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th style="width:18%">Sidebar Area</th>' +
            '<th style="width:25%">Widgets in This Area</th>' +
            '<th style="width:25%">IDX Links in This Sidebar</th>' +
            '<th>Pages This Sidebar Displays On</th>' +
            '</tr></thead><tbody>';

        sidebars.forEach(sb => {
            const widgetsHtml = sb.widgets.length
                ? sb.widgets.map(w =>
                    '<div style="margin:2px 0;"><span class="idx-tag blue">' + h(w.title) + '</span></div>'
                  ).join('')
                : '<span style="color:#aaa;font-size:11px;">(empty)</span>';

            const sbLinks     = sbIdxLinks[sb.id] || [];
            const linksHtml   = sbLinks.length
                ? sbLinks.map(l => '<div style="font-size:11px;font-family:monospace;word-break:break-all;margin:1px 0;">' + h(l) + '</div>').join('')
                : '<span style="color:#aaa;font-size:11px;">None found</span>';

            const pagesHtml = sb.pages.length
                ? sb.pages.map(pg =>
                    pg.url
                        ? '<a href="' + h(pg.url) + '" target="_blank" style="display:block;font-size:12px;">' + h(pg.title) + '</a>'
                        : '<span style="font-size:12px;color:#888;display:block;">' + h(pg.title) + '</span>'
                  ).join('')
                : '<span style="color:#aaa;font-size:11px;">Could not detect automatically</span>';

            html +=
                '<tr>' +
                '<td><strong>' + h(sb.name) + '</strong><br><code style="font-size:10px;color:#888;">' + h(sb.id) + '</code></td>' +
                '<td>' + widgetsHtml + '</td>' +
                '<td>' + linksHtml + '</td>' +
                '<td>' + pagesHtml + '</td>' +
                '</tr>';

            const widgetTitles = sb.widgets.map(w => w.title).join(', ') || '(empty)';
            if (sb.pages.length) {
                sb.pages.forEach(pg => sidebarsResults.push({ sidebar: sb.name, sidebar_id: sb.id, widgets: widgetTitles, idx_links: sbLinks.join(' | '), page: pg.title, page_url: pg.url || '' }));
            } else {
                sidebarsResults.push({ sidebar: sb.name, sidebar_id: sb.id, widgets: widgetTitles, idx_links: sbLinks.join(' | '), page: '', page_url: '' });
            }
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('sidebars-export-btn').disabled = false;
    });

    document.getElementById('sidebars-export-btn').addEventListener('click', function () {
        exportCSV(
            sidebarsResults.map(r => [r.sidebar, r.sidebar_id, r.widgets, r.idx_links, r.page, r.page_url]),
            ['Sidebar Area', 'Sidebar ID', 'Widgets In Area', 'IDX Links', 'Page', 'Page URL'],
            'idx-sidebar-areas.csv'
        );
    });

    // ── Nav Menus Scanner ───────────────────────────────────────────────────────
    let navmenusResults = [];

    document.getElementById('navmenus-scan-btn').addEventListener('click', async function () {
        this.disabled   = true;
        navmenusResults = [];
        const div = document.getElementById('navmenus-results');
        div.innerHTML = '<em>Scanning navigation menus…</em>';

        const res = await ajax('idx_scan_navmenus');
        this.disabled = false;

        if (!res.success) { div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>'; return; }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX Broker URLs found in any navigation menus.</p>';
            return;
        }

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th>Menu Name</th><th>Item Label</th><th>URL</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(m => {
            navmenusResults.push({ menu: m.menu, item: m.item, url: m.url });
            html +=
                '<tr>' +
                '<td>' + h(m.menu) + '</td>' +
                '<td>' + h(m.item) + '</td>' +
                '<td><code style="word-break:break-all;">' + h(m.url) + '</code></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('navmenus-export-btn').disabled = false;
    });

    document.getElementById('navmenus-export-btn').addEventListener('click', function () {
        exportCSV(
            navmenusResults.map(r => [r.menu, r.item, r.url]),
            ['Menu Name', 'Item Label', 'URL'],
            'idx-nav-menus.csv'
        );
    });

    // ── Utilities ───────────────────────────────────────────────────────────────
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
