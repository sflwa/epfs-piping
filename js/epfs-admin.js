jQuery(document).ready(function($) {
    // Add any necessary client-side validation or dynamic behavior here.
    console.log('Email Piping for FluentSupport admin script loaded.');

    // Example: Show a confirmation before saving if a field is empty
    $('#epfs_settings_nonce').closest('form').on('submit', function() {
        if ( ! $('#epfs_pop3_password').val() ) {
            // Note: In real-world, we'd use a custom modal, but for a dev tool, a log is fine.
            console.warn("The POP3 password field is empty. If you are re-entering it, this is fine; otherwise, you'll need to re-enter it.");
        }
    });
});
