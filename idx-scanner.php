<?php
/**
 * Plugin Name: IDX Element Scanner
 * Description: Scans the current WordPress page for IDX Broker elements and displays them in the admin.
 * Version: 1.0
 * Author: You
 */

if (!defined('ABSPATH')) exit;

// Add admin menu item
add_action('admin_menu', function () {
    add_management_page(
        'IDX Scanner',
        'IDX Scanner',
        'manage_options',
        'idx-scanner',
        'idx_scanner_page'
    );
});

// Admin page output
function idx_scanner_page() {
    ?>
    <div class="wrap">
        <h1>IDX Element Scanner</h1>
        <p>Click the button below to scan the current page for IDX Broker elements.</p>
        <button id="idx-scan-btn" class="button button-primary">Scan Page</button>

        <pre id="idx-results" style="margin-top:20px; background:#f7f7f7; padding:15px; border:1px solid #ddd;"></pre>
    </div>

    <script>
        document.getElementById('idx-scan-btn').addEventListener('click', function () {
            const idxSelectors = [
                '[id^="IDX-"]',
                '[class*="idx-"]',
                '[class*="IDX-"]',
                '[id*="idx"]',
                '[src*="idxbroker"]',
                '[href*="idxbroker"]',
                '[data-idx]',
                '[data-idx-id]',
                '[data-idx-widget]',
                '[data-idx-page]'
            ];

            const found = new Set();

            idxSelectors.forEach(selector => {
                document.querySelectorAll(selector).forEach(el => found.add(el));
            });

            const output = [];
            output.push("Total IDX elements found: " + found.size);
            output.push("----------------------------------------");

            [...found].forEach((el, i) => {
                output.push((i + 1) + ". " + el.outerHTML.substring(0, 200) + "...");
            });

            document.getElementById('idx-results').textContent = output.join("\n");
        });
    </script>
    <?php
}
