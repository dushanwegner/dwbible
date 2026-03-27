(function($) {
    'use strict';

    function initMediaPicker(buttonId, inputId, mediaType, title, buttonText) {
        var btn = document.getElementById(buttonId);
        if (!btn) return;

        var frame = null;
        btn.addEventListener('click', function(e) {
            e.preventDefault();

            if (!window.wp || !wp.media) {
                alert('Media library not available. Please reload the page.');
                return;
            }

            if (frame) {
                frame.open();
                return;
            }

            var libraryOpts = {};
            if (mediaType === 'font') {
                libraryOpts.type = [
                    'application/octet-stream',
                    'font/ttf',
                    'font/otf',
                    'application/x-font-ttf',
                    'application/x-font-otf',
                    'font/woff',
                    'font/woff2'
                ];
            } else if (mediaType === 'image') {
                libraryOpts.type = 'image';
            }

            frame = wp.media({
                title: title,
                library: libraryOpts,
                button: { text: buttonText },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                if (attachment && attachment.url) {
                    var input = document.getElementById(inputId);
                    if (input) {
                        input.value = attachment.url;
                    }
                }
            });

            frame.open();
        });
    }

    // Initialize all three pickers when DOM is ready
    $(document).ready(function() {
        // Font picker
        initMediaPicker(
            'dwbible_pick_font',
            'dwbible_og_font_url',
            'font',
            'Select a font file',
            'Use this font'
        );

        // Background image picker
        initMediaPicker(
            'dwbible_pick_bg',
            'dwbible_og_background_image_url',
            'image',
            'Select background image',
            'Use this image'
        );

        // Icon picker
        initMediaPicker(
            'dwbible_pick_icon',
            'dwbible_og_icon_url',
            'image',
            'Select icon',
            'Use this image'
        );
    });

})(jQuery);
