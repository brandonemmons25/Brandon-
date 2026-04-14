<?php
/**
 * Plugin Name: Mobile CSS Auditor
 * Description: Scans every page of your site at mobile width, inspects all DOM elements and iHF shadow roots, then generates ready-to-paste CSS for WordPress Customizer and iHomeFinder Admin.
 * Version:     2.0.0
 * Author:      Brandon Emmons
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Mobile_CSS_Auditor {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'wp_enqueue_scripts',    [ $this, 'maybe_enqueue_probe' ] );
    }

    /* ── Admin menu ─────────────────────────────────────────────── */
    public function add_menu() {
        add_menu_page(
            'Mobile CSS Auditor',
            'Mobile Auditor',
            'manage_options',
            'mobile-css-auditor',
            [ $this, 'render_page' ],
            'dashicons-smartphone',
            81
        );
    }

    /* ── Admin assets ───────────────────────────────────────────── */
    public function enqueue_admin( $hook ) {
        if ( $hook !== 'toplevel_page_mobile-css-auditor' ) return;

        wp_enqueue_style(
            'mca-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
            [],
            '2.0.0'
        );
        wp_enqueue_script(
            'mca-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.js',
            [ 'jquery' ],
            '2.0.0',
            true
        );
        wp_localize_script( 'mca-admin', 'MCA', [
            'pages'   => $this->get_all_pages(),
            'siteUrl' => get_site_url(),
            'nonce'   => wp_create_nonce( 'mca_probe' ),
        ] );
    }

    /* ── Probe script on frontend ───────────────────────────────── */
    public function maybe_enqueue_probe() {
        if (
            isset( $_GET['mca_probe'] ) &&
            isset( $_GET['mca_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['mca_nonce'] ) ), 'mca_probe' ) &&
            current_user_can( 'manage_options' )
        ) {
            wp_enqueue_script(
                'mca-probe',
                plugin_dir_url( __FILE__ ) . 'assets/probe.js',
                [],
                '2.0.0',
                true   // load in footer so DOM is ready
            );
        }
    }

    /* ── All published pages ────────────────────────────────────── */
    public function get_all_pages() {
        $list  = [];
        $front = (int) get_option( 'page_on_front' );

        // Front page first
        if ( $front ) {
            $list[] = [
                'id'    => $front,
                'title' => 'Home (Front Page)',
                'url'   => home_url( '/' ),
            ];
        } else {
            $list[] = [
                'id'    => 0,
                'title' => 'Home',
                'url'   => home_url( '/' ),
            ];
        }

        // All other published pages
        $pages = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'menu_order' ] );
        foreach ( $pages as $p ) {
            if ( $p->ID === $front ) continue;
            $list[] = [
                'id'    => $p->ID,
                'title' => $p->post_title,
                'url'   => get_permalink( $p->ID ),
            ];
        }

        return $list;
    }

    /* ── Admin page HTML ────────────────────────────────────────── */
    public function render_page() {
        ?>
        <div class="wrap" id="mca-wrap">
            <h1>
                <span class="dashicons dashicons-smartphone" style="font-size:28px;vertical-align:middle;margin-right:8px;margin-top:-3px"></span>
                Mobile CSS Auditor
            </h1>
            <p class="description">
                Loads each page inside a hidden 375 px iframe so mobile media queries fire,
                then probes every element — including iHF shadow DOM — and generates
                copy-ready CSS for <strong>Customizer → Additional CSS</strong> and
                <strong>iHomeFinder Admin → Custom CSS</strong>.
            </p>

            <div id="mca-toolbar">
                <button id="mca-btn-scan-all" class="button button-primary">&#9654;&nbsp;Scan All Pages</button>
                <button id="mca-btn-csv"      class="button button-secondary mca-hidden">&#8659;&nbsp;Download CSV</button>
                <button id="mca-btn-view"     class="button mca-hidden">&#128065;&nbsp;View / Copy Data</button>
                <button id="mca-btn-clear"    class="button">&#10007;&nbsp;Clear</button>
                <span   id="mca-progress-text"></span>
                <div    id="mca-progress-bar"><div id="mca-progress-fill"></div></div>
            </div>

            <div id="mca-workflow">
                <strong>Workflow:</strong>
                Scan all pages &rarr; <strong>View / Copy Data</strong> &rarr; paste into Claude chat &rarr; Claude writes the CSS &rarr; paste into
                <em>Customizer &rarr; Additional CSS</em> and <em>Optima Express &rarr; Custom CSS</em>.
            </div>

            <div id="mca-table-wrap"></div>

            <div id="mca-view-wrap" class="mca-hidden">
                <h2>Scan Summary — Copy and paste into Claude</h2>
                <p>This is a compact summary of everything Claude needs. Click <strong>Copy All</strong> then paste into the chat.</p>
                <button id="mca-btn-copy-data" class="button button-primary" style="font-size:15px;padding:8px 24px;">&#128203;&nbsp;Copy All</button>
                <textarea id="mca-data-out" rows="40" readonly spellcheck="false" style="margin-top:12px;width:100%;font-size:11px;font-family:monospace;background:#1a1a2e;color:#e2e2e2;padding:12px;box-sizing:border-box;border-radius:4px;resize:vertical;"></textarea>
            </div>
        </div>

        <!-- Hidden iframe: loads pages at 375 px to trigger mobile media queries -->
        <iframe id="mca-iframe"
                sandbox="allow-same-origin allow-scripts allow-forms"
                title="MCA Scanner"
                aria-hidden="true">
        </iframe>
        <?php
    }
}

new Mobile_CSS_Auditor();
