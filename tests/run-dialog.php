<?php
// Separate process: the non-AJAX path ends in gm_otp_start_dialog_flow() which
// prints the bounce page and exit()s. We capture that output to prove a normal
// (non-AJAX) login is redirected to the dedicated dialog page.
require __DIR__ . '/bootstrap.php';
reset_state();
$GLOBALS['t_ajax'] = false; // normal POST login
$GLOBALS['t_auth_result'] = new WP_User( 9, 'carol@example.com', 'carol' );
// valid creds, no captcha error
gm_otp_maybe_require_otp( new WP_User( 9, 'carol@example.com', 'carol' ), 'carol', 'pw' );
// (never returns — start_dialog_flow exits after printing the bounce)
