/**
 * Scroll Sections Developer — Admin Scripts
 * Handles media uploader and conditional field visibility.
 */

(function ($) {
    'use strict';

    $(function () {
        /* -------------------------------------------------------------- */
        /*  Conditional background-type fields                             */
        /* -------------------------------------------------------------- */
        var $bgType = $('#ssd-bg-type');

        function toggleBgFields() {
            var val = $bgType.val();
            $('.ssd-bg-field').hide();
            $('.ssd-bg-' + val + '-field').show();
        }

        $bgType.on('change', toggleBgFields);
        toggleBgFields(); // run on load

        /* -------------------------------------------------------------- */
        /*  WordPress media uploader for image fields                      */
        /* -------------------------------------------------------------- */
        $(document).on('click', '.ssd-upload-btn', function (e) {
            e.preventDefault();

            var $btn    = $(this);
            var target  = $btn.data('target');
            var $input  = $(target);
            var $preview = $btn.closest('.ssd-bg-field').find('.ssd-image-preview');

            var frame = wp.media({
                title: 'Select Background Image',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.url);
                $preview.html('<img src="' + attachment.url + '" />');
            });

            frame.open();
        });
    });

})(jQuery);
