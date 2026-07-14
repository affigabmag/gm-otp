<?php
/**
 * GM OTP — login: OTP auth flow, inline field, and dialog page (split out of gm-otp.php for maintainability).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs after core's wp_authenticate_username_password (priority 20).
 * $user is either a WP_User (creds ok) or WP_Error (creds bad).
 *
 * The code field lives directly on the login form (rendered by
 * gm_otp_render_field(), hooked on 'login_form') rather than a separate
 * page/action â€” needed for compatibility with AJAX-based login flows
 * (e.g. Wordfence Login Security posts to admin-ajax.php, which broke the
 * old raw-HTML redirect page entirely). On success we simply return the
 * WP_User: core's own wp_signon() sets the auth cookie, fires 'wp_login',
 * and redirects â€” we don't need to touch any of that ourselves.
 */
add_filter( 'authenticate', 'gm_otp_maybe_require_otp', 30, 3 );
function gm_otp_maybe_require_otp( $user, $username, $password ) {
	gm_otp_log( "authenticate fired for username='{$username}', host=" . gm_otp_input( $_SERVER, 'HTTP_HOST' ) );

	if ( ! gm_otp_option( GM_OTP_OPTION ) ) {
		gm_otp_log( 'skipped: gm_otp_enabled option is off (checked via ' . ( is_multisite() ? 'get_site_option' : 'get_option' ) . ')' );
		return $user; // feature disabled
	}

	// Belt-and-suspenders: the settings page already refuses to save "enabled"
	// without this, but if the option ever got set directly (WP-CLI, direct
	// DB edit), don't lock users out waiting on email that was never verified.
	if ( ! gm_otp_option( GM_OTP_SMTP_CONFIRMED_OPTION ) ) {
		gm_otp_log( 'skipped: gm_otp_enabled is on but email delivery was never confirmed in settings â€” refusing to risk locking users out' );
		return $user;
	}

	// Some security plugins (Wordfence Login Security's own captcha is the
	// known case, error code "wfls_captcha_verify") run their own check
	// *before* this filter and reject the login outright if it fails â€”
	// independent of whether the username/password were actually correct.
	// Since GM OTP is already acting as the second factor here, a second,
	// broken captcha in front of it just locks real users out. Re-verify
	// the credentials directly against core; if they're genuinely valid,
	// proceed with our own OTP flow instead of honoring that rejection.
	if ( is_wp_error( $user ) && 'wfls_captcha_verify' === $user->get_error_code() ) {
		gm_otp_log( "Wordfence captcha rejected username='{$username}' (wfls_captcha_verify) â€” re-checking credentials directly against core, bypassing that check" );
		$real_user = wp_authenticate_username_password( null, $username, $password );
		if ( $real_user instanceof WP_User ) {
			gm_otp_log( "credentials for '{$username}' are genuinely valid â€” proceeding despite the captcha rejection" );
			$user = $real_user;
		} else {
			gm_otp_log( "credentials for '{$username}' are also invalid on their own â€” leaving the original rejection in place" );
		}
	}

	if ( ! ( $user instanceof WP_User ) ) {
		gm_otp_log( 'skipped: $user is not a WP_User, got ' . ( is_wp_error( $user ) ? 'WP_Error: ' . $user->get_error_code() : gettype( $user ) ) );
		return $user; // bad creds this submission, let core's own error show
	}

	if ( gm_otp_is_exempt( $user ) ) {
		gm_otp_log( "skipped: user_id={$user->ID} matches an OTP exemption (role or user)" );
		return $user;
	}

	// Non-interactive logins (XML-RPC, REST/application passwords) can't show a
	// prompt and must never receive HTML output â€” skip OTP entirely there.
	if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		gm_otp_log( "skipped: non-interactive login (XML-RPC/REST) for user_id={$user->ID}" );
		return $user;
	}

	// Two-phase AJAX login handling. Some security plugins (Wordfence Login
	// Security's reCAPTCHA is the known case) authenticate ONCE over
	// admin-ajax.php to pre-validate credentials, then submit the real login
	// form to wp-login.php â€” running this filter TWICE for a single login. If
	// the code was already entered and accepted in that earlier AJAX phase, a
	// single-use grace token lets the follow-up wp-login.php POST through
	// instead of demanding a brand-new code (which would also overwrite the
	// code the user just used, causing a "wrong code" loop). The token is only
	// ever minted right after a genuinely correct code (see below), is bound
	// to a cookie value, and is consumed on first use.
	$grace_cookie = isset( $_COOKIE['gm_otp_grace'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['gm_otp_grace'] ) ) : '';
	if ( $grace_cookie ) {
		$grace_stored = get_transient( 'gm_otp_grace_' . $user->ID );
		if ( $grace_stored && hash_equals( (string) $grace_stored, (string) $grace_cookie ) ) {
			gm_otp_log( "user_id={$user->ID}: consuming OTP grace token â€” already passed OTP in this login's AJAX phase, skipping the second demand" );
			delete_transient( 'gm_otp_grace_' . $user->ID );
			setcookie( 'gm_otp_grace', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
			return $user;
		}
	}

	// Password is genuinely correct on THIS submission â€” now see if there's
	// a pending code (from an earlier submission) for this SAME user. Only
	// ever honored when the cookie's stored user_id matches $user->ID, so a
	// valid password for user A can never consume user B's pending code.
	$token  = isset( $_COOKIE[ GM_OTP_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GM_OTP_COOKIE ] ) ) : '';
	$stored = $token ? get_transient( 'gm_otp_' . $token ) : false;
	if ( $stored && (int) $stored['user_id'] !== (int) $user->ID ) {
		$stored = false; // stale/foreign cookie â€” ignore it, start fresh below
	}

	if ( gm_otp_get_lockout( $user->ID ) ) {
		if ( $token ) {
			gm_otp_clear_pending( $token );
		}
		gm_otp_log( "blocked: user_id={$user->ID} is locked out" );
		return new WP_Error( 'gm_otp_locked', gm_otp_lockout_message( $user->ID ) );
	}

	// How the code is collected depends on the login transport:
	//  - AJAX logins (e.g. Wordfence reCAPTCHA posts to admin-ajax.php) never
	//    re-render wp-login.php, so the code field stays INLINE on the form and
	//    is revealed with JS (handled below).
	//  - Normal POST logins are sent to a dedicated code DIALOG page. That
	//    survives custom login pages (e.g. ql-registration) which would
	//    otherwise hijack the re-rendered login form and drop an inline field.
	if ( ! wp_doing_ajax() ) {
		return gm_otp_start_dialog_flow( $user );
	}

	if ( ! $stored ) {
		return gm_otp_start_new_code( $user );
	}

	if ( ! empty( $_POST['gm_otp_resend'] ) ) {
		return gm_otp_resend_code( $token, $stored, $user );
	}

	$submitted = isset( $_POST['gm_otp_code'] ) ? preg_replace( '/\D/', '', gm_otp_input( $_POST, 'gm_otp_code' ) ) : '';

	if ( '' === $submitted ) {
		// Code field wasn't filled in on this submission â€” just re-show
		// the pending prompt rather than treating it as a wrong guess.
		gm_otp_set_show_cookie();
		$GLOBALS['gm_otp_pending'] = array(
			'masked_email' => gm_otp_mask_email( $user->user_email ),
			'seconds_left' => max( 0, GM_OTP_RESEND_WAIT - ( time() - (int) $stored['last_sent'] ) ),
		);
		return new WP_Error( 'gm_otp_required', __( 'Enter the 6-digit code we emailed you.' ) );
	}

	if ( hash_equals( (string) $stored['code'], (string) $submitted ) ) {
		gm_otp_clear_pending( $token );
		delete_transient( 'gm_otp_attempts_' . $user->ID );

		// If this success happened during an AJAX pre-check (Wordfence
		// reCAPTCHA), the browser is about to POST the real login form to
		// wp-login.php, which re-runs this filter. Mint a single-use grace
		// token so that second phase is let through rather than demanding a
		// fresh code. On a normal (single-phase) login this branch never runs,
		// so there's no window where a later login could skip OTP.
		if ( wp_doing_ajax() ) {
			$grace = wp_generate_password( 32, false );
			set_transient( 'gm_otp_grace_' . $user->ID, $grace, 2 * MINUTE_IN_SECONDS );
			setcookie( 'gm_otp_grace', $grace, time() + 2 * MINUTE_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			gm_otp_log( "user_id={$user->ID}: correct code during AJAX phase â€” minted grace token for the follow-up wp-login.php POST" );
		}

		gm_otp_log( "user_id={$user->ID} entered correct code â€” login proceeding" );
		return $user; // core's wp_signon() sets the auth cookie and redirects from here
	}

	$attempts_key = 'gm_otp_attempts_' . $user->ID;
	$max_attempts = max( 1, (int) gm_otp_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ) );
	$lockout_mins = max( 1, (int) gm_otp_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) );
	$attempts     = (int) get_transient( $attempts_key ) + 1;

	if ( $attempts >= $max_attempts ) {
		delete_transient( $attempts_key );
		set_transient( 'gm_otp_lockout_' . $user->ID, time() + $lockout_mins * MINUTE_IN_SECONDS, $lockout_mins * MINUTE_IN_SECONDS );
		gm_otp_clear_pending( $token );
		gm_otp_log( "user_id={$user->ID} hit max wrong-code attempts â€” locked out for {$lockout_mins}m" );
		return new WP_Error( 'gm_otp_locked', gm_otp_lockout_message( $user->ID ) );
	}

	set_transient( $attempts_key, $attempts, GM_OTP_TTL );
	gm_otp_set_show_cookie();
	$GLOBALS['gm_otp_pending'] = array(
		'masked_email' => gm_otp_mask_email( $user->user_email ),
		'seconds_left' => max( 0, GM_OTP_RESEND_WAIT - ( time() - (int) $stored['last_sent'] ) ),
	);
	return new WP_Error( 'gm_otp_invalid', __( 'Wrong code.' ) );
}

/**
 * First time we see valid creds with no pending code yet: generate one,
 * cookie+transient it, email it, and mark $GLOBALS so gm_otp_render_field()
 * shows the field on THIS same response (setcookie() doesn't populate
 * $_COOKIE until the next request).
 */
function gm_otp_start_new_code( WP_User $user ) {
	$token = wp_generate_password( 32, false );
	$code  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

	set_transient( 'gm_otp_' . $token, array(
		'user_id'   => $user->ID,
		'code'      => $code,
		'last_sent' => time(),
	), GM_OTP_TTL );

	$cookie_set = setcookie( GM_OTP_COOKIE, $token, time() + GM_OTP_TTL, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	gm_otp_log( sprintf(
		'code generated for user_id=%d, setcookie()=%s, COOKIEPATH=%s, COOKIE_DOMAIN=%s, is_ssl()=%s',
		$user->ID,
		$cookie_set ? 'true' : 'FALSE',
		COOKIEPATH,
		COOKIE_DOMAIN ? COOKIE_DOMAIN : '(empty)',
		is_ssl() ? 'true' : 'false'
	) );

	$mail_sent = wp_mail(
		$user->user_email,
		__( 'Your login code' ),
		sprintf( __( 'Your one-time login code is: %s (valid 5 minutes)' ), $code )
	);
	gm_otp_log( 'wp_mail() returned ' . ( $mail_sent ? 'true' : 'FALSE' ) . " for {$user->user_email}" );

	gm_otp_set_show_cookie();
	$GLOBALS['gm_otp_pending'] = array(
		'masked_email' => gm_otp_mask_email( $user->user_email ),
		'seconds_left' => GM_OTP_RESEND_WAIT,
	);

	return new WP_Error( 'gm_otp_required', __( 'Enter the 6-digit code we emailed you.' ) );
}

/**
 * Resend button: respects the cooldown, regenerates the code, re-sends it.
 */
function gm_otp_resend_code( $token, array $stored, WP_User $user ) {
	$elapsed = time() - (int) $stored['last_sent'];

	if ( $elapsed < GM_OTP_RESEND_WAIT ) {
		gm_otp_set_show_cookie();
		$GLOBALS['gm_otp_pending'] = array(
			'masked_email' => gm_otp_mask_email( $user->user_email ),
			'seconds_left' => GM_OTP_RESEND_WAIT - $elapsed,
		);
		return new WP_Error( 'gm_otp_required', __( 'Please wait before requesting another code.' ) );
	}

	$new_code            = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	$stored['code']      = $new_code;
	$stored['last_sent'] = time();
	set_transient( 'gm_otp_' . $token, $stored, GM_OTP_TTL );

	wp_mail(
		$user->user_email,
		__( 'Your login code' ),
		sprintf( __( 'Your one-time login code is: %s (valid 5 minutes)' ), $new_code )
	);
	gm_otp_log( "resent code for user_id={$user->ID}" );

	gm_otp_set_show_cookie();
	$GLOBALS['gm_otp_pending'] = array(
		'masked_email' => gm_otp_mask_email( $user->user_email ),
		'seconds_left' => GM_OTP_RESEND_WAIT,
	);

	return new WP_Error( 'gm_otp_required', __( 'A new code has been sent to your email.' ) );
}

/* -------------------------------------------------------------------------
 * Dedicated code DIALOG page (non-AJAX logins).
 * Used when the login is a normal POST rather than an AJAX submission â€” it
 * bounces the browser to wp-login.php?action=gm_otp, which shows the code
 * dialog and completes the login itself. This is what makes GM OTP work on
 * sites whose login form is a custom page that would otherwise hijack the
 * re-rendered wp-login.php form.
 * ---------------------------------------------------------------------- */

/**
 * Valid creds on a normal POST login: generate a code, email it, and bounce
 * to the dialog page. Stores remember/redirect_to so the dialog can finish
 * the login exactly as core would have.
 */
function gm_otp_start_dialog_flow( WP_User $user ) {
	$token = wp_generate_password( 32, false );
	$code  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

	set_transient( 'gm_otp_' . $token, array(
		'user_id'     => $user->ID,
		'code'        => $code,
		'remember'    => ! empty( $_POST['rememberme'] ),
		'redirect_to' => isset( $_REQUEST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ) : '',
		'last_sent'   => time(),
	), GM_OTP_TTL );

	setcookie( GM_OTP_COOKIE, $token, time() + GM_OTP_TTL, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

	$mail_sent = wp_mail(
		$user->user_email,
		__( 'Your login code' ),
		sprintf( __( 'Your one-time login code is: %s (valid 5 minutes)' ), $code )
	);
	gm_otp_log( 'dialog flow: code sent (wp_mail=' . ( $mail_sent ? 'true' : 'FALSE' ) . ") for user_id={$user->ID}, bouncing to code page" );

	gm_otp_post_bounce( wp_login_url(), array( 'action' => 'gm_otp' ) );
	exit;
}

/**
 * Navigate to $url via an auto-submitting POST form instead of a GET redirect.
 * Some custom-login plugins intercept GET requests to wp-login.php and strip
 * our action; a POST sidesteps that. The fallback button is always visible in
 * case a strict CSP blocks the inline auto-submit script.
 */
function gm_otp_post_bounce( $url, array $fields ) {
	?>
	<!DOCTYPE html>
	<html>
	<head><meta charset="utf-8"><title><?php esc_html_e( 'Redirectingâ€¦' ); ?></title></head>
	<body>
		<form id="gm-otp-bounce" method="post" action="<?php echo esc_url( $url ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<p><?php esc_html_e( 'Redirectingâ€¦' ); ?> <button type="submit"><?php esc_html_e( 'Continue' ); ?></button></p>
		</form>
		<script>document.getElementById('gm-otp-bounce').submit();</script>
	</body>
	</html>
	<?php
}

/**
 * Resilience: if a proxy/security tool corrupts the bounce and the user lands
 * on the plain login screen with a valid pending-OTP cookie, show the dialog
 * anyway â€” the cookie is the source of truth, not the URL's action.
 */
add_action( 'login_form_login', function () {
	$token = isset( $_COOKIE[ GM_OTP_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GM_OTP_COOKIE ] ) ) : '';
	if ( ! $token ) {
		return;
	}

	if ( ! empty( $_GET['gm_otp_cancel'] ) ) {
		gm_otp_clear_pending( $token );
		return;
	}

	$stored = get_transient( 'gm_otp_' . $token );
	// Only dialog-flow sessions store 'redirect_to' â€” inline/AJAX pending
	// sessions must NOT be pulled into the dialog page, so check for that key.
	if ( $stored && array_key_exists( 'redirect_to', $stored ) ) {
		gm_otp_handle_verify_page();
	}
}, 5 );

/**
 * The dialog page itself: wp-login.php?action=gm_otp
 */
add_action( 'login_form_gm_otp', 'gm_otp_handle_verify_page' );
function gm_otp_handle_verify_page() {
	$token  = isset( $_COOKIE[ GM_OTP_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GM_OTP_COOKIE ] ) ) : '';
	$stored = $token ? get_transient( 'gm_otp_' . $token ) : false;

	if ( ! $stored ) {
		gm_otp_log( 'dialog page: no valid pending cookie/transient â€” back to login' );
		wp_safe_redirect( add_query_arg( 'action', 'login', wp_login_url() ) );
		exit;
	}

	$user_data    = get_userdata( $stored['user_id'] );
	$masked_email = $user_data ? gm_otp_mask_email( $user_data->user_email ) : '';
	$is_post      = 'POST' === gm_otp_input( $_SERVER, 'REQUEST_METHOD' );

	// The bounce-landing POST carries only action=gm_otp and has never seen a
	// nonce; only actual resend/code submissions from our rendered form do.
	$is_user_submission = $is_post && ( isset( $_POST['gm_otp_resend'] ) || isset( $_POST['gm_otp_code'] ) );
	$has_valid_nonce    = $is_user_submission && wp_verify_nonce( gm_otp_input( $_POST, 'gm_otp_verify_nonce' ), 'gm_otp_verify' );

	if ( gm_otp_get_lockout( $stored['user_id'] ) ) {
		gm_otp_clear_pending( $token );
		gm_otp_render_verify_form( new WP_Error( 'gm_otp_locked', gm_otp_lockout_message( $stored['user_id'] ) ), 0, '', $masked_email );
		exit;
	}

	if ( $is_user_submission && ! $has_valid_nonce ) {
		gm_otp_render_verify_form( new WP_Error( 'gm_otp_expired_nonce', __( 'This page expired, please try again.' ) ), max( 0, GM_OTP_RESEND_WAIT - ( time() - (int) $stored['last_sent'] ) ), '', $masked_email );
		exit;
	}

	if ( $is_user_submission && ! empty( $_POST['gm_otp_resend'] ) ) {
		$elapsed = time() - (int) $stored['last_sent'];
		if ( $elapsed < GM_OTP_RESEND_WAIT ) {
			gm_otp_render_verify_form( null, GM_OTP_RESEND_WAIT - $elapsed, '', $masked_email );
			exit;
		}
		$stored['code']      = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$stored['last_sent'] = time();
		set_transient( 'gm_otp_' . $token, $stored, GM_OTP_TTL );
		wp_mail( $user_data->user_email, __( 'Your login code' ), sprintf( __( 'Your one-time login code is: %s (valid 5 minutes)' ), $stored['code'] ) );
		gm_otp_log( "dialog page: resent code for user_id={$stored['user_id']}" );
		gm_otp_render_verify_form( null, GM_OTP_RESEND_WAIT, __( 'A new code has been sent to your email.' ), $masked_email );
		exit;
	}

	if ( $is_user_submission && isset( $_POST['gm_otp_code'] ) ) {
		$submitted = preg_replace( '/\D/', '', gm_otp_input( $_POST, 'gm_otp_code' ) );

		if ( hash_equals( (string) $stored['code'], (string) $submitted ) ) {
			delete_transient( 'gm_otp_' . $token );
			delete_transient( 'gm_otp_attempts_' . $stored['user_id'] );
			setcookie( GM_OTP_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
			wp_set_auth_cookie( $stored['user_id'], (bool) $stored['remember'] );
			do_action( 'wp_login', $user_data->user_login, $user_data );
			gm_otp_log( "dialog page: correct code â€” logging user_id={$stored['user_id']} in" );
			wp_safe_redirect( $stored['redirect_to'] ? $stored['redirect_to'] : admin_url() );
			exit;
		}

		$attempts_key = 'gm_otp_attempts_' . $stored['user_id'];
		$max_attempts = max( 1, (int) gm_otp_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ) );
		$lockout_mins = max( 1, (int) gm_otp_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) );
		$attempts     = (int) get_transient( $attempts_key ) + 1;

		if ( $attempts >= $max_attempts ) {
			delete_transient( $attempts_key );
			set_transient( 'gm_otp_lockout_' . $stored['user_id'], time() + $lockout_mins * MINUTE_IN_SECONDS, $lockout_mins * MINUTE_IN_SECONDS );
			gm_otp_clear_pending( $token );
			gm_otp_render_verify_form( new WP_Error( 'gm_otp_locked', gm_otp_lockout_message( $stored['user_id'] ) ), 0, '', $masked_email );
			exit;
		}

		set_transient( $attempts_key, $attempts, GM_OTP_TTL );
		gm_otp_render_verify_form( new WP_Error( 'gm_otp_invalid', __( 'Wrong code.' ) ), max( 0, GM_OTP_RESEND_WAIT - ( time() - (int) $stored['last_sent'] ) ), '', $masked_email );
		exit;
	}

	gm_otp_render_verify_form( null, max( 0, GM_OTP_RESEND_WAIT - ( time() - (int) $stored['last_sent'] ) ), '', $masked_email );
	exit;
}

/**
 * Style the dialog page as a centered card over a blurred backdrop.
 */
add_action( 'login_enqueue_scripts', function () {
	if ( 'gm_otp' !== gm_otp_input( $_REQUEST, 'action' ) ) {
		return;
	}
	wp_enqueue_style( 'gm-otp-dialog', GM_OTP_PLUGIN_URL . 'assets/gm-otp-dialog.css', array(), GM_OTP_VERSION );
} );

/**
 * Renders the code dialog form (used only by the dedicated dialog page).
 */
function gm_otp_render_verify_form( $wp_error = null, $seconds_left = 0, $notice = '', $masked_email = '' ) {
	$locked = $wp_error && 'gm_otp_locked' === $wp_error->get_error_code();
	if ( $notice && ! $wp_error ) {
		$wp_error = new WP_Error( 'gm_otp_notice', $notice );
	}

	login_header( __( 'Enter your code' ), '', $wp_error );

	if ( ! $locked ) :
		$max_attempts = max( 1, (int) gm_otp_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ) );
		$lockout_mins = max( 1, (int) gm_otp_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) );
		?>
		<form name="gm_otp_form" id="gm_otp_form" action="<?php echo esc_url( add_query_arg( 'action', 'gm_otp', wp_login_url() ) ); ?>" method="post">
			<?php wp_nonce_field( 'gm_otp_verify', 'gm_otp_verify_nonce' ); ?>
			<?php if ( $masked_email ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: masked email, 2: max attempts, 3: lockout minutes */
						esc_html__( 'Code sent to %1$s. After %2$d wrong attempts, this login will be locked for %3$d minute(s).' ),
						esc_html( $masked_email ),
						$max_attempts,
						$lockout_mins
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<label for="gm_otp_code"><?php esc_html_e( '6-digit code' ); ?></label>
				<input type="password" name="gm_otp_code" id="gm_otp_code" class="input" inputmode="numeric" maxlength="6" autocomplete="one-time-code" autofocus="autofocus" />
			</p>
			<p class="submit">
				<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify' ); ?>" />
				<button type="submit" name="gm_otp_resend" value="1" id="gm_otp_resend" class="button" disabled="disabled"></button>
			</p>
		</form>
		<script>
		( function () {
			var btn = document.getElementById( 'gm_otp_resend' );
			var left = <?php echo (int) $seconds_left; ?>;
			var label = <?php echo wp_json_encode( __( 'Resend code' ) ); ?>;
			var waitLabel = <?php echo wp_json_encode( __( 'Resend code (%ds)' ) ); ?>;
			function tick() {
				if ( left <= 0 ) { btn.disabled = false; btn.textContent = label; return; }
				btn.disabled = true; btn.textContent = waitLabel.replace( '%d', left ); left--; setTimeout( tick, 1000 );
			}
			tick();
		} )();
		</script>
	<?php endif; ?>
	<p id="nav">
		<a href="<?php echo esc_url( add_query_arg( 'gm_otp_cancel', '1', wp_login_url() ) ); ?>"><?php esc_html_e( '&larr; Back to login' ); ?></a>
	</p>
	<?php
	login_footer( $locked ? null : 'gm_otp_code' );
}

/**
 * Custom login logo, applied to every wp-login.php screen (not just the
 * OTP code page) if one is set in settings.
 */
add_filter( 'login_headerurl', function ( $url ) {
	return gm_otp_option( GM_OTP_LOGO_OPTION ) ? home_url( '/' ) : $url;
} );

add_action( 'login_enqueue_scripts', function () {
	$logo = gm_otp_option( GM_OTP_LOGO_OPTION );
	if ( ! $logo ) {
		return;
	}
	?>
	<style>
		/* !important: WP core's own login.css sets this same selector and
		   can print after this inline block, which would otherwise win on
		   equal specificity. */
		.login h1 a {
			background-image: url( <?php echo esc_url( $logo ); ?> ) !important;
			background-size: contain !important;
			background-position: center !important;
			width: 320px !important;
			height: 90px !important;
		}
	</style>
	<?php
} );

function gm_otp_clear_pending( $token ) {
	delete_transient( 'gm_otp_' . $token );
	setcookie( GM_OTP_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
	setcookie( 'gm_otp_show', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
}

/**
 * A deliberately non-secret, non-HttpOnly companion cookie the login-page
 * script polls for. It only signals "a code is pending, reveal the field" â€”
 * the actual token stays in the HttpOnly GM_OTP_COOKIE that JS can't read.
 * Needed because AJAX login flows never re-render the page, so there's no
 * server-side moment to un-hide the field on.
 */
function gm_otp_set_show_cookie() {
	setcookie( 'gm_otp_show', '1', time() + GM_OTP_TTL, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
}

function gm_otp_get_lockout( $user_id ) {
	return get_transient( 'gm_otp_lockout_' . $user_id );
}

function gm_otp_mask_email( $email ) {
	$at = strpos( $email, '@' );
	if ( false === $at ) {
		return str_repeat( '*', 6 );
	}
	$local  = substr( $email, 0, $at );
	$domain = substr( $email, $at + 1 );

	$local_visible = mb_substr( $local, 0, min( 2, max( 1, mb_strlen( $local ) - 1 ) ) );

	$last_dot = strrpos( $domain, '.' );
	if ( false === $last_dot ) {
		$masked_domain = str_repeat( '*', 2 ) . $domain;
	} else {
		$tld  = substr( $domain, $last_dot ); // includes the leading dot
		$name = substr( $domain, 0, $last_dot );
		$domain_visible = mb_substr( $name, 0, 1 );
		$hidden         = max( 2, mb_strlen( $name ) - 1 );
		$masked_domain  = $domain_visible . str_repeat( '*', $hidden ) . $tld;
	}

	return $local_visible . str_repeat( '*', 4 ) . '@' . $masked_domain;
}

function gm_otp_lockout_message( $user_id ) {
	$locked_until = gm_otp_get_lockout( $user_id );
	$minutes_left = max( 1, (int) ceil( ( $locked_until - time() ) / MINUTE_IN_SECONDS ) );
	return sprintf(
		/* translators: %d: minutes remaining */
		__( 'Too many wrong codes. Try again in %d minute(s).' ),
		$minutes_left
	);
}

/**
 * Renders the 6-digit code field directly on the login form, right below
 * username/password â€” hooked on 'login_form', so it lives INSIDE the login
 * <form>. Crucial detail: the field is ALWAYS present in the markup (just
 * hidden until a code is actually pending), never conditionally omitted.
 *
 * Why "always present": AJAX login flows (Wordfence Login Security's own
 * reCAPTCHA is the known case) submit the login through admin-ajax.php and
 * never re-render wp-login.php, so a field printed only *after* the code is
 * required could never appear. By keeping the input in the form from the
 * first render, the AJAX serializer carries it on every submit, and a tiny
 * script just un-hides it the moment a code is required (detected via the
 * non-secret gm_otp_show cookie our server sets alongside the real token).
 *
 * On a normal (non-AJAX) login the server re-renders with
 * $GLOBALS['gm_otp_pending'] set and the field is already visible â€” the
 * cookie-poll path is simply never needed there.
 */
add_action( 'login_form', 'gm_otp_render_field' );
function gm_otp_render_field() {
	$pending = null;

	if ( isset( $GLOBALS['gm_otp_pending'] ) ) {
		$pending = $GLOBALS['gm_otp_pending'];
	}
	// Otherwise the field renders hidden and the script below reveals it when
	// the server sets the gm_otp_show cookie (AJAX login flows only). Normal
	// POST logins use the dedicated dialog page instead, so this inline field
	// stays hidden and unused there.

	$active       = null !== $pending;
	$masked_email = $active ? $pending['masked_email'] : '';
	$seconds_left = $active ? (int) $pending['seconds_left'] : (int) GM_OTP_RESEND_WAIT;
	$max_attempts = max( 1, (int) gm_otp_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ) );
	$lockout_mins = max( 1, (int) gm_otp_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) );
	?>
	<div id="gm_otp_wrap" style="<?php echo $active ? '' : 'display:none;'; ?>">
		<p class="description" id="gm_otp_sent_msg">
			<?php
			if ( $active ) {
				printf(
					/* translators: 1: masked email, 2: max attempts, 3: lockout minutes */
					esc_html__( 'Code sent to %1$s. After %2$d wrong attempts, this login will be locked for %3$d minute(s).' ),
					esc_html( $masked_email ),
					$max_attempts,
					$lockout_mins
				);
			}
			?>
		</p>
		<p>
			<label for="gm_otp_code"><?php esc_html_e( '6-digit code' ); ?></label>
			<input type="password" name="gm_otp_code" id="gm_otp_code" class="input" inputmode="numeric" maxlength="6" autocomplete="one-time-code" style="width:100%;" />
		</p>
		<p>
			<?php // Hidden flag, not the button's own name â€” button values aren't reliably serialized by AJAX login submitters. ?>
			<input type="hidden" name="gm_otp_resend" id="gm_otp_resend_flag" value="" />
			<?php // type="button", NOT submit â€” otherwise pressing Enter in the code field would fire this (the first submit button) and resend instead of logging in. ?>
			<button type="button" id="gm_otp_resend" class="button" disabled="disabled"></button>
		</p>
	</div>
	<script>
	( function () {
		var wrap       = document.getElementById( 'gm_otp_wrap' );
		var codeInput  = document.getElementById( 'gm_otp_code' );
		var resendBtn  = document.getElementById( 'gm_otp_resend' );
		var resendFlag = document.getElementById( 'gm_otp_resend_flag' );
		var sentMsg    = document.getElementById( 'gm_otp_sent_msg' );
		var form       = codeInput.form;
		var active     = <?php echo $active ? 'true' : 'false'; ?>;
		var label      = <?php echo wp_json_encode( __( 'Resend code' ) ); ?>;
		var waitLabel  = <?php echo wp_json_encode( __( 'Resend code (%ds)' ) ); ?>;
		var sentText   = <?php echo wp_json_encode( __( 'A code has been emailed to you. Enter it above to finish signing in.' ) ); ?>;

		function reveal() {
			if ( 'none' === wrap.style.display ) {
				wrap.style.display = '';
				if ( ! sentMsg.textContent.trim() ) {
					sentMsg.textContent = sentText;
				}
				codeInput.focus();
			}
		}

		function startTimer( left ) {
			( function tick() {
				if ( left <= 0 ) {
					resendBtn.disabled = false;
					resendBtn.textContent = label;
					return;
				}
				resendBtn.disabled = true;
				resendBtn.textContent = waitLabel.replace( '%d', left );
				left--;
				setTimeout( tick, 1000 );
			} )();
		}

		// The real "Log In" button, so Enter and resend both drive the same
		// submit path the login plugin expects.
		var loginBtn = form.querySelector( '#wp-submit' ) || form.querySelector( 'input[type=submit], button[type=submit]' );

		function submitLogin() {
			if ( form.requestSubmit ) { form.requestSubmit( loginBtn || undefined ); }
			else if ( loginBtn ) { loginBtn.click(); }
			else { form.submit(); }
		}

		// Resend: arm the hidden flag, then re-submit the (possibly
		// AJAX-hijacked) login form so the serializer carries gm_otp_resend=1.
		resendBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			resendFlag.value = '1';
			submitLogin();
		} );
		// Typing a code clears the resend flag so the next submit verifies
		// rather than re-triggering a resend.
		codeInput.addEventListener( 'input', function () { resendFlag.value = ''; } );
		// Enter in the code field = clicking Log In (not resend).
		codeInput.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || 13 === e.keyCode ) {
				e.preventDefault();
				resendFlag.value = '';
				submitLogin();
			}
		} );

		startTimer( active ? <?php echo (int) $seconds_left; ?> : 0 );

		// AJAX login (e.g. Wordfence reCAPTCHA) never re-renders the page, so
		// watch for the server's reveal cookie to know when a code is pending.
		if ( ! active ) {
			var poll = setInterval( function () {
				if ( /(?:^|;\s*)gm_otp_show=1/.test( document.cookie ) ) {
					clearInterval( poll );
					reveal();
					startTimer( <?php echo (int) GM_OTP_RESEND_WAIT; ?> );
				}
			}, 500 );
		}
	} )();
	</script>
	<?php
}