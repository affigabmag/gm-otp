<?php
/**
 * Plugin Name: GM OTP
 * Description: After username/password check, requires a 6-digit code emailed to the user, entered as a 3rd field on the same login form, before login completes.
 * Version: 3.16.0
 * Author: Affiliate GM
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gm-otp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GM_OTP_VERSION', '3.16.0' );
define( 'GM_OTP_BUILD_TIME', '2026-07-14 15:30' );

/**
 * Show the build timestamp next to the version on the Plugins list
 * (both the network admin and single-site screens use this filter).
 */
add_filter( 'plugin_row_meta', function ( $plugin_meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$plugin_meta[] = sprintf(
			/* translators: %s: build timestamp */
			esc_html__( 'Updated: %s' ),
			esc_html( GM_OTP_BUILD_TIME )
		);
	}
	return $plugin_meta;
}, 10, 2 );
define( 'GM_OTP_COOKIE', 'gm_otp_token' );
define( 'GM_OTP_TTL', 300 ); // 5 minutes
define( 'GM_OTP_RESEND_WAIT', 60 ); // seconds before resend is allowed
define( 'GM_OTP_OPTION', 'gm_otp_enabled' );
define( 'GM_OTP_MAX_ATTEMPTS_OPTION', 'gm_otp_max_attempts' );
define( 'GM_OTP_LOCKOUT_MINUTES_OPTION', 'gm_otp_lockout_minutes' );
define( 'GM_OTP_LOGO_OPTION', 'gm_otp_logo_url' );
define( 'GM_OTP_EXEMPT_ROLES_OPTION', 'gm_otp_exempt_roles' );
define( 'GM_OTP_EXEMPT_USERS_OPTION', 'gm_otp_exempt_users' );
define( 'GM_OTP_SMTP_CONFIRMED_OPTION', 'gm_otp_smtp_confirmed' );
define( 'GM_OTP_LOCKOUT_ACK_OPTION', 'gm_otp_lockout_ack' );
define( 'GM_OTP_LOG_DIR', WP_CONTENT_DIR . '/gm-otp-logs' );
define( 'GM_OTP_LOG_FILE', GM_OTP_LOG_DIR . '/otp.log' );

define( 'GM_OTP_PLUGIN_FILE', __FILE__ );
define( 'GM_OTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/login.php';
