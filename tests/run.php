<?php
require __DIR__ . '/bootstrap.php';

echo "=== Multisite vs single-site option routing ===\n";
$GLOBALS['t_multisite'] = true;
$GLOBALS['t_site'][ GM_OTP_OPTION ] = 'FROM_SITE';
$GLOBALS['t_opts'][ GM_OTP_OPTION ] = 'FROM_LOCAL';
check( 'multisite -> get_site_option', gm_otp_option( GM_OTP_OPTION ), 'FROM_SITE' );
$GLOBALS['t_multisite'] = false;
check( 'single-site -> get_option', gm_otp_option( GM_OTP_OPTION ), 'FROM_LOCAL' );

echo "\n=== Wordfence captcha bypass ===\n";
// Valid creds behind a wfls_captcha_verify rejection -> should bypass and demand OTP.
reset_state();
$GLOBALS['t_auth_result'] = new WP_User( 5, 'alice@example.com', 'alice' );
$r = gm_otp_maybe_require_otp( new WP_Error( 'wfls_captcha_verify', 'captcha' ), 'alice', 'pw' );
check( 'captcha + VALID creds -> OTP demanded', code_of( $r ), 'WP_Error:gm_otp_required' );

// Invalid creds behind the same rejection -> leave the rejection in place.
reset_state();
$GLOBALS['t_auth_result'] = new WP_Error( 'incorrect_password', 'bad' );
$r = gm_otp_maybe_require_otp( new WP_Error( 'wfls_captcha_verify', 'captcha' ), 'alice', 'wrong' );
check( 'captcha + INVALID creds -> rejection kept', code_of( $r ), 'WP_Error:wfls_captcha_verify' );

echo "\n=== Feature gating ===\n";
// Disabled -> pass the user straight through untouched.
reset_state( 0, 1 );
$r = gm_otp_maybe_require_otp( new WP_User( 5, 'a@b.com', 'a' ), 'a', 'pw' );
check( 'disabled -> passthrough', code_of( $r ), 'WP_User' );

// Enabled but SMTP never confirmed -> passthrough (don't risk lockout).
reset_state( 1, 0 );
$r = gm_otp_maybe_require_otp( new WP_User( 5, 'a@b.com', 'a' ), 'a', 'pw' );
check( 'enabled but SMTP unconfirmed -> passthrough', code_of( $r ), 'WP_User' );

// Exempt user -> passthrough.
reset_state();
$GLOBALS['t_opts'][ GM_OTP_EXEMPT_USERS_OPTION ] = array( 5 );
$r = gm_otp_maybe_require_otp( new WP_User( 5, 'a@b.com', 'a' ), 'a', 'pw' );
check( 'exempt user -> passthrough', code_of( $r ), 'WP_User' );

echo "\n=== AJAX inline flow (Wordfence) ===\n";
// No pending code, AJAX -> demand code inline.
reset_state();
$GLOBALS['t_ajax'] = true;
$r = gm_otp_maybe_require_otp( new WP_User( 7, 'bob@example.com', 'bob' ), 'bob', 'pw' );
check( 'ajax, no pending -> OTP demanded', code_of( $r ), 'WP_Error:gm_otp_required' );

// Pending code + correct code submitted, AJAX -> login proceeds + grace minted.
reset_state();
$GLOBALS['t_ajax'] = true;
$_COOKIE[ GM_OTP_COOKIE ] = 'tok7';
set_transient( 'gm_otp_tok7', array( 'user_id' => 7, 'code' => '123456', 'last_sent' => time() ) );
$_POST['gm_otp_code'] = '123456';
$r = gm_otp_maybe_require_otp( new WP_User( 7, 'bob@example.com', 'bob' ), 'bob', 'pw' );
check( 'ajax, correct code -> login proceeds', code_of( $r ), 'WP_User' );
check( 'ajax, correct code -> grace token minted', isset( $GLOBALS['t_trans']['gm_otp_grace_7'] ) ? 'yes' : 'no', 'yes' );

// Pending code + wrong code, AJAX -> wrong-code error.
reset_state();
$GLOBALS['t_ajax'] = true;
$_COOKIE[ GM_OTP_COOKIE ] = 'tok7';
set_transient( 'gm_otp_tok7', array( 'user_id' => 7, 'code' => '123456', 'last_sent' => time() ) );
$_POST['gm_otp_code'] = '000000';
$r = gm_otp_maybe_require_otp( new WP_User( 7, 'bob@example.com', 'bob' ), 'bob', 'pw' );
check( 'ajax, wrong code -> invalid', code_of( $r ), 'WP_Error:gm_otp_invalid' );

echo "\n=== Grace token (Wordfence phase 2, non-AJAX) ===\n";
// A valid grace token on a non-AJAX request -> passes through WITHOUT bouncing.
reset_state();
$GLOBALS['t_ajax'] = false;
$_COOKIE['gm_otp_grace'] = 'GRACEVAL';
set_transient( 'gm_otp_grace_7', 'GRACEVAL' );
$r = gm_otp_maybe_require_otp( new WP_User( 7, 'bob@example.com', 'bob' ), 'bob', 'pw' );
check( 'non-ajax + valid grace -> passthrough (no bounce)', code_of( $r ), 'WP_User' );

echo "\n---------------------------------\n";
printf( "TOTAL: %d passed, %d failed\n", $GLOBALS['t_pass'], $GLOBALS['t_fail'] );
exit( $GLOBALS['t_fail'] > 0 ? 1 : 0 );
