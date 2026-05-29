/* Kadence Block Scanner — Admin JS */
(function ($) {
	'use strict';

	$(function () {
		var $btn    = $('#kbs-run-scan');
		var $status = $('#kbs-scan-status');
		var $wrap   = $('#kbs-results-wrap');

		$btn.on('click', function () {
			$btn.prop('disabled', true).text('Scanning…');
			$status.text('Running scan — this may take a moment on large sites…');
			$wrap.html('<p style="padding:20px;color:#555;">⏳ Scanning all posts for Kadence block issues…</p>');

			$.post(KBS.ajaxurl, {
				action: 'kbs_run_scan',
				nonce:  KBS.nonce
			})
			.done(function (response) {
				if (response.success) {
					var d = response.data;
					var meta = d.meta || {};
					$status.html(
						'✅ Scan complete — ' +
						(meta.total_posts || 0) + ' posts checked in ' +
						(meta.duration || '?') + 's. ' +
						'<a href="" onclick="location.reload();return false;">Reload to view full report</a>.'
					);
					// Reload so PHP renders the full results table
					setTimeout(function () { location.reload(); }, 800);
				} else {
					$status.text('Error: ' + (response.data || 'Unknown error.'));
					$btn.prop('disabled', false).text('Run Full Scan');
				}
			})
			.fail(function (xhr) {
				$status.text('Request failed (HTTP ' + xhr.status + '). Check server logs.');
				$btn.prop('disabled', false).text('Run Full Scan');
			});
		});
	});
}(jQuery));
