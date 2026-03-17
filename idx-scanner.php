<?php
/**
 * Plugin Name: AiDX Scanner
 * Description: Scans for IDX Broker elements — pages, posts, shortcodes, widgets, sidebar areas, and nav menus.
 * Version: 5.6
 * Author: You
 */

if (!defined('ABSPATH')) exit;

if (defined('IDX_SCANNER_LOADED')) return;
define('IDX_SCANNER_LOADED', true);

// ── Shared helper: get custom IDX search domain from WordPress options ─────────
// IMPress / imFORZA stores the site's branded IDX search subdomain in idxforza-info.
// Example: "search.collegestationhomes.com". Without this, links like
// https://search.collegestationhomes.com/i/luxury-homes would never be found.
function idx_scanner_get_search_domain() {
    $info = get_option('idxforza-info', []);
    if (is_string($info)) {
        $decoded = json_decode($info, true);
        if (is_array($decoded)) $info = $decoded;
    }
    $domain = is_array($info) ? trim($info['domain'] ?? '') : '';

    // Fallback: check idx_broker_subdomain (IDX Broker plugin) and similar options
    if (!$domain) {
        foreach (['idx_broker_subdomain','idx_broker_settings','idxbroker_domain'] as $opt) {
            $val = get_option($opt);
            if (is_string($val) && strpos($val, '.') !== false) { $domain = trim($val); break; }
            if (is_array($val) && !empty($val['subdomain'])) { $domain = trim($val['subdomain']); break; }
            if (is_array($val) && !empty($val['domain']))    { $domain = trim($val['domain']);    break; }
        }
    }

    // Fallback: IDX Saved Searches Exporter plugin stores the search subdomain in isse_subdomain.
    // It may be stored as a full URL ("https://search.collegestationhomes.com") or bare hostname.
    if (!$domain) {
        $isse = trim(get_option('isse_subdomain', ''));
        if ($isse && strpos($isse, '://') !== false) {
            $isse = parse_url($isse, PHP_URL_HOST) ?: '';
        }
        if ($isse && strpos($isse, '.') !== false) $domain = $isse;
    }

    // Final cleanup: strip protocol/slashes from any source (idxforza-info may also store full URLs)
    if ($domain && strpos($domain, '://') !== false) {
        $domain = parse_url($domain, PHP_URL_HOST) ?: $domain;
    }
    $domain = rtrim(trim($domain), '/');

    return $domain;
}

// ── Admin Menu ──────────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_management_page('AiDX Scanner', 'AiDX Scanner', 'manage_options', 'idx-scanner', 'idx_scanner_page');
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

// ── AJAX: Scan published pages for IDX content via database ────────────────────
// Database-first: no HTTP requests, no caching issues, finds shortcodes + blocks
// that never appear in rendered HTML. Instant even on sites with 200+ pages.
add_action('wp_ajax_idx_scan_pages_db', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $search_domain = idx_scanner_get_search_domain();
    $domain_clause = $search_domain
        ? ' OR post_content LIKE ' . $wpdb->prepare('%s', '%' . $wpdb->esc_like($search_domain) . '%')
        : '';

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- domain_clause is built via prepare()
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_content
         FROM {$wpdb->posts}
         WHERE post_type = 'page'
           AND post_status = 'publish'
           AND (  post_content LIKE '%idxbroker%'
               OR post_content LIKE '%idxre.com%'
               OR post_content LIKE '%mlsfinder%'
               OR post_content LIKE '%/idx/%'
               OR post_content LIKE '%[IDX%'
               OR post_content LIKE '%[idx%'
               OR post_content LIKE '%ihf%'
               OR post_content LIKE '%[impress%'
               OR post_content LIKE '%idx-broker-platinum%'
               OR post_content LIKE '%impress-carousel-block%'
               OR post_content LIKE '%impress-showcase-block%'
               {$domain_clause} )",
        ARRAY_A
    );

    $found = [];
    foreach ($rows as $row) {
        $id      = (int) $row['ID'];
        $content = $row['post_content'];
        $links   = [];

        // Known IDX platform domain URLs
        if (preg_match_all('#https?://[^\s"\'<>\\\\]*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^\s"\'<>\\\\]*#i', $content, $m))
            foreach ($m[0] as $u) $links[] = $u;

        // Site's own custom IDX search subdomain (e.g. search.collegestationhomes.com)
        if ($search_domain) {
            $pat = '#https?://[^\s"\'<>\\\\]*' . preg_quote($search_domain, '#') . '[^\s"\'<>\\\\]*#i';
            if (preg_match_all($pat, $content, $m))
                foreach ($m[0] as $u) $links[] = $u;
        }

        // Internal /idx/ path links
        if (preg_match_all('#href=["\']([^"\']*?/idx/[^"\']*)["\']#', $content, $m))
            foreach ($m[1] as $u) $links[] = $u;

        // Shortcodes: [IDX-*], [idx*], [ihf*], [impress*]
        if (preg_match_all('/\[(IDX|idx|ihf|impress)[^\]]*\]/i', $content, $m))
            foreach ($m[0] as $sc) $links[] = $sc;

        // Gutenberg block types
        if (preg_match_all('#<!-- wp:(idx-broker-platinum/[a-z-]+|impress-[a-z-]+-block)#', $content, $m))
            foreach ($m[1] as $blk) $links[] = $blk;

        // Gutenberg block widget IDs {"id":"909-42343"}
        if (preg_match_all('/"id":"(\d+)-(\d+)"/', $content, $m))
            foreach ($m[2] as $xid) $links[] = 'IDX Widget ' . $xid;

        $links = array_values(array_unique($links));

        // Fallback: SQL matched but no regex extracted anything.
        // Find the first matching keyword in content and show a context snippet.
        if (empty($links)) {
            $terms = ['idxbroker', 'idxre.com', 'mlsfinder', '/idx/', '[IDX', '[idx', '[ihf', '[impress',
                      'idx-broker-platinum', 'impress-carousel-block', 'impress-showcase-block'];
            if ($search_domain) $terms[] = $search_domain;
            foreach ($terms as $term) {
                $pos = stripos($content, $term);
                if ($pos !== false) {
                    $start   = max(0, $pos - 60);
                    $snippet = substr($content, $start, 200);
                    $links[] = '[snippet] …' . preg_replace('/\s+/', ' ', $snippet) . '…';
                    break;
                }
            }
            if (empty($links)) $links = ['[IDX content detected — could not extract snippet]'];
        }

        $found[] = [
            'id'    => $id,
            'title' => $row['post_title'],
            'url'   => get_permalink($id),
            'links' => $links,
        ];
    }

    wp_send_json_success($found);
});

// ── AJAX: Scan posts & all CPTs for IDX content (DB) ──────────────────────────
add_action('wp_ajax_idx_scan_post_links', function () {
    check_ajax_referer('idx_scanner_nonce', 'nonce');
    global $wpdb;

    $search_domain = idx_scanner_get_search_domain();
    $domain_clause = $search_domain
        ? ' OR post_content LIKE ' . $wpdb->prepare('%s', '%' . $wpdb->esc_like($search_domain) . '%')
        : '';

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- domain_clause is built via prepare()
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_parent, post_content
         FROM {$wpdb->posts}
         WHERE post_type NOT IN (
                 'page','attachment','revision','nav_menu_item','custom_css',
                 'customize_changeset','oembed_cache','user_request','wp_block',
                 'wp_template','wp_template_part','wp_global_styles',
                 'wp_navigation','wp_font_face','wp_font_family'
               )
           AND post_status IN ('publish','inherit')
           AND (  post_content LIKE '%idxbroker%'
               OR post_content LIKE '%idxre.com%'
               OR post_content LIKE '%mlsfinder%'
               OR post_content LIKE '%/idx/%'
               OR post_content LIKE '%[IDX%'
               OR post_content LIKE '%[idx%'
               OR post_content LIKE '%ihf%'
               OR post_content LIKE '%[impress%'
               OR post_content LIKE '%idx-broker-platinum%'
               {$domain_clause} )",
        ARRAY_A
    );

    $found = [];
    foreach ($rows as $row) {
        $content = $row['post_content'];
        $links   = [];

        // Known IDX platform domain URLs
        if (preg_match_all('#https?://[^\s"\'<>\\\\]*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^\s"\'<>\\\\]*#i', $content, $m))
            foreach ($m[0] as $u) $links[] = $u;

        // Site's own custom IDX search subdomain (e.g. search.collegestationhomes.com)
        if ($search_domain) {
            $pat = '#https?://[^\s"\'<>\\\\]*' . preg_quote($search_domain, '#') . '[^\s"\'<>\\\\]*#i';
            if (preg_match_all($pat, $content, $m))
                foreach ($m[0] as $u) $links[] = $u;
        }

        // Internal /idx/ path links
        if (preg_match_all('#href=["\']([^"\']*?/idx/[^"\']*)["\']#', $content, $m))
            foreach ($m[1] as $u) $links[] = $u;

        // IDX / IMPress shortcodes
        if (preg_match_all('/\[(IDX|idx|ihf|impress)[^\]]*\]/i', $content, $m))
            foreach ($m[0] as $u) $links[] = $u;

        // Gutenberg IDX block names
        if (preg_match_all('#<!-- wp:(idx-broker-platinum/[a-z-]+|impress-[a-z-]+-block)#', $content, $m))
            foreach ($m[1] as $u) $links[] = $u;

        // Gutenberg block widget IDs {"id":"909-42343"}
        if (preg_match_all('/"id":"(\d+)-(\d+)"/', $content, $m))
            foreach ($m[2] as $xid) $links[] = 'IDX Widget ' . $xid;

        $links = array_values(array_unique($links));
        if (empty($links)) continue;

        $parent_info = null;
        if (!empty($row['post_parent'])) {
            $parent = get_post((int) $row['post_parent']);
            if ($parent) $parent_info = [
                'id'    => $parent->ID,
                'title' => $parent->post_title,
                'url'   => get_permalink($parent->ID),
            ];
        }

        $found[] = [
            'id'     => (int) $row['ID'],
            'title'  => $row['post_title'],
            'type'   => $row['post_type'],
            'url'    => get_permalink((int) $row['ID']),
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
    global $wpdb, $wp_registered_sidebars, $wp_widget_factory;

    // Terms that flag IDX content inside text / HTML / block widgets
    $idx_terms = [
        'idxbroker', 'idx-broker', 'idxre.com', 'mlsfinder.com',
        '[IDX', '[idx', 'IDX-', 'data-idx', '/idx/',
        'impress/', 'wp:impress', '[impress-', 'ihf-idx', '[ihf-',
    ];

    // ── Collect IDX / IMPress widget id_bases from registered widget classes ──
    $idx_id_base_map = []; // id_base => class_name
    if (!empty($wp_widget_factory->widgets)) {
        foreach ($wp_widget_factory->widgets as $cls => $obj) {
            $base = $obj->id_base ?? '';
            if (
                stripos($cls,  'idx')     !== false ||
                stripos($cls,  'impress') !== false ||
                stripos($cls,  'broker')  !== false ||
                stripos($base, 'idx')     !== false ||
                stripos($base, 'impress') !== false
            ) {
                $idx_id_base_map[$base] = $cls;
            }
        }
    }

    $sidebars_widgets = get_option('sidebars_widgets', []);

    // Full reverse map: widget_id => sidebar_id (ALL sidebars, including inactive)
    $widget_sidebar_map = [];
    foreach ((array) $sidebars_widgets as $sid => $wids) {
        if (!is_array($wids)) continue;
        foreach ($wids as $wid) $widget_sidebar_map[$wid] = $sid;
    }

    $sidebar_names = [];
    foreach ((array) ($wp_registered_sidebars ?? []) as $id => $info) {
        $sidebar_names[$id] = $info['name'] ?? $id;
    }

    $idx_widgets     = [];
    $all_widget_data = [];

    $widget_opt_cache = [];
    $get_widget_opt   = function ($id_base) use (&$widget_opt_cache) {
        if (!array_key_exists($id_base, $widget_opt_cache)) {
            $raw = get_option('widget_' . $id_base);
            $widget_opt_cache[$id_base] = is_array($raw) ? $raw : [];
        }
        return $widget_opt_cache[$id_base];
    };

    $add_idx_widget = function ($wid, $sid, $id_base, $title, $matched, $flat = '') use (
        &$idx_widgets, $sidebar_names
    ) {
        $links = [];
        if ($flat) {
            if (preg_match_all('/href=["\']([^"\']*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^"\']*)["\']/', $flat, $lm))
                foreach ($lm[1] as $u) $links[] = $u;
            if (preg_match_all('/href=["\']([^"\']*\/idx\/[^"\']*)["\']/', $flat, $lm))
                foreach ($lm[1] as $u) $links[] = $u;
            if (preg_match_all('/https?:\/\/[^\s"\'<>]*(?:idxbroker\.com|idxre\.com|mlsfinder\.com)[^\s"\'<>]*/i', $flat, $lm))
                foreach ($lm[0] as $u) $links[] = $u;
        }
        $idx_widgets[] = [
            'widget_key'   => $wid,
            'type'         => $id_base,
            'sidebar_id'   => $sid,
            'sidebar_name' => $sidebar_names[$sid] ?? $sid,
            'title'        => $title,
            'matched'      => $matched,
            'links'        => array_values(array_unique($links)),
            'active'       => ($sid !== 'wp_inactive_widgets' && strpos($sid, 'orphaned_widgets') !== 0),
        ];
    };

    // ── PASS 1: Iterate ALL sidebar buckets — active, inactive, and orphaned ─
    // We previously skipped wp_inactive_widgets; if IDX widgets were deactivated
    // or the theme changed, they'd be there and silently missed.
    foreach ((array) $sidebars_widgets as $sid => $wids) {
        if (!is_array($wids)) continue;
        $is_active_sid = ($sid !== 'wp_inactive_widgets' && strpos($sid, 'orphaned_widgets') !== 0);

        foreach ($wids as $wid) {
            if (!preg_match('/^(.+)-(\d+)$/', $wid, $wm)) {
                if ($is_active_sid) $all_widget_data[$wid] = ['type' => $wid, 'title' => $wid];
                continue;
            }
            $id_base     = $wm[1];
            $instance_id = (int) $wm[2];

            $is_idx_type = isset($idx_id_base_map[$id_base])
                        || stripos($id_base, 'idx')     !== false
                        || stripos($id_base, 'impress') !== false;

            $opt  = $get_widget_opt($id_base);
            $inst = (isset($opt[$instance_id]) && is_array($opt[$instance_id])) ? $opt[$instance_id] : null;

            $title = ($inst['title'] ?? '') ?: (isset($idx_id_base_map[$id_base]) ? $idx_id_base_map[$id_base] : $id_base);
            if ($is_active_sid) $all_widget_data[$wid] = ['type' => $id_base, 'title' => $title];

            if ($inst === null) {
                if ($is_idx_type) $add_idx_widget($wid, $sid, $id_base, $title, ['widget-type:' . $id_base]);
                continue;
            }

            $parts = [];
            array_walk_recursive($inst, function ($v) use (&$parts) { if (is_string($v)) $parts[] = $v; });
            $flat = implode(' ', $parts);

            $matched = [];
            foreach ($idx_terms as $term) {
                if (stripos($flat, $term) !== false) $matched[] = $term;
            }
            if ($is_idx_type) $matched[] = 'widget-type:' . $id_base;

            if (!empty($matched)) $add_idx_widget($wid, $sid, $id_base, $title, $matched, $flat);
        }
    }

    // ── PASS 2: DB scan for widget_* options whose name contains idx/impress/broker ─
    // Catches any IDX widget type that is stored in the DB but somehow absent from
    // sidebars_widgets (e.g. a plugin that manages its own widget storage).
    $seen_wkeys = array_flip(array_column($idx_widgets, 'widget_key'));
    $db_idx_rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'widget\_%'
           AND ( option_name LIKE '%idx%'
              OR option_name LIKE '%impress%'
              OR option_name LIKE '%broker%' )",
        ARRAY_A
    );
    foreach ($db_idx_rows as $row) {
        $data = maybe_unserialize($row['option_value']);
        if (!is_array($data)) continue;
        $type_slug = substr($row['option_name'], 7); // strip 'widget_'
        foreach ($data as $instance_id => $inst) {
            if ($instance_id === '_multiwidget' || !is_array($inst)) continue;
            $wkey = $type_slug . '-' . $instance_id;
            if (isset($seen_wkeys[$wkey])) continue; // already found in pass 1
            $sid   = $widget_sidebar_map[$wkey] ?? 'unassigned';
            $title = ($inst['title'] ?? '') ?: $type_slug;
            $parts = [];
            array_walk_recursive($inst, function ($v) use (&$parts) { if (is_string($v)) $parts[] = $v; });
            $flat    = implode(' ', $parts);
            $matched = ['widget-option:' . $row['option_name']];
            foreach ($idx_terms as $term) {
                if (stripos($flat, $term) !== false) $matched[] = $term;
            }
            $add_idx_widget($wkey, $sid, $type_slug, $title, $matched, $flat);
            $seen_wkeys[$wkey] = true;
        }
    }

    // ── Diagnostic data ──────────────────────────────────────────────────────
    $debug_option_names = array_column($db_idx_rows ?? [], 'option_name');
    $debug_sidebars_map = [];
    foreach ((array) $sidebars_widgets as $sid => $wids) {
        if (is_array($wids)) $debug_sidebars_map[$sid] = $wids;
    }
    $debug_registered = [];
    if (!empty($wp_widget_factory->widgets)) {
        foreach ($wp_widget_factory->widgets as $cls => $obj) {
            $debug_registered[] = $cls . ' → id_base:' . ($obj->id_base ?? '?');
        }
    }

    // ── PASS 3: Post-content + postmeta scan ─────────────────────────────────
    // Widget types like 'idx909_30371' encode the IDX Broker page ID (30371).
    // IMPress embeds these as [ihf_idx page_id="30371"] or [ihf-idx] — NOT as
    // [idx909_30371]. So we must search by the numeric ID and by IMPress shortcode
    // patterns, not just the widget type slug.
    $idx_type_set          = array_unique(array_column($idx_widgets, 'type'));
    $type_content_pages    = []; // type_slug => [ ['title'=>..,'url'=>..], … ]
    $debug_unmatched_pages = []; // pages with IDX content but no specific widget match

    // Only IDX-specific slugs are safe to search in post_content / meta_value.
    // Generic slugs like 'text' match almost every page and cause false positives.
    $idx_specific_type_set = array_values(array_filter(
        $idx_type_set,
        fn($s) => (bool) preg_match('/idx|impress|ihf|broker|forza|omnibar|mlssearch/i', $s)
    ));

    // Map numeric IDX page IDs → widget type slug
    // e.g. 'idx909_30371' → '30371' → 'idx909_30371'
    $idx_page_id_map = []; // '30371' => 'idx909_30371'
    foreach ($idx_type_set as $slug) {
        if (preg_match('/^idx\d+_(\d+)$/', $slug, $m)) {
            $idx_page_id_map[$m[1]] = $slug;
        }
    }

    // ── Read IDX Broker account info early (needed for URL scan + synthetic types) ─
    $ifz_info_early = get_option('idxforza-info', []);
    // imFORZA stores this as a JSON string, not a serialized PHP array
    if (is_string($ifz_info_early)) {
        $decoded = json_decode($ifz_info_early, true);
        if (is_array($decoded)) $ifz_info_early = $decoded;
    }
    $idx_account_id    = is_array($ifz_info_early) ? ($ifz_info_early['account_id'] ?? '909') : '909';
    $idx_search_domain = is_array($ifz_info_early) ? ($ifz_info_early['domain']     ?? '')    : '';

    // ── Extend $idx_page_id_map from the DB ──────────────────────────────────
    // The map above only covers widget types found in sidebars_widgets.
    // IMPress registers a widget_idx{account}_{page_id} option for EVERY IDX
    // Broker page, regardless of whether it's been placed in a sidebar.
    // Scan wp_options so every IDX page ID is checked in all downstream scans.
    $db_idx_opts = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE 'widget\_idx%'
           AND option_name NOT LIKE '\\_transient%'
         LIMIT 200"
    );
    foreach ($db_idx_opts as $opt_name) {
        $slug = substr($opt_name, 7); // strip 'widget_'
        if (!preg_match('/^idx\d+_(\d+)$/', $slug, $m)) continue;
        $pid = $m[1];
        if (!isset($idx_page_id_map[$pid])) {
            $idx_page_id_map[$pid] = $slug;
        }
        if (!in_array($slug, $idx_specific_type_set, true)) {
            $idx_specific_type_set[] = $slug;
        }
    }

    // Build a combined search: widget type slugs + IMPress/IHF patterns + numeric IDs
    $sc_cond = []; $sc_vals = [];

    // 1. Widget type slug in post_content (IDX-specific slugs only — generic ones
    //    like 'text' match almost every page so are excluded here)
    foreach ($idx_specific_type_set as $slug) {
        $sc_cond[] = "post_content LIKE %s"; $sc_vals[] = '%[' . $wpdb->esc_like($slug) . '%';
        $sc_cond[] = "post_content LIKE %s"; $sc_vals[] = '%"id":"' . $wpdb->esc_like($slug) . '%';
        $sc_cond[] = "post_content LIKE %s"; $sc_vals[] = '%widget/' . $wpdb->esc_like($slug) . '%';
    }

    // 2. IMPress / IHF shortcode patterns (the actual shortcodes IMPress registers)
    $impress_patterns = ['[ihf_idx', '[ihf-idx', '[ihf ', '[IMPress', '[impress_', 'ihf_idx'];
    foreach ($impress_patterns as $pat) {
        $sc_cond[] = "post_content LIKE %s"; $sc_vals[] = '%' . $wpdb->esc_like($pat) . '%';
    }

    // 3. Numeric IDX page IDs embedded in shortcode attributes, e.g. page_id="30371"
    foreach (array_keys($idx_page_id_map) as $pid) {
        $sc_cond[] = "post_content LIKE %s"; $sc_vals[] = '%' . $wpdb->esc_like($pid) . '%';
    }

    if (!empty($sc_cond)) {
        $sc_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('page','post')
                   AND (" . implode(' OR ', $sc_cond) . ")",
                $sc_vals
            ),
            ARRAY_A
        );

        foreach ($sc_rows as $row) {
            $url     = get_permalink($row['ID']);
            $pg      = ['title' => $row['post_title'], 'url' => $url];
            $content = $row['post_content'];
            $matched = false;

            // Precise: match by numeric IDX page ID in content
            foreach ($idx_page_id_map as $pid => $widget_slug) {
                if (strpos($content, $pid) !== false) {
                    $type_content_pages[$widget_slug][] = $pg;
                    $matched = true;
                }
            }

            // Precise: match by widget type slug (IDX-specific only)
            foreach ($idx_specific_type_set as $slug) {
                if (stripos($content, '[' . $slug)      !== false ||
                    stripos($content, '"id":"' . $slug)  !== false ||
                    stripos($content, 'widget/' . $slug) !== false) {
                    $type_content_pages[$slug][] = $pg;
                    $matched = true;
                }
            }

            // Broad: page has IMPress shortcode but we can't tell which specific widget.
            // Don't spray to all types — record for debug only.
            if (!$matched) {
                foreach ($impress_patterns as $pat) {
                    if (stripos($content, $pat) !== false) {
                        $debug_unmatched_pages[] = ['title' => $row['post_title'], 'url' => $url, 'reason' => $pat];
                        break;
                    }
                }
            }
        }
    }

    // ── Postmeta scan ─────────────────────────────────────────────────────────
    // IMPress sets meta like '_impress_page_id', '_ihf_page_id', '_idx_page_id', etc.
    // Also check for page template assignments from IMPress.
    $pm_cond = []; $pm_vals = [];

    // Meta keys containing IDX page IDs (IMPress assigns these to wrapper pages)
    foreach (array_keys($idx_page_id_map) as $pid) {
        $pm_cond[] = "pm.meta_value = %s";  $pm_vals[] = $pid;
        $pm_cond[] = "pm.meta_key LIKE %s"; $pm_vals[] = '%' . $wpdb->esc_like($pid) . '%';
    }
    // Generic IMPress/IHF meta key patterns
    $pm_cond[] = "pm.meta_key LIKE %s"; $pm_vals[] = '%impress%';
    $pm_cond[] = "pm.meta_key LIKE %s"; $pm_vals[] = '%_ihf_%';
    $pm_cond[] = "pm.meta_key LIKE %s"; $pm_vals[] = '%idx_page%';
    // IMPress page template
    $pm_cond[] = "(pm.meta_key = '_wp_page_template' AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s))";
    $pm_vals[] = '%impress%'; $pm_vals[] = '%ihf%';

    $pm_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, pm.meta_key, pm.meta_value
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
               AND (" . implode(' OR ', $pm_cond) . ")
             LIMIT 200",
            $pm_vals
        ),
        ARRAY_A
    );

    $pm_pages_by_id   = []; // WP post ID => page info (dedup)
    $pm_widget_hits   = []; // widget_slug => [page info] from postmeta
    foreach ($pm_rows as $row) {
        $url = get_permalink($row['ID']);
        $pg  = ['title' => $row['post_title'], 'url' => $url];
        $pm_pages_by_id[$row['ID']] = $pg;

        // Try to match meta_value to a specific IDX page ID
        foreach ($idx_page_id_map as $pid => $widget_slug) {
            if ($row['meta_value'] === $pid || strpos($row['meta_value'], $pid) !== false) {
                $pm_widget_hits[$widget_slug][] = $pg;
            }
        }
    }

    // Merge postmeta results into type_content_pages
    foreach ($pm_widget_hits as $slug => $pgs) {
        foreach ($pgs as $pg) $type_content_pages[$slug][] = $pg;
    }
    // Unmatched postmeta pages → debug only, not broadcast to all widget types
    if (!empty($pm_pages_by_id) && empty($pm_widget_hits)) {
        foreach ($pm_pages_by_id as $pg) {
            $debug_unmatched_pages[] = $pg + ['reason' => 'postmeta (no specific widget match)'];
        }
    }

    // Page-builder scan (Elementor, Divi, Oxygen)
    // Fetch meta_value so we can match only the specific widget types present.
    if (!empty($idx_type_set)) {
        $pb_cond = []; $pb_vals = [];
        foreach ($idx_specific_type_set as $slug) {
            $pb_cond[] = "pm.meta_value LIKE %s"; $pb_vals[] = '%' . $wpdb->esc_like($slug) . '%';
        }
        foreach (array_keys($idx_page_id_map) as $pid) {
            $pb_cond[] = "pm.meta_value LIKE %s"; $pb_vals[] = '%' . $wpdb->esc_like($pid) . '%';
        }
        $pb_rows_raw = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE pm.meta_key IN ('_elementor_data','_ct_builder_shortcodes','_et_pb_use_builder')
                   AND p.post_status = 'publish' AND p.post_type IN ('page','post')
                   AND (" . implode(' OR ', $pb_cond) . ")",
                $pb_vals
            ),
            ARRAY_A
        );
        // Group meta_value by page ID so one page → one content blob
        $pb_by_page = [];
        foreach ($pb_rows_raw as $r) {
            $pb_by_page[$r['ID']]['title']    = $r['post_title'];
            $pb_by_page[$r['ID']]['content'] .= ' ' . $r['meta_value'];
        }
        foreach ($pb_by_page as $pid_wp => $data) {
            $pg      = ['title' => $data['title'] . ' (page builder)', 'url' => get_permalink($pid_wp)];
            $content = $data['content'];
            $hit     = false;
            foreach ($idx_page_id_map as $pid => $widget_slug) {
                if (strpos($content, $pid) !== false) {
                    $type_content_pages[$widget_slug][] = $pg; $hit = true;
                }
            }
            foreach ($idx_specific_type_set as $slug) {
                if (stripos($content, $slug) !== false) {
                    $type_content_pages[$slug][] = $pg; $hit = true;
                }
            }
            // Still no match → debug bucket, not broadcast
            if (!$hit) {
                $debug_unmatched_pages[] = $pg + ['reason' => 'page builder (no specific slug found)'];
            }
        }
    }

    // ── Dynamic IDX slug scan (catch-all for accordion / Gutenberg blocks) ───
    // All scans above rely on a pre-built list of known IDX page IDs.  If the
    // accordion block stores an IDX widget whose page ID is NOT in wp_options
    // (e.g. a Gutenberg Legacy Widget block referencing idx909_34615 that was
    // never placed in a sidebar), all previous scans miss it.
    //
    // This scan uses REGEXP to find ANY idx{account}_{page_id} slug inside
    // post_content (Gutenberg blocks) and _elementor_data (Elementor panels)
    // without requiring a pre-known list.  Results are merged normally and any
    // newly-discovered slugs are added to $idx_page_id_map for completeness.
    $dyn_pages = []; // wp_id => ['title'=>..., 'text'=>...]

    // 1. post_content — Gutenberg blocks, Classic editor, raw embeds
    foreach ($wpdb->get_results(
        "SELECT ID, post_title, post_content AS txt FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type IN ('page','post')
           AND post_content REGEXP 'idx[0-9]+_[0-9]{4,}'
         LIMIT 300",
        ARRAY_A
    ) as $r) {
        $dyn_pages[$r['ID']] = ['title' => $r['post_title'], 'text' => $r['txt']];
    }

    // 2. _elementor_data — Elementor panels, accordion widgets, HTML blocks
    foreach ($wpdb->get_results(
        "SELECT p.ID, p.post_title, pm.meta_value AS txt
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
         WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
           AND pm.meta_value REGEXP 'idx[0-9]+_[0-9]{4,}'
         LIMIT 300",
        ARRAY_A
    ) as $r) {
        $id = $r['ID'];
        if (!isset($dyn_pages[$id])) {
            $dyn_pages[$id] = ['title' => $r['post_title'], 'text' => ''];
        }
        $dyn_pages[$id]['text'] .= ' ' . $r['txt'];
    }

    foreach ($dyn_pages as $wp_id => $data) {
        $pg = ['title' => $data['title'], 'url' => get_permalink($wp_id)];
        preg_match_all('/\b(idx\d+_(\d{4,}))\b/', $data['text'], $ms);
        $seen_slugs = [];
        foreach ($ms[1] as $i => $full_slug) {
            if (isset($seen_slugs[$full_slug])) continue;
            $seen_slugs[$full_slug] = true;
            $pid = $ms[2][$i];
            if (!isset($idx_page_id_map[$pid])) {
                $idx_page_id_map[$pid] = $full_slug;
            }
            if (!in_array($full_slug, $idx_specific_type_set, true)) {
                $idx_specific_type_set[] = $full_slug;
            }
            $type_content_pages[$full_slug][] = $pg;
            // If this page was in the unmatched bucket, promote it
            $debug_unmatched_pages = array_values(array_filter(
                $debug_unmatched_pages,
                fn($p) => $p['url'] !== $pg['url']
            ));
        }
    }

    // Deduplicate per type (run again at the end after all sources are merged)
    $dedup_type_pages = function () use (&$type_content_pages) {
        foreach ($type_content_pages as $slug => $pgs) {
            $seen = []; $deduped = [];
            foreach ($pgs as $pg) {
                $key = $pg['url'] ?: $pg['title'];
                if (!isset($seen[$key])) { $seen[$key] = true; $deduped[] = $pg; }
            }
            $type_content_pages[$slug] = $deduped;
        }
    };
    $dedup_type_pages();

    // ── Broad IDX URL scan ────────────────────────────────────────────────────
    // When IMPress embeds content via iframe / JS (not shortcodes), the page
    // post_content contains idxbroker.com, idxforza.com, etc. URLs.
    $url_cond = []; $url_vals = [];
    foreach (['idxbroker', 'idxforza', 'mlssearch.', 'mlsfinder', 'idxre.com',
              'data-idx', 'idx-broker', 'ihf_idx', 'imforza',
              'customshowcasejs', 'idx-broker-platinum'] as $pat) {
        $url_cond[] = "post_content LIKE %s"; $url_vals[] = '%' . $wpdb->esc_like($pat) . '%';
    }
    // Add the site's own IDX Broker search domain (e.g. search.collegestationhomes.com)
    if ($idx_search_domain) {
        $url_cond[] = "post_content LIKE %s";
        $url_vals[] = '%' . $wpdb->esc_like($idx_search_domain) . '%';
    }
    $url_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ('page','post')
               AND (" . implode(' OR ', $url_cond) . ")",
            $url_vals
        ),
        ARRAY_A
    );
    $unmatched_url_post_ids = [];
    foreach ($url_rows as $row) {
        $pg      = ['title' => $row['post_title'], 'url' => get_permalink($row['ID'])];
        $content = $row['post_content'];
        $hit     = false;
        // Match by literal IDX page ID appearing in content
        foreach ($idx_page_id_map as $pid => $widget_slug) {
            if (strpos($content, $pid) !== false) {
                $type_content_pages[$widget_slug][] = $pg; $hit = true;
            }
        }
        // Match by IDX-specific type slug appearing in content
        foreach ($idx_specific_type_set as $slug) {
            if (stripos($content, $slug) !== false) {
                $type_content_pages[$slug][] = $pg; $hit = true;
            }
        }
        // ── IDX Broker Platinum Gutenberg blocks ─────────────────────────────
        // Block format: <!-- wp:idx-broker-platinum/idx-widgets-block {"id":"909-42343"} /-->
        // The "id" attribute is "{account_id}-{widget_id}".
        preg_match_all('/"id":"(\d+)-(\d+)"/', $content, $gb_m);
        foreach ($gb_m[2] as $i => $xid) {
            $acct  = !empty($gb_m[1][$i]) ? $gb_m[1][$i] : $idx_account_id;
            $synth = 'idx' . $acct . '_' . $xid;
            if (!isset($idx_page_id_map[$xid])) $idx_page_id_map[$xid] = $synth;
            $type_content_pages[$idx_page_id_map[$xid]][] = $pg; $hit = true;
        }
        // impress-showcase-block: {"saved_link_id":"23613"}
        preg_match_all('/"saved_link_id":"(\d+)"/', $content, $sl_m);
        foreach ($sl_m[1] as $slid) {
            $type_content_pages['idx_savedlink_' . $slid][] = $pg; $hit = true;
        }
        // impress-carousel-block (no page ID parameter)
        if (strpos($content, 'impress-carousel-block') !== false) {
            $type_content_pages['idx_impress_carousel'][] = $pg; $hit = true;
        }
        // Old-style JS embed: id="idxwidgetsrc-42229" (e.g. Austin's Colony accordion)
        preg_match_all('/idxwidgetsrc-(\d{4,})/', $content, $ws_m);
        foreach ($ws_m[1] as $xid) {
            $synth = 'idx' . $idx_account_id . '_' . $xid;
            if (!isset($idx_page_id_map[$xid])) $idx_page_id_map[$xid] = $synth;
            $type_content_pages[$idx_page_id_map[$xid]][] = $pg; $hit = true;
        }
        // URL parameter extraction for remaining unmatched pages (widgetid=, pageid=, etc.)
        if (!$hit) {
            preg_match_all(
                '/\b(?:showcase[_-]?id|page[_-]?id|idx[_-]?id|community[_-]?id|featured[_-]?id|widgetid|widget[_-]?id|pageid|idxID|ihf_id)\s*[=:]\s*["\']?(\d{4,})/i',
                $content, $url_pid_m
            );
            foreach ($url_pid_m[1] as $xid) {
                if (!isset($idx_page_id_map[$xid])) {
                    $synth = 'idx' . $idx_account_id . '_' . $xid;
                    $idx_page_id_map[$xid] = $synth;
                }
                $type_content_pages[$idx_page_id_map[$xid]][] = $pg; $hit = true;
            }
        }
        // No specific match → collect ID for follow-up Elementor scan
        if (!$hit) {
            $unmatched_url_post_ids[] = (int) $row['ID'];
            $debug_unmatched_pages[] = $pg + ['reason' => 'IDX URL in content (no specific widget match)'];
        }
    }

    // ── Follow-up: scan _elementor_data for URL-unmatched pages ──────────────
    // URL scan only checks post_content. For Elementor-built pages the IDX
    // widget type slug lives in _elementor_data postmeta, not post_content.
    if (!empty($unmatched_url_post_ids)) {
        $id_ph     = implode(',', array_fill(0, count($unmatched_url_post_ids), '%d'));
        $elem_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, pm.meta_value AS ed
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.ID IN ({$id_ph})
                   AND pm.meta_key = '_elementor_data'
                   AND pm.meta_value != ''",
                $unmatched_url_post_ids
            ),
            ARRAY_A
        );
        foreach ($elem_rows as $er) {
            $pg2  = ['title' => $er['post_title'] . ' (elementor)', 'url' => get_permalink($er['ID'])];
            $ed   = $er['ed'];
            $hit2 = false;
            foreach ($idx_page_id_map as $pid => $widget_slug) {
                if (stripos($ed, $widget_slug) !== false) {
                    $type_content_pages[$widget_slug][] = $pg2; $hit2 = true;
                }
                // Also match bare numeric ID in JSON (e.g. "page_id":"30371")
                if (!$hit2 && strpos($ed, '"' . $pid . '"') !== false) {
                    $type_content_pages[$widget_slug][] = $pg2; $hit2 = true;
                }
            }
            if (!$hit2) {
                // URL param extraction in elementor data
                preg_match_all(
                    '/\b(?:showcase[_-]?id|page[_-]?id|idx[_-]?id|widgetid|widget[_-]?id|pageid|idxID|ihf_id)\s*[=:]\s*["\']?(\d{4,})/i',
                    $ed, $ep_m
                );
                foreach ($ep_m[1] as $xid) {
                    if (!isset($idx_page_id_map[$xid])) {
                        $synth = 'idx' . $idx_account_id . '_' . $xid;
                        $idx_page_id_map[$xid] = $synth;
                    }
                    $type_content_pages[$idx_page_id_map[$xid]][] = $pg2; $hit2 = true;
                }
            }
            if ($hit2) {
                // Remove from unmatched bucket now that we found a match
                $debug_unmatched_pages = array_values(array_filter(
                    $debug_unmatched_pages,
                    fn($p) => $p['url'] !== get_permalink($er['ID'])
                ));
            }
        }
    }

    // Final dedup
    $dedup_type_pages();

    // ── IMPress / imFORZA plugin options scan (for debug) ─────────────────────
    // Reveals how the plugin stores its page-to-IDX mappings so we can
    // improve detection in future versions.
    $plugin_opt_rows = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options}
         WHERE ( option_name LIKE 'impress%' OR option_name LIKE 'ihf_%'
              OR option_name LIKE 'imforza%' OR option_name LIKE 'idxforza%'
              OR option_name LIKE 'idx_broker%' OR option_name LIKE 'idx_options%' )
           AND option_name NOT LIKE 'widget\_%'
           AND option_name NOT LIKE '\\_transient%'
         LIMIT 40",
        ARRAY_A
    );
    $debug_plugin_option_names = array_column($plugin_opt_rows, 'option_name');

    // ── PASS 4: IDX Broker / imFORZA plugin-level page assignments ───────────────
    // The IDX Broker plugin stores the WordPress page ID of its "dynamic wrapper"
    // — the single page through which ALL IDX Broker content is served.
    // Use it as a fallback for any IDX-specific widget types still unmatched.
    // Also read idxforza-general which may have page ID assignments per feature.
    $debug_pass4 = [];

    // ---- IDX Broker dynamic wrapper: show raw stored values ----
    $dw_raw_id   = get_option('idx_broker_dynamic_wrapper_page_id');
    $dw_raw_name = get_option('idx_broker_dynamic_wrapper_page_name');
    $dw_raw_url  = get_option('idx_broker_dynamic_wrapper_page_url');
    $debug_pass4[] = 'idx_broker_dynamic_wrapper_page_id   = ' . var_export($dw_raw_id, true);
    $debug_pass4[] = 'idx_broker_dynamic_wrapper_page_name = ' . var_export($dw_raw_name, true);
    $debug_pass4[] = 'idx_broker_dynamic_wrapper_page_url  = ' . var_export($dw_raw_url, true);

    $dw_pg = null;
    $dw_page_id = (int) $dw_raw_id;
    if ($dw_page_id > 0) {
        $dw_post = get_post($dw_page_id);
        if ($dw_post && $dw_post->post_status === 'publish') {
            $dw_pg = [
                'title' => $dw_post->post_title . ' (IDX wrapper)',
                'url'   => get_permalink($dw_page_id),
            ];
            $debug_pass4[] = '→ resolved wrapper page: "' . $dw_post->post_title . '" status=' . $dw_post->post_status;
        } else {
            $debug_pass4[] = '→ page ID ' . $dw_page_id . ' not found or not published (status: '
                           . ($dw_post ? $dw_post->post_status : 'NULL') . ')';
        }
    }
    // Fallback: look up wrapper page by stored URL slug
    if (!$dw_pg && $dw_raw_url) {
        $dw_by_url = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ID, post_title, post_status FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type = 'page'
                   AND (guid = %s OR post_name = %s) LIMIT 1",
                $dw_raw_url,
                sanitize_title($dw_raw_name ?: '')
            ),
            ARRAY_A
        );
        if ($dw_by_url) {
            $dw_pg = [
                'title' => $dw_by_url['post_title'] . ' (IDX wrapper)',
                'url'   => get_permalink((int)$dw_by_url['ID']),
            ];
            $debug_pass4[] = '→ wrapper found via URL/name lookup: "' . $dw_by_url['post_title'] . '"';
        } else {
            $debug_pass4[] = '→ wrapper not found via URL/name lookup either';
        }
    }
    if ($dw_pg) {
        foreach ($idx_specific_type_set as $slug) {
            if (empty($type_content_pages[$slug])) {
                $type_content_pages[$slug][] = $dw_pg;
            }
        }
    }

    // ---- imFORZA general settings: show raw values + extract page IDs ----
    $ifz_general = get_option('idxforza-general');
    $debug_pass4[] = 'idxforza-general = ' . (is_array($ifz_general) ? json_encode($ifz_general) : var_export($ifz_general, true));
    if (is_array($ifz_general)) {
        foreach ($ifz_general as $key => $val) {
            if (!is_numeric($val) || (int) $val < 2) continue;
            $ifz_post = get_post((int) $val);
            if (!$ifz_post || $ifz_post->post_status !== 'publish') continue;
            $ifz_pg = [
                'title' => $ifz_post->post_title . ' (imFORZA setting)',
                'url'   => get_permalink((int) $val),
            ];
            $debug_pass4[] = 'idxforza-general[' . $key . '] = page "' . $ifz_post->post_title . '" (ID ' . (int)$val . ')';
            foreach ($idx_specific_type_set as $slug) {
                $key_l  = strtolower($key);
                $slug_l = strtolower($slug);
                if (
                    (strpos($key_l, 'search') !== false && strpos($slug_l, 'search') !== false) ||
                    (strpos($key_l, 'featured') !== false && strpos($slug_l, 'featured') !== false) ||
                    (strpos($key_l, 'login') !== false && strpos($slug_l, 'login') !== false) ||
                    (strpos($key_l, 'omnibar') !== false && strpos($slug_l, 'omnibar') !== false)
                ) {
                    $type_content_pages[$slug][] = $ifz_pg;
                }
            }
        }
    }

    // ---- Dump raw idx909_* widget instance data to debug ----
    // Instance settings may contain community names, slugs, or page URLs
    // that we can use to match against WP page titles/slugs.
    $debug_pass4[] = '--- raw idx909_* widget instances ---';
    foreach ($idx_page_id_map as $pid => $slug) {
        $raw = get_option('widget_' . $slug, []);
        unset($raw['_multiwidget']);
        if (!empty($raw)) {
            $debug_pass4[] = $slug . ': ' . json_encode($raw);
            // Try to extract community/title from instance settings and match to a WP page
            foreach ($raw as $num => $inst) {
                if (!is_array($inst)) continue;
                // Collect candidate strings: title, name, community, label, etc.
                $candidates = array_filter(array_map('strval', array_values(array_filter($inst, 'is_string'))));
                foreach ($candidates as $candidate) {
                    $candidate = trim($candidate);
                    if (strlen($candidate) < 3 || strlen($candidate) > 80) continue;
                    $found = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT ID, post_title FROM {$wpdb->posts}
                             WHERE post_status = 'publish' AND post_type = 'page'
                               AND post_title LIKE %s LIMIT 3",
                            '%' . $wpdb->esc_like($candidate) . '%'
                        ),
                        ARRAY_A
                    );
                    foreach ($found as $r) {
                        $type_content_pages[$slug][] = [
                            'title' => $r['post_title'] . ' (widget data match)',
                            'url'   => get_permalink($r['ID']),
                        ];
                    }
                }
            }
        } else {
            $debug_pass4[] = $slug . ': (no instances)';
        }
    }

    // ---- ihf_links_created ----
    $ihf_links = get_option('ihf_links_created');
    $debug_pass4[] = 'ihf_links_created = ' . var_export($ihf_links, true);

    // ---- idxforza-info ----
    $ifz_info = get_option('idxforza-info');
    $debug_pass4[] = 'idxforza-info = ' . json_encode($ifz_info);

    // ---- imforzacntrl options (may hold IDX page ↔ WP page mappings) ----
    foreach (['imforzacntrl_sync_status', 'imforzacntrl_site_details', 'ihf_permissions', 'idx_broker_listings_enabled'] as $opt) {
        $val = get_option($opt);
        $debug_pass4[] = $opt . ' = ' . ( $val !== false
            ? substr(json_encode($val), 0, 800)
            : '(not set)' );
    }

    // ---- PASS 5: raw content snippets for EVERY unmatched page ─────────────
    // Show exactly what IDX-related text is stored in post_content AND
    // _elementor_data so we can see the real embed format.
    $debug_pass4[] = '';
    $debug_pass4[] = '=== PASS 5 — content snippets for unmatched pages ===';
    foreach ($debug_unmatched_pages as $um) {
        $slug    = basename( rtrim( parse_url( $um['url'], PHP_URL_PATH ), '/' ) );
        $um_row  = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('page','post')
                   AND (post_name = %s OR ID = %d)
                 LIMIT 1",
                $slug, 0
            ),
            ARRAY_A
        );
        if ( ! $um_row ) {
            // Fallback: match by title
            $um_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                     WHERE post_status = 'publish' AND post_type IN ('page','post')
                       AND post_title = %s LIMIT 1",
                    $um['title']
                ),
                ARRAY_A
            );
        }
        if ( ! $um_row ) {
            $debug_pass4[] = $um['title'] . ': (page not found in DB by slug/title)';
            continue;
        }

        // post_content — grab first 1200 chars that mention IDX
        $pc = $um_row['post_content'];
        if ( preg_match( '/(.{0,300}(?:ihf|idx|impress|imforza|idxforza|mlssearch|broker).{0,300})/is', $pc, $pcm ) ) {
            $snippet_pc = trim( $pcm[1] );
        } else {
            $snippet_pc = substr( $pc, 0, 400 ) ?: '(empty)';
        }
        $debug_pass4[] = $um['title'] . ' [post_content]: ' . $snippet_pc;

        // _elementor_data — search for IDX-related content in JSON
        $ed = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
            $um_row['ID']
        ) );
        if ( $ed ) {
            if ( preg_match( '/(.{0,200}(?:ihf|idx|impress|imforza|idxforza|mlssearch|broker|widgetid|widgetType).{0,200})/is', $ed, $edm ) ) {
                $snippet_ed = trim( $edm[1] );
            } else {
                $snippet_ed = '(IDX-related text not found in elementor data)';
            }
            $debug_pass4[] = $um['title'] . ' [_elementor_data]: ' . $snippet_ed;
        } else {
            $debug_pass4[] = $um['title'] . ' [_elementor_data]: (no _elementor_data meta found)';
        }
    }

    // Run dedup again after PASS 4 additions
    $dedup_type_pages();

    // ── Synthetic widget entries for IDX Broker Platinum Gutenberg blocks ────
    // idx-broker-platinum/idx-widgets-block, impress-showcase-block,
    // impress-carousel-block, and old-style JS embeds (idxwidgetsrc-NNN) are NOT
    // WordPress widget instances — they are Gutenberg blocks embedded directly in
    // page post_content. Create synthetic $idx_widgets entries so the Widgets tab
    // shows the pages where these embeds live.
    $existing_w_types = array_flip(array_unique(array_column($idx_widgets, 'type')));
    foreach ($type_content_pages as $wp_type => $pages_list) {
        if (isset($existing_w_types[$wp_type]) || empty($pages_list)) continue;
        if (!preg_match('/^(?:idx\d+_\d+|idx_savedlink_\d+|idx_impress_carousel)$/', $wp_type)) continue;
        if ($wp_type === 'idx_impress_carousel') {
            $synth_title   = 'IMPress Carousel';
            $synth_matched = ['impress-carousel-block'];
        } elseif (preg_match('/^idx_savedlink_(\d+)$/', $wp_type, $stm)) {
            $synth_title   = 'IMPress Showcase (saved search ' . $stm[1] . ')';
            $synth_matched = ['impress-showcase-block'];
        } elseif (preg_match('/^idx\d+_(\d+)$/', $wp_type, $stm)) {
            $synth_title   = 'IDX Widget ' . $stm[1];
            $synth_matched = ['gutenberg-block'];
        } else {
            $synth_title   = $wp_type;
            $synth_matched = ['content-embed'];
        }
        $idx_widgets[] = [
            'widget_key'   => $wp_type . '-embed',
            'type'         => $wp_type,
            'sidebar_id'   => 'post-content',
            'sidebar_name' => 'Embedded in Page Content',
            'title'        => $synth_title,
            'matched'      => $synth_matched,
            'links'        => [],
            'active'       => true,
        ];
        $existing_w_types[$wp_type] = true;
    }

    // Add found postmeta keys to debug output
    $debug_pm_keys = array_unique(array_column($pm_rows, 'meta_key'));

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

                // dynamic_sidebar() — string argument: dynamic_sidebar('sidebar-1')
                preg_match_all("/dynamic_sidebar\s*\(\s*['\"]([^'\"]+)['\"]/", $src, $dm);
                foreach ($dm[1] as $sid) $file_sidebars[$rel][] = $sid;

                // dynamic_sidebar() — numeric argument or no argument → first registered sidebar
                if (preg_match('/\bdynamic_sidebar\s*\(\s*(?:\d|\))/', $src)) {
                    $file_sidebars[$rel][] = '__first__';
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

    // Resolve __first__ placeholder to the first registered sidebar ID
    $first_sidebar_id = !empty($sidebar_names) ? array_key_first($sidebar_names) : 'sidebar-1';
    foreach ($file_sidebars as $rel => &$sids) {
        foreach ($sids as &$sid) {
            if ($sid === '__first__') $sid = $first_sidebar_id;
        }
        unset($sid);
    }
    unset($sids);

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
                return $result; // empty → sidebar-level fallback will supply pages
            }
        }
        return []; // unrecognised template part — let the sidebar-level fallback run
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
                // Query published pages as fallback. Try default-template pages first;
                // if the site uses a page builder (all pages have custom templates),
                // fall back to ALL published pages.
                if ($fallback_pages === null) {
                    $default_rows = $wpdb->get_results(
                        "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                         LEFT JOIN {$wpdb->postmeta} pm
                           ON pm.post_id = p.ID AND pm.meta_key = '_wp_page_template'
                         WHERE p.post_status = 'publish' AND p.post_type = 'page'
                           AND (pm.meta_value IS NULL OR pm.meta_value IN ('default',''))
                         ORDER BY p.post_title ASC",
                        ARRAY_A
                    );
                    if ($default_rows) {
                        $fallback_pages = [];
                        foreach ($default_rows as $r) {
                            $fallback_pages[] = ['title' => $r['post_title'], 'url' => get_permalink($r['ID'])];
                        }
                    } else {
                        // Page builder site — every page has a custom template; fetch all pages
                        $all_rows = $wpdb->get_results(
                            "SELECT ID, post_title FROM {$wpdb->posts}
                             WHERE post_status = 'publish' AND post_type = 'page'
                             ORDER BY post_title ASC LIMIT 50",
                            ARRAY_A
                        );
                        $fallback_pages = [];
                        foreach ($all_rows as $r) {
                            $fallback_pages[] = ['title' => $r['post_title'], 'url' => get_permalink($r['ID'])];
                        }
                        // If the site has no pages at all, mark as sitewide
                        if (empty($fallback_pages)) {
                            $fallback_pages = [['title' => 'All pages (sitewide)', 'url' => '']];
                        }
                    }
                }
                foreach ($fallback_pages as $pg) $pages[$pg['url'] ?: $pg['title']] = $pg;
            }
        }

        $sidebars_out[] = [
            'id'      => $sid,
            'name'    => $sidebar_names[$sid] ?? $sid,
            'widgets' => $widgets_here,
            'pages'   => array_values($pages),
        ];
    }

    // Inject pages into every widget entry. Priority:
    //   1. Post-content / page-builder / postmeta scan (specific slug or page ID match)
    //   2. Per-instance title match (widget display title ↔ WP page title)
    //   3. Empty → widget exists in DB but couldn't be located on any page
    // Sidebar-based lookup is intentionally skipped: sidebar bucket contents
    // are often stale/empty and produce incorrect "All pages (sitewide)" results.
    foreach ($idx_widgets as &$iw) {
        $from_content = $type_content_pages[$iw['type']] ?? [];

        if (!empty($from_content)) {
            $iw['pages']        = $from_content;
            // If every matched page came from the IDX wrapper fallback, label it distinctly
            $all_wrapper = !empty($from_content) && array_reduce(
                $from_content,
                fn($c, $p) => $c && (strpos($p['title'] ?? '', '(IDX wrapper)') !== false),
                true
            );
            $iw['pages_source'] = $all_wrapper ? 'wrapper' : 'shortcode';
        } else {
            // Per-instance title match: each widget instance is checked independently
            // so "Login" and "Property Search" widgets never inherit each other's pages.
            $wtitle = trim($iw['title'] ?? '');
            if (strlen($wtitle) >= 4) {
                $t_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ID, post_title FROM {$wpdb->posts}
                         WHERE post_status = 'publish' AND post_type = 'page'
                           AND post_title LIKE %s LIMIT 5",
                        '%' . $wpdb->esc_like($wtitle) . '%'
                    ),
                    ARRAY_A
                );
                if (!empty($t_rows)) {
                    $iw['pages'] = array_map(fn($r) => [
                        'title' => $r['post_title'] . ' (title match)',
                        'url'   => get_permalink($r['ID']),
                    ], $t_rows);
                    $iw['pages_source'] = 'title-match';
                } else {
                    $iw['pages']        = [];
                    $iw['pages_source'] = 'none';
                }
            } else {
                $iw['pages']        = [];
                $iw['pages_source'] = 'none';
            }
        }
    }
    unset($iw);

    wp_send_json_success([
        'idx_widgets' => $idx_widgets,
        'sidebars'    => $sidebars_out,
        'debug'       => [
            'idx_option_names'    => $debug_option_names,
            'idx_id_base_map'     => $idx_id_base_map,
            'sidebars_map'        => $debug_sidebars_map,
            'registered_widgets'  => $debug_registered,
            'idx_page_id_map'       => $idx_page_id_map,
            'postmeta_keys_found'   => $debug_pm_keys ?? [],
            'plugin_option_names'   => $debug_plugin_option_names ?? [],
            'pass4'                 => $debug_pass4 ?? [],
            'unmatched_pages'       => $debug_unmatched_pages ?? [],
        ],
    ]);
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
        <h1>AiDX Scanner <span style="font-size:13px;color:#999;font-weight:normal;">v<?php echo esc_html( get_plugin_data( __FILE__ )['Version'] ?? '?' ); ?></span></h1>

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
            <p>Scans the DOM of the current admin page for IDX Broker elements. Use the bookmarklet to scan any front-end page.</p>
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
            <p>Searches the database for published pages whose content contains IDX Broker links, shortcodes, or blocks — instant, no HTTP requests.</p>
            <button id="pages-start-btn" class="button button-primary">Scan Pages</button>
            <button id="pages-export-btn" class="button" style="margin-left:8px;" disabled>Export CSV</button>
            <div id="pages-results" style="margin-top:16px;"></div>
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

    // ── Pages Scanner (DB) ──────────────────────────────────────────────────────
    let pagesResults = [];

    document.getElementById('pages-start-btn').addEventListener('click', async function () {
        this.disabled = true;
        pagesResults  = [];
        const div = document.getElementById('pages-results');
        div.innerHTML = '<em>Scanning database…</em>';
        document.getElementById('pages-export-btn').disabled = true;

        const res = await ajax('idx_scan_pages_db');
        this.disabled = false;

        if (!res.success) { div.innerHTML = '<p style="color:#d63638">Error: ' + h(res.data) + '</p>'; return; }

        if (!res.data.length) {
            div.innerHTML = '<p style="color:#00a32a;font-weight:bold;">No IDX content found in any published pages.</p>';
            return;
        }

        let html = '<p><strong>' + res.data.length + '</strong> page(s) contain IDX content.</p>' +
            '<table class="widefat striped"><thead><tr>' +
            '<th>Page</th><th>IDX Content Found</th>' +
            '</tr></thead><tbody>';

        res.data.forEach(p => {
            const linksHtml = p.links.map(l => '<div style="font-size:11px;font-family:monospace;word-break:break-all;">' + h(l) + '</div>').join('');
            p.links.forEach(l => pagesResults.push({ page: p.title, page_url: p.url, element: l }));
            html +=
                '<tr>' +
                '<td><a href="' + h(p.url) + '" target="_blank">' + h(p.title) + '</a></td>' +
                '<td>' + linksHtml + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        div.innerHTML = html;
        document.getElementById('pages-export-btn').disabled = false;
    });

    document.getElementById('pages-export-btn').addEventListener('click', function () {
        exportCSV(
            pagesResults.map(r => [r.page, r.page_url, r.element]),
            ['Page', 'Page URL', 'IDX Element'],
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

        // Build one row per page+widget combination
        const rows = [];
        idxWidgets.forEach(w => {
            const pages    = w.pages && w.pages.length ? w.pages : [];
            const source   = w.pages_source || 'none';
            const position = widgetPosition(w.sidebar_name || w.sidebar_id, w.sidebar_id);

            if (pages.length) {
                pages.forEach(pg => rows.push({
                    page_title: pg.title, page_url: pg.url || '',
                    widget: w.title, type: w.type, source, position,
                }));
            } else {
                rows.push({ page_title: '', page_url: '', widget: w.title, type: w.type, source: 'none', position });
            }
        });
        rows.sort((a, b) => (a.page_title || '').localeCompare(b.page_title || ''));
        widgetsResults = rows;

        const sourceBadge = s => {
            const map = {
                sidebar:      ['#0073aa', 'sidebar'],
                shortcode:    ['#46b450', 'shortcode / block'],
                wrapper:      ['#9b59b6', 'IDX wrapper page'],
                'title-match':['#e6a817', 'title match'],
                none:         ['#999',    'not found in pages'],
            };
            const [color, label] = map[s] || ['#999', s];
            return '<span style="font-size:10px;background:' + color + ';color:#fff;padding:2px 6px;border-radius:3px;">' + h(label) + '</span>';
        };

        let html = '<table class="widefat striped"><thead><tr>' +
            '<th style="width:35%">Page</th>' +
            '<th style="width:35%">Widget</th>' +
            '<th>Found via</th>' +
            '</tr></thead><tbody>';

        rows.forEach(r => {
            const pageCell = r.page_url
                ? '<a href="' + h(r.page_url) + '" target="_blank"><strong>' + h(r.page_title) + '</strong></a>'
                : r.page_title
                    ? h(r.page_title)
                    : '<em style="color:#aaa;">Not found in page content — check Pages/Shortcodes tab</em>';
            html +=
                '<tr>' +
                '<td>' + pageCell + '</td>' +
                '<td><strong>' + h(r.widget) + '</strong><br><code style="font-size:10px;color:#888;">' + h(r.type) + '</code></td>' +
                '<td>' + sourceBadge(r.source) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';

        // ── Debug panel (collapsed by default) ──────────────────────────────
        const dbg = res.data.debug || {};
        const dbgIdxOpts = dbg.idx_option_names  || [];
        const dbgIdxMap  = dbg.idx_id_base_map   || {};
        const dbgSbMap   = dbg.sidebars_map       || {};
        const dbgReg     = dbg.registered_widgets || [];

        const dbgPmKeys    = dbg.postmeta_keys_found  || [];
        const dbgPageIds   = dbg.idx_page_id_map     || {};
        const dbgPluginOpts= dbg.plugin_option_names || [];

        let dbgHtml = '<details style="margin-top:16px;"><summary style="cursor:pointer;color:#777;font-size:11px;">&#9654; Debug info (expand if widgets are still missing)</summary>' +
            '<div style="margin-top:8px;font-size:10px;font-family:monospace;background:#f8f8f8;padding:10px;border:1px solid #ddd;overflow:auto;max-height:280px;">';
        dbgHtml += '<b>IDX page ID map extracted from widget types:</b><br>' +
            (Object.entries(dbgPageIds).length
                ? Object.entries(dbgPageIds).map(([id,slug]) => '  page_id ' + h(id) + ' → widget type "' + h(slug) + '"').join('<br>')
                : '  (none — widget type names did not match idx{account}_{page_id} pattern)');
        dbgHtml += '<br><br><b>Postmeta keys found on IDX/IMPress pages:</b><br>' +
            (dbgPmKeys.length ? dbgPmKeys.map(k => '  ' + h(k)).join('<br>') : '  (none — no postmeta with impress/ihf/idx_page keys found)');
        const dbgPass4     = dbg.pass4 || [];
        const dbgUnmatched = dbg.unmatched_pages || [];
        if (dbgPass4.length) {
            dbgHtml += '<br><br><b>PASS 4 — IDX Broker / imFORZA page assignments:</b><br>' +
                dbgPass4.map(s => '  ' + h(s)).join('<br>');
        }
        if (dbgUnmatched.length) {
            dbgHtml += '<br><br><b>Pages with IDX content (no specific widget match — excluded from results):</b><br>' +
                dbgUnmatched.map(p => '  <a href="' + h(p.url) + '" target="_blank">' + h(p.title) + '</a> — ' + h(p.reason)).join('<br>');
        }
        dbgHtml += '<br><br><b>IMPress/imFORZA plugin options (non-widget):</b><br>' +
            (dbgPluginOpts.length ? dbgPluginOpts.map(n => '  ' + h(n)).join('<br>') : '  (none — impress_*, ihf_*, idxforza_* options not found)');
        dbgHtml += '<br><br><b>widget_* option names matching idx/impress/broker:</b><br>' +
            (dbgIdxOpts.length ? dbgIdxOpts.map(n => '  ' + h(n)).join('<br>') : '  (none)');
        dbgHtml += '<br><br><b>sidebars_widgets buckets:</b><br>';
        Object.entries(dbgSbMap).forEach(([sid, wids]) => {
            dbgHtml += '  [' + h(sid) + '] ' + (Array.isArray(wids) ? wids.map(w => h(w)).join(', ') : '') + '<br>';
        });
        dbgHtml += '</div></details>';

        div.innerHTML = html + dbgHtml;
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
        return fetch(ajaxUrl, { method: 'POST', body })
            .then(r => r.text())
            .then(text => {
                try { return JSON.parse(text); }
                catch (e) {
                    // PHP fatal errors / warnings return HTML, not JSON
                    const msg = text.replace(/<[^>]+>/g, '').trim().substring(0, 300);
                    return { success: false, data: 'Server error: ' + (msg || 'invalid response') };
                }
            });
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
