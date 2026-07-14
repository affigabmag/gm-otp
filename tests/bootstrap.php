<?php
/**
 * Minimal WordPress stubs so the real GM OTP includes can run under plain PHP
 * CLI, for testing the auth decision logic (captcha bypass, multisite routing,
 * AJAX vs dialog). Not a full WP â€” just enough for includes/core.php + login.php.
 */
error_reporting( E_ERROR | E_PARSE ); // silence CLI setcookie/headers warnings

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/gm-otp-test-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['t_opts']      = array();
$GLOBALS['t_site']      = array();
$GLOBALS['t_trans']     = array();
$GLOBALS['t_multisite'] = false;
$GLOBALS['t_ajax']      = false;
$GLOBALS['t_auth_result'] = null;

function is_multisite() { return $GLOBALS['t_multisite']; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $k ] : $d; }
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_site'] ) ? $GLOBALS['t_site'][ $k ] : $d; }
function get_current_blog_id() { return 1; }
function wp_doing_ajax() { return $GLOBALS['t_ajax']; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['t_trans'] ) ? $GLOBALS['t_trans'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['t_trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t_trans'][ $k ] ); return true; }
function add_filter() { return true; }
function add_action() { return true; }
function has_filter() { return false; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/gm-otp/'; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_add_inline_script() {}
function wp_enqueue_media() {}
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : $v; }
function esc_html__( $t, $d = null ) { return $t; }
function __( $t, $d = null ) { return $t; }
function esc_html_e( $t, $d = null ) { echo $t; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function esc_attr_e( $t, $d = null ) { echo $t; }
function esc_url( $t ) { return $t; }
function esc_js( $t ) { return $t; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function wp_generate_password( $len = 12, $s = true ) { return substr( bin2hex( random_bytes( $len ) ), 0, $len ); }
function wp_mail( $to, $s, $m ) { return true; }
function is_ssl() { return true; }
function wp_login_url() { return 'https://example.test/wp-login.php'; }
function wp_authenticate_username_password( $u, $user, $pass ) { return $GLOBALS['t_auth_result']; }
function add_query_arg( $k, $v, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $k . '=' . $v; }
function wp_set_auth_cookie( $id, $r = false ) { $GLOBALS['t_auth_cookie_set'] = $id; }
function do_action() {}
function wp_safe_redirect( $u ) { $GLOBALS['t_redirect'] = $u; }
function login_header() {}
function login_footer() {}
function wp_nonce_field() {}
function wp_verify_nonce() { return true; }
function submit_button() {}

// This particular Local PHP CLI build has mbstring off; WP servers have it on.
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( $s, $start ) : substr( $s, $start, $len ); }
}
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $s ) { return strlen( $s ); }
}

class WP_Error {
	private $code;
	private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_User {
	public $ID;
	public $user_email;
	public $user_login;
	public $roles = array();
	public function __construct( $id = 0, $email = '', $login = '' ) {
		$this->ID = $id; $this->user_email = $email; $this->user_login = $login;
	}
}

$GLOBALS['_SERVER']['SCRIPT_NAME'] = 'cli-test';

// Load the real plugin exactly as WordPress would: main file defines the
// GM_OTP_* constants and require_once's includes/core.php, admin.php, login.php.
require_once __DIR__ . '/../gm-otp.php';

function reset_state( $enabled = 1, $smtp = 1 ) {
	$GLOBALS['t_multisite'] = false;
	$GLOBALS['t_ajax']      = true;
	$GLOBALS['t_trans']     = array();
	$GLOBALS['t_opts']      = array( GM_OTP_OPTION => $enabled, GM_OTP_SMTP_CONFIRMED_OPTION => $smtp );
	$GLOBALS['t_site']      = $GLOBALS['t_opts'];
	$_COOKIE = array();
	$_POST   = array();
	$_REQUEST = array();
}

function code_of( $r ) {
	if ( $r instanceof WP_User ) { return 'WP_User'; }
	if ( $r instanceof WP_Error ) { return 'WP_Error:' . $r->get_error_code(); }
	return gettype( $r );
}

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
function check( $label, $got, $want ) {
	$ok = ( $got === $want );
	printf( "[%s] %s\n    got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label, $got, $want );
	if ( $ok ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail']++; }
}
