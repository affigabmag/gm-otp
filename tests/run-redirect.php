<?php
// Separate process: gm_otp_login_redirect() ends in wp_safe_redirect()+exit.
// Prove it honours a third-party login_redirect filter (LoginWP / QL Redirector)
// instead of always going to wp-admin.
require __DIR__ . '/bootstrap.php';

$mode = $argv[1] ?? 'filter';

if ( 'filter' === $mode ) {
	// Simulate LoginWP: role-based redirect to the front page.
	add_filter( 'login_redirect', function ( $to, $requested, $user ) {
		return 'https://example.test/welcome/';
	}, 10, 3 );
	gm_otp_login_redirect( new WP_User( 5, 'a@b.com', 'a' ), '' );
} elseif ( 'requested' === $mode ) {
	// No filter, an explicit redirect_to was captured at login -> honour it.
	gm_otp_login_redirect( new WP_User( 5, 'a@b.com', 'a' ), 'https://example.test/reports/' );
} else {
	// No filter, no request -> core default (wp-admin for a capable user).
	gm_otp_login_redirect( new WP_User( 5, 'a@b.com', 'a' ), '' );
}
