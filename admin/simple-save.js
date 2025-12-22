jQuery(document).ready(function($) {
    // Override the save button with a simple direct save
    $('#miniload-save-button').off('click').on('click', function(e) {
        e.preventDefault();

        var settings = {};
        var tab = '<?php echo isset($_GET["tab"]) ? $_GET["tab"] : "settings"; ?>';

        // Collect all form inputs
        $('input[type="checkbox"]').each(function() {
            settings[$(this).attr('name')] = $(this).is(':checked') ? '1' : '0';
        });

        $('input[type="text"], input[type="number"], select').each(function() {
            if ($(this).attr('name')) {
                settings[$(this).attr('name')] = $(this).val();
            }
        });

        // Direct save via AJAX
        $.post(ajaxurl, {
            action: 'miniload_direct_save',
            settings: settings,
            tab: tab
        }, function(response) {
            alert('Settings saved! Please refresh the page.');
            location.reload();
        });
    });
});