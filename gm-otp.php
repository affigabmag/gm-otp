<?php
/**
 * Plugin Name: GM OTP
 * Description: After username/password check, requires a 6-digit code emailed to the user, entered as a 3rd field on the same login form, before login completes.
 * Version: 3.12.1
 * Author: Affiliate GM
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gm-otp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GM_OTP_VERSION', '3.12.1' );
define( 'GM_OTP_BUILD_TIME', '2026-07-14 08:35' );

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

/**
 * Logging. Writes to wp-content/gm-otp-logs/otp.log (outside the plugin
 * folder so it survives updates) with an .htaccess/index.php pair so the
 * file can't be requested directly over HTTP — it can contain email
 * addresses and (briefly) valid codes. Also mirrors to error_log() so it
 * shows up alongside PHP's own log (e.g. Local's logs/php/error.log).
 */
function gm_otp_log( $message ) {
	if ( ! file_exists( GM_OTP_LOG_DIR ) ) {
		wp_mkdir_p( GM_OTP_LOG_DIR );
		file_put_contents( GM_OTP_LOG_DIR . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( GM_OTP_LOG_DIR . '/.htaccess', "Require all denied\nDeny from all\n" );
	}

	$site = is_multisite() ? ' site=' . get_current_blog_id() : '';
	$line = sprintf( '[%s]%s %s' . PHP_EOL, gmdate( 'Y-m-d H:i:s' ), $site, $message );

	error_log( 'GM OTP: ' . $message );
	file_put_contents( GM_OTP_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
}

/**
 * Read a superglobal value the way WPCS wants: unslashed and sanitized,
 * even though these are only ever used in log messages/routing decisions,
 * never echoed as HTML or stored to the DB.
 */
function gm_otp_input( array $source, $key, $default = '' ) {
	return isset( $source[ $key ] ) ? sanitize_text_field( wp_unslash( $source[ $key ] ) ) : $default;
}

/**
 * Fires the instant this file is parsed — before plugins_loaded, before any
 * of our own hooks run. If a login attempt never produces even this line,
 * the plugin file itself isn't executing for that request (wrong site,
 * not actually active there, or something fataled earlier in bootstrap) —
 * as opposed to running fine but "authenticate" never firing.
 */
if ( false !== strpos( gm_otp_input( $_SERVER, 'SCRIPT_NAME' ), 'wp-login.php' ) ) {
	gm_otp_log( sprintf(
		'plugin file loaded on wp-login.php request: host=%s, method=%s, GET[action]=%s, POST[action]=%s, REQUEST[action]=%s',
		gm_otp_input( $_SERVER, 'HTTP_HOST', '(unknown)' ),
		gm_otp_input( $_SERVER, 'REQUEST_METHOD', '(unknown)' ),
		gm_otp_input( $_GET, 'action', '(unset)' ),
		gm_otp_input( $_POST, 'action', '(unset)' ),
		gm_otp_input( $_REQUEST, 'action', '(unset)' )
	) );

	/**
	 * Trace the request through WP's own lifecycle to find exactly where
	 * "action=gm_otp" gets lost, if it does. Each of these fires at a
	 * different point relative to when wp-login.php reads $_REQUEST['action']
	 * and validates it against has_filter( 'login_form_' . $action ).
	 */
	add_action( 'plugins_loaded', function () {
		$network_active = is_multisite()
			? implode( ', ', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) )
			: '(not multisite)';
		gm_otp_log( sprintf(
			'plugins_loaded: GET[action]=%s, has_filter(login_form_gm_otp)=%s, site_active_plugins=%s, network_active_plugins=%s',
			gm_otp_input( $_GET, 'action', '(unset)' ),
			has_filter( 'login_form_gm_otp' ) ? 'yes' : 'NO',
			implode( ', ', (array) get_option( 'active_plugins', array() ) ),
			$network_active
		) );
	}, 1 );

	add_action( 'login_init', function () {
		gm_otp_log( sprintf(
			'login_init: GET[action]=%s, REQUEST[action]=%s, has_filter(login_form_gm_otp)=%s',
			gm_otp_input( $_GET, 'action', '(unset)' ),
			gm_otp_input( $_REQUEST, 'action', '(unset)' ),
			has_filter( 'login_form_gm_otp' ) ? 'yes' : 'NO'
		) );
	}, 1 );

	// If WP fell back to the default login screen despite action=gm_otp
	// being requested, THIS fires instead of login_form_gm_otp — proving
	// core's own validity check rejected our action for this request.
	add_action( 'login_form_login', function () {
		gm_otp_log( sprintf(
			'login_form_login fired (fallback) — GET[action] at this point=%s, REQUEST[action]=%s',
			gm_otp_input( $_GET, 'action', '(unset)' ),
			gm_otp_input( $_REQUEST, 'action', '(unset)' )
		) );
	}, 1 );
}

function gm_otp_read_log_tail( $max_bytes = 50000 ) {
	if ( ! file_exists( GM_OTP_LOG_FILE ) ) {
		return '';
	}
	// file_get_contents() + substr() instead of fopen()/fseek()/fread() —
	// avoids the low-level file handle entirely for what's just a tail read.
	$contents = file_get_contents( GM_OTP_LOG_FILE );
	return strlen( $contents ) > $max_bytes ? substr( $contents, -$max_bytes ) : $contents;
}

/**
 * ini_get('error_log') is often empty even though PHP errors are landing
 * *somewhere* on disk (PHP-FPM/Apache/Nginx frequently have their own
 * error log independent of PHP's own setting). Rather than give up, probe
 * the handful of paths that cover the vast majority of real hosting setups
 * — first one that's actually readable from this PHP process wins.
 */
function gm_otp_find_php_error_log() {
	$configured = ini_get( 'error_log' );

	$candidates = array(
		$configured,
		'/var/log/php-fpm/www-error.log',
		'/var/log/php-fpm/error.log',
		'/var/log/php8.3-fpm.log',
		'/var/log/php8.2-fpm.log',
		'/var/log/php8.1-fpm.log',
		'/var/log/php8.0-fpm.log',
		'/var/log/php7.4-fpm.log',
		'/var/log/php/error.log',
		'/var/log/php_errors.log',
		'/var/log/apache2/error.log',
		'/var/log/httpd/error_log',
		'/var/log/nginx/error.log',
		rtrim( sys_get_temp_dir(), '/' ) . '/php-errors.log',
	);

	foreach ( $candidates as $path ) {
		if ( $path && is_readable( $path ) && is_file( $path ) ) {
			return $path;
		}
	}

	return null;
}

/**
 * Tail whichever file gm_otp_find_php_error_log() locates — no FTP/SSH/
 * hosting-panel access needed, as long as PHP's own worker can read it
 * (usually true, since it's frequently the same worker writing to it).
 */
function gm_otp_read_php_error_log_tail( $max_bytes = 50000 ) {
	$path = gm_otp_find_php_error_log();
	if ( ! $path ) {
		return null; // nothing readable found among the candidates
	}
	$contents = file_get_contents( $path );
	return strlen( $contents ) > $max_bytes ? substr( $contents, -$max_bytes ) : $contents;
}

/**
 * "View Log" / "Clear Log" block, shared by the network and single-site
 * settings pages. Caller is responsible for the capability check.
 */
function gm_otp_render_log_viewer( $base_url ) {
	if ( wp_verify_nonce( gm_otp_input( $_POST, 'gm_otp_clear_log_nonce' ), 'gm_otp_clear_log' ) ) {
		file_put_contents( GM_OTP_LOG_FILE, '' );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Log cleared.' ) . '</p></div>';
	}

	$show_log  = ! empty( $_GET['gm_otp_view_log'] );
	$check_dir = file_exists( GM_OTP_LOG_DIR ) ? GM_OTP_LOG_DIR : WP_CONTENT_DIR;
	// Read-only diagnostic display, not a file modification — WP_Filesystem is unnecessary here.
	$writable = is_writable( $check_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	?>
	<div style="display:flex;gap:40px;flex-wrap:wrap;align-items:flex-start;">
	<div style="flex:1;min-width:340px;">
	<h2><?php esc_html_e( 'Log' ); ?></h2>
	<p>
		<?php if ( $writable ) : ?>
			<span style="color:#008a20;">&#10003;</span> <?php esc_html_e( 'Log directory is writable — logging should work.' ); ?>
		<?php else : ?>
			<span style="color:#d63638;">&#10007;</span>
			<?php
			printf(
				/* translators: %s: log directory path */
				esc_html__( 'NOT writable: %s — file logging will silently fail here. Check error_log() / your host\'s PHP error log instead.' ),
				'<code>' . esc_html( GM_OTP_LOG_DIR ) . '</code>'
			);
			?>
		<?php endif; ?>
	</p>
	<p>
		<a href="<?php echo esc_url( add_query_arg( 'gm_otp_view_log', '1', $base_url ) ); ?>" class="button">
			<?php esc_html_e( 'View Log' ); ?>
		</a>
	</p>
	<?php if ( $show_log ) : ?>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'gm_otp_view_log', '1', $base_url ) ); ?>">
			<?php wp_nonce_field( 'gm_otp_clear_log', 'gm_otp_clear_log_nonce' ); ?>
			<p>
				<textarea readonly rows="20" style="width:100%;max-width:900px;font-family:monospace;font-size:12px;" onclick="this.select()"><?php
					$log = gm_otp_read_log_tail();
					echo esc_textarea( $log ? $log : __( '(log is empty)' ) );
				?></textarea>
			</p>
			<?php submit_button( __( 'Clear Log' ), 'delete' ); ?>
		</form>
	<?php endif; ?>
	</div>
	<div style="flex:1;min-width:340px;">
	<h2><?php esc_html_e( 'PHP Error Log' ); ?></h2>
	<p>
		<?php
		$configured_path = ini_get( 'error_log' );
		$found_path      = gm_otp_find_php_error_log();
		?>
		<?php if ( $found_path ) : ?>
			<span style="color:#008a20;">&#10003;</span>
			<?php
			printf(
				/* translators: %s: log file path */
				esc_html__( 'Found a readable log at %s' ),
				'<code>' . esc_html( $found_path ) . '</code>'
			);
			if ( $found_path !== $configured_path ) {
				echo ' ' . esc_html__( '(not the one PHP itself is configured to use — found by checking common hosting paths instead).' );
			}
			?>
		<?php else : ?>
			<span style="color:#d63638;">&#10007;</span>
			<?php esc_html_e( "Couldn't find a readable PHP error log among common paths." ); ?>
		<?php endif; ?>
		<br />
		<?php
		printf(
			/* translators: %s: configured error_log path */
			esc_html__( "PHP's own configured error_log directive: %s" ),
			'<code>' . esc_html( $configured_path ? $configured_path : '(not set — logging to stderr/syslog, not a file)' ) . '</code>'
		);
		?>
		<br />
		<?php
		printf(
			/* translators: 1: log_errors on/off, 2: display_errors value */
			esc_html__( 'log_errors: %1$s, display_errors: %2$s' ),
			esc_html( ini_get( 'log_errors' ) ? 'On' : 'Off' ),
			esc_html( ini_get( 'display_errors' ) ?: 'Off' )
		);
		?>
	</p>
	<p class="description">
		<?php
		printf(
			/* translators: 1: SAPI name, 2: loaded php.ini path */
			esc_html__( 'phpinfo() equivalents, in case the log above needs chasing down manually via SSH: SAPI = %1$s, loaded php.ini = %2$s' ),
			'<code>' . esc_html( php_sapi_name() ) . '</code>',
			'<code>' . esc_html( php_ini_loaded_file() ?: '(none loaded)' ) . '</code>'
		);
		?>
	</p>
	<p>
		<a href="<?php echo esc_url( add_query_arg( 'gm_otp_view_php_log', '1', $base_url ) ); ?>" class="button">
			<?php esc_html_e( 'View PHP Error Log' ); ?>
		</a>
	</p>
	<?php if ( ! empty( $_GET['gm_otp_view_php_log'] ) ) :
		$php_log = gm_otp_read_php_error_log_tail();
		?>
		<p>
			<textarea readonly rows="20" style="width:100%;max-width:900px;font-family:monospace;font-size:12px;" onclick="this.select()"><?php
				echo esc_textarea( null === $php_log ? __( '(nothing readable found among common paths; use FTP/SSH/hosting panel instead)' ) : ( $php_log ?: __( '(log is empty)' ) ) );
			?></textarea>
		</p>
	<?php endif; ?>
	</div>
	</div>
	<?php
}

/**
 * Option storage: on multisite this is network-wide (one switch controls
 * every site), on single-site it's the normal per-site option.
 */
function gm_otp_option( $key, $default = false ) {
	return is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
}

/**
 * True if $user should skip OTP entirely — either their user ID or any of
 * their roles is on the exemption lists configured in settings.
 */
function gm_otp_is_exempt( WP_User $user ) {
	$exempt_users = (array) gm_otp_option( GM_OTP_EXEMPT_USERS_OPTION, array() );
	if ( in_array( (int) $user->ID, array_map( 'intval', $exempt_users ), true ) ) {
		return true;
	}

	$exempt_roles = (array) gm_otp_option( GM_OTP_EXEMPT_ROLES_OPTION, array() );
	if ( $exempt_roles && array_intersect( $user->roles, $exempt_roles ) ) {
		return true;
	}

	return false;
}

/**
 * Settings page: available both as a top-level admin menu item and under
 * Settings > GM OTP (same slug/callback, so both entries open the same page).
 * On multisite this becomes read-only, pointing at the network settings
 * page instead — the network switch is what actually gates the feature.
 */
add_action( 'admin_menu', function () {
	add_menu_page( 'GM OTP', 'GM OTP', 'manage_options', 'gm-otp', 'gm_otp_render_settings_page', 'dashicons-lock', 80 );
} );

/**
 * "Settings" link next to Deactivate on the Plugins list, like other plugins.
 * On multisite the network Plugins screen points at the network settings page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=gm-otp' ) ) . '">' . esc_html__( 'Settings' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
} );

add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$settings = '<a href="' . esc_url( network_admin_url( 'settings.php?page=gm-otp-network' ) ) . '">' . esc_html__( 'Settings' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
} );


function gm_otp_sanitize_exempt_roles( $roles ) {
	$roles = array_map( 'sanitize_key', (array) $roles );
	return array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) );
}

function gm_otp_sanitize_exempt_users( $users ) {
	return array_values( array_filter( array_map( 'absint', (array) $users ) ) );
}

/**
 * Media uploader JS, only on GM OTP's own settings screens.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$screens = array( 'toplevel_page_gm-otp', 'settings_page_gm-otp-network' );
	if ( ! in_array( $hook, $screens, true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script( 'jquery-core', <<<'JS'
		jQuery(function ($) {
			$('#gm_otp_logo_pick').on('click', function (e) {
				e.preventDefault();
				var frame = wp.media({ title: 'Select login logo', multiple: false });
				frame.on('select', function () {
					var url = frame.state().get('selection').first().toJSON().url;
					$('#gm_otp_logo_url').val(url);
					$('#gm_otp_logo_preview').attr('src', url).show();
				});
				frame.open();
			});
			$('#gm_otp_logo_clear').on('click', function (e) {
				e.preventDefault();
				$('#gm_otp_logo_url').val('');
				$('#gm_otp_logo_preview').hide();
			});
		});
JS
	);
} );

function gm_otp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( is_multisite() ) {
		?>
		<div class="wrap">
			<h1>GM OTP</h1>
			<p><?php esc_html_e( 'This is controlled network-wide.' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable OTP login' ); ?></th>
					<td><?php echo gm_otp_option( GM_OTP_OPTION ) ? esc_html__( 'Enabled' ) : esc_html__( 'Disabled' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max attempts' ); ?></th>
					<td><?php echo esc_html( gm_otp_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Lockout duration (minutes)' ); ?></th>
					<td><?php echo esc_html( gm_otp_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Login logo' ); ?></th>
					<td>
						<?php $logo = gm_otp_option( GM_OTP_LOGO_OPTION ); ?>
						<?php if ( $logo ) : ?>
							<img src="<?php echo esc_url( $logo ); ?>" style="max-width:200px;max-height:80px;display:block;" />
						<?php else : ?>
							<?php esc_html_e( '(using default WordPress logo)' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exempt roles' ); ?></th>
					<td><?php echo esc_html( gm_otp_option( GM_OTP_EXEMPT_ROLES_OPTION ) ? implode( ', ', gm_otp_option( GM_OTP_EXEMPT_ROLES_OPTION ) ) : __( '(none)' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exempt users' ); ?></th>
					<td><?php echo esc_html( count( (array) gm_otp_option( GM_OTP_EXEMPT_USERS_OPTION ) ) . ' ' . __( 'user(s)' ) ); ?></td>
				</tr>
			</table>
			<?php if ( current_user_can( 'manage_network_options' ) ) : ?>
				<p><a href="<?php echo esc_url( network_admin_url( 'settings.php?page=gm-otp-network' ) ); ?>"><?php esc_html_e( 'Change at network level' ); ?></a></p>
			<?php endif; ?>
			<hr />
			<?php gm_otp_render_log_viewer( admin_url( 'options-general.php?page=gm-otp' ) ); ?>
		</div>
		<?php
		return;
	}
	$test_result = gm_otp_maybe_send_test_email();

	if ( wp_verify_nonce( gm_otp_input( $_POST, 'gm_otp_site_nonce' ), 'gm_otp_site_save' ) ) {
		$smtp_confirmed = ! empty( $_POST[ GM_OTP_SMTP_CONFIRMED_OPTION ] );
		$lockout_ack    = ! empty( $_POST[ GM_OTP_LOCKOUT_ACK_OPTION ] );
		$wants_enabled  = ! empty( $_POST[ GM_OTP_OPTION ] );

		if ( $wants_enabled && ! $smtp_confirmed ) {
			$wants_enabled = false;
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Not enabled: you must confirm email delivery works (send a test email and check the box below) before OTP login can be turned on.' ) . '</p></div>';
		}

		if ( $wants_enabled && ! $lockout_ack ) {
			$wants_enabled = false;
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Not enabled: you must acknowledge the lockout risk (check the recovery-awareness box below) before OTP login can be turned on.' ) . '</p></div>';
		}

		update_option( GM_OTP_OPTION, $wants_enabled );
		update_option( GM_OTP_SMTP_CONFIRMED_OPTION, $smtp_confirmed );
		update_option( GM_OTP_LOCKOUT_ACK_OPTION, $lockout_ack );
		update_option( GM_OTP_MAX_ATTEMPTS_OPTION, max( 1, absint( $_POST[ GM_OTP_MAX_ATTEMPTS_OPTION ] ?? 3 ) ) );
		update_option( GM_OTP_LOCKOUT_MINUTES_OPTION, max( 1, absint( $_POST[ GM_OTP_LOCKOUT_MINUTES_OPTION ] ?? 15 ) ) );
		update_option( GM_OTP_LOGO_OPTION, isset( $_POST[ GM_OTP_LOGO_OPTION ] ) ? sanitize_url( wp_unslash( $_POST[ GM_OTP_LOGO_OPTION ] ) ) : '' );
		update_option( GM_OTP_EXEMPT_ROLES_OPTION, gm_otp_sanitize_exempt_roles( wp_unslash( $_POST[ GM_OTP_EXEMPT_ROLES_OPTION ] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_option( GM_OTP_EXEMPT_USERS_OPTION, gm_otp_sanitize_exempt_users( wp_unslash( $_POST[ GM_OTP_EXEMPT_USERS_OPTION ] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.' ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1>GM OTP</h1>
		<form method="post">
			<?php wp_nonce_field( 'gm_otp_site_save', 'gm_otp_site_nonce' ); ?>
			<table class="form-table">
				<?php
				gm_otp_render_enable_field(
					get_option( GM_OTP_OPTION ),
					get_option( GM_OTP_SMTP_CONFIRMED_OPTION ),
					$test_result,
					__( 'Require email code after password on login' ),
					get_option( GM_OTP_LOCKOUT_ACK_OPTION )
				);
				?>
				<?php gm_otp_render_attempts_row( get_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ), get_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) ); ?>
				<?php gm_otp_render_logo_field( get_option( GM_OTP_LOGO_OPTION, '' ) ); ?>
				<?php gm_otp_render_exemptions_field( get_option( GM_OTP_EXEMPT_ROLES_OPTION, array() ), get_option( GM_OTP_EXEMPT_USERS_OPTION, array() ) ); ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr />
		<?php gm_otp_render_log_viewer( admin_url( 'options-general.php?page=gm-otp' ) ); ?>
	</div>
	<?php
}

/**
 * Shared "Max attempts" + "Lockout duration" row — the two live side by side
 * in a single form-table row instead of stacked.
 */
function gm_otp_render_attempts_row( $max_attempts, $lockout_minutes ) {
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Attempts & lockout' ); ?></th>
		<td>
			<div style="display:flex;gap:32px;flex-wrap:wrap;align-items:flex-start;">
				<div>
					<label for="gm_otp_max_attempts" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Max attempts' ); ?></label>
					<input type="number" min="1" step="1" name="<?php echo esc_attr( GM_OTP_MAX_ATTEMPTS_OPTION ); ?>" id="gm_otp_max_attempts" value="<?php echo esc_attr( $max_attempts ); ?>" class="small-text" />
					<p class="description" style="max-width:220px;"><?php esc_html_e( 'Wrong-code attempts allowed before lockout.' ); ?></p>
				</div>
				<div>
					<label for="gm_otp_lockout_minutes" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Lockout duration (minutes)' ); ?></label>
					<input type="number" min="1" step="1" name="<?php echo esc_attr( GM_OTP_LOCKOUT_MINUTES_OPTION ); ?>" id="gm_otp_lockout_minutes" value="<?php echo esc_attr( $lockout_minutes ); ?>" class="small-text" />
					<p class="description" style="max-width:220px;"><?php esc_html_e( 'How long the user is blocked after hitting max attempts.' ); ?></p>
				</div>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Shared logo-upload row (media-library picker) for both settings pages.
 */
function gm_otp_render_logo_field( $current ) {
	?>
	<tr>
		<th scope="row"><label for="gm_otp_logo_url"><?php esc_html_e( 'Login logo' ); ?></label></th>
		<td>
			<input type="hidden" name="<?php echo esc_attr( GM_OTP_LOGO_OPTION ); ?>" id="gm_otp_logo_url" value="<?php echo esc_attr( $current ); ?>" />
			<img id="gm_otp_logo_preview" src="<?php echo esc_url( $current ); ?>" style="max-width:200px;max-height:80px;display:<?php echo $current ? 'block' : 'none'; ?>;margin-bottom:8px;" />
			<p>
				<button type="button" class="button" id="gm_otp_logo_pick"><?php esc_html_e( 'Choose image' ); ?></button>
				<button type="button" class="button" id="gm_otp_logo_clear"><?php esc_html_e( 'Remove' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Replaces the default WordPress logo on all login screens (leave empty to keep the default).' ); ?></p>
		</td>
	</tr>
	<?php
}

/**
 * Shared "exceptions" rows (role + user multi-selects), for both settings
 * pages. Anyone matching either selection skips the OTP step entirely.
 */
function gm_otp_render_exemptions_field( $selected_roles, $selected_users ) {
	$selected_roles = (array) $selected_roles;
	$selected_users = (array) $selected_users;
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Exemptions' ); ?></th>
		<td>
			<div style="display:flex;gap:32px;flex-wrap:wrap;align-items:flex-start;">
				<div>
					<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Exempt roles' ); ?></label>
					<div style="width:320px;max-height:180px;overflow-y:auto;border:1px solid #8c8f94;padding:8px;background:#fff;">
						<?php foreach ( wp_roles()->get_names() as $slug => $label ) : ?>
							<label style="display:block;padding:2px 0;">
								<input type="checkbox" name="<?php echo esc_attr( GM_OTP_EXEMPT_ROLES_OPTION ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_roles, true ) ); ?> />
								<?php echo esc_html( translate_user_role( $label ) ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="description" style="max-width:320px;"><?php esc_html_e( 'Users with any of these roles never get an OTP prompt.' ); ?></p>
				</div>
				<div>
					<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Exempt users' ); ?></label>
					<input type="text" id="gm_otp_exempt_users_filter" placeholder="<?php esc_attr_e( 'Filter…' ); ?>" style="width:320px;display:block;margin-bottom:6px;" />
					<div style="width:320px;max-height:220px;overflow-y:auto;border:1px solid #8c8f94;padding:8px;background:#fff;">
						<?php
						$users = get_users( array(
							'fields'  => array( 'ID', 'user_login', 'user_email' ),
							'orderby' => 'user_login',
							'number'  => 500,
							// get_users() defaults to only users with a role on the
							// *current* site; blog_id=0 lists every user across the
							// whole network's shared wp_users table instead.
							'blog_id' => is_multisite() ? 0 : get_current_blog_id(),
						) );
						foreach ( $users as $u ) :
							$text = $u->user_login . ' (' . $u->user_email . ')';
							?>
							<label class="gm-otp-user-row" data-search="<?php echo esc_attr( strtolower( $text ) ); ?>" style="display:block;padding:2px 0;">
								<input type="checkbox" name="<?php echo esc_attr( GM_OTP_EXEMPT_USERS_OPTION ); ?>[]" value="<?php echo esc_attr( $u->ID ); ?>" <?php checked( in_array( (int) $u->ID, $selected_users, true ) ); ?> />
								<?php echo esc_html( $text ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<script>
					document.getElementById('gm_otp_exempt_users_filter').addEventListener('input', function (e) {
						var q = e.target.value.toLowerCase();
						document.querySelectorAll('.gm-otp-user-row').forEach(function (row) {
							row.style.display = row.dataset.search.indexOf(q) === -1 ? 'none' : 'block';
						});
					});
					</script>
					<p class="description" style="max-width:320px;"><?php esc_html_e( 'These specific users never get an OTP prompt, regardless of role.' ); ?></p>
					<?php if ( count( $users ) >= 500 ) : ?>
						<p class="description" style="color:#d63638;max-width:320px;"><?php esc_html_e( 'Showing first 500 users only — use role exemptions for larger sets.' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Sends a real test email via wp_mail() to the current admin's address, so
 * "email delivery works" is verified rather than just self-reported. Called
 * from both settings pages when their "Send Test Email" button is used.
 */
function gm_otp_maybe_send_test_email() {
	if ( empty( $_POST['gm_otp_send_test_email'] ) ) {
		return null; // some other button in the same form was clicked (e.g. Save Changes)
	}
	if ( ! wp_verify_nonce( gm_otp_input( $_POST, 'gm_otp_test_email_nonce' ), 'gm_otp_test_email' ) ) {
		return null;
	}
	$to  = get_option( 'admin_email' );
	$sent = wp_mail(
		$to,
		__( 'GM OTP test email' ),
		__( 'If you received this, email delivery is working on this site. You can now enable OTP login.' )
	);
	gm_otp_log( 'test email ' . ( $sent ? 'sent' : 'FAILED to send' ) . " to {$to}" );
	return array( 'sent' => $sent, 'to' => $to );
}

/**
 * Shared "Enable OTP login" row: requires an explicit, separate confirmation
 * that email delivery actually works before the checkbox is allowed to take
 * effect — self-reported via the checkbox, but backed by a real "Send Test
 * Email" button so it's not just a blind checkbox.
 */
function gm_otp_render_enable_field( $enabled, $smtp_confirmed, $test_result, $label, $lockout_ack = false ) {
	$smtp_plugin_file = 'smtp-mailer/main.php';
	$smtp_installed   = file_exists( WP_PLUGIN_DIR . '/' . $smtp_plugin_file );
	$smtp_active      = $smtp_installed && function_exists( 'is_plugin_active' ) && is_plugin_active( $smtp_plugin_file );

	if ( $smtp_active ) {
		$smtp_link_url   = admin_url( 'options-general.php?page=smtp-mailer-settings' );
		$smtp_link_label = __( 'Configure SMTP Mailer' );
	} elseif ( $smtp_installed ) {
		$smtp_link_url   = admin_url( 'plugins.php' );
		$smtp_link_label = __( 'Activate SMTP Mailer' );
	} else {
		$smtp_link_url   = 'https://wordpress.org/plugins/smtp-mailer/';
		$smtp_link_label = __( 'Get SMTP Mailer' );
	}
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Activation' ); ?></th>
		<td>
			<div class="notice notice-warning inline" style="margin:0 0 12px;padding:8px 12px;">
				<p>
					<strong><?php esc_html_e( 'This requires working outgoing email.' ); ?></strong>
					<?php esc_html_e( 'Without SMTP configured, codes will silently fail to send and users will be locked out of their own accounts.' ); ?>
					<a href="<?php echo esc_url( $smtp_link_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $smtp_link_label ); ?></a>
				</p>
			</div>
			<div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;">
				<div style="flex:1;min-width:260px;">
					<p style="font-weight:600;margin:0 0 6px;"><?php esc_html_e( 'Enable OTP login' ); ?></p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( GM_OTP_OPTION ); ?>" id="gm_otp_enable_checkbox" value="1" <?php checked( $enabled ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				</div>
				<div style="flex:1;min-width:260px;">
					<p style="font-weight:600;margin:0 0 6px;"><?php esc_html_e( 'Email delivery confirmed' ); ?></p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( GM_OTP_SMTP_CONFIRMED_OPTION ); ?>" id="gm_otp_smtp_confirmed_checkbox" value="1" <?php checked( $smtp_confirmed ); ?> />
						<?php esc_html_e( 'I have sent a test email and confirmed it arrived — email/SMTP delivery works on this site.' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Required before "Enable OTP login" takes effect — otherwise users get locked out waiting for a code that never arrives.' ); ?></p>
					<p>
						<button type="submit" name="gm_otp_send_test_email" value="1" class="button"><?php esc_html_e( 'Send Test Email' ); ?></button>
						<?php wp_nonce_field( 'gm_otp_test_email', 'gm_otp_test_email_nonce', false ); ?>
					</p>
					<?php if ( null !== $test_result ) : ?>
						<?php if ( $test_result['sent'] ) : ?>
							<p><span style="color:#008a20;">&#10003;</span> <?php printf( esc_html__( 'wp_mail() reported success sending to %s — check that inbox to be sure it really arrived (and isn\'t in spam).' ), esc_html( $test_result['to'] ) ); ?></p>
						<?php else : ?>
							<p><span style="color:#d63638;">&#10007;</span> <?php esc_html_e( 'wp_mail() reported failure. Email is NOT working — do not enable OTP login until this is fixed (install/configure an SMTP plugin).' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<div style="flex:1;min-width:260px;">
					<p style="font-weight:600;margin:0 0 6px;"><?php esc_html_e( 'Lockout risk acknowledged' ); ?></p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( GM_OTP_LOCKOUT_ACK_OPTION ); ?>" id="gm_otp_lockout_ack_checkbox" value="1" <?php checked( $lockout_ack ); ?> />
						<?php esc_html_e( 'I am aware that enabling OTP login could lock me out of the site (e.g. if email delivery stops working), and I have a recovery plan such as file-system/FTP/hosting access to rename or delete this plugin, or database access to disable it.' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Required before "Enable OTP login" takes effect. If you ever get locked out, deleting or renaming the gm-otp plugin folder via FTP/SSH/hosting file manager disables OTP and restores normal login.' ); ?></p>
				</div>
			</div>
			<script>
			document.getElementById( 'gm_otp_enable_checkbox' ).addEventListener( 'change', function ( e ) {
				if ( e.target.checked && ! confirm( <?php echo wp_json_encode( __( "Have you set up SMTP and confirmed a test email actually arrives?\n\nWithout it, users who log in will never receive their code and will be locked out.\n\nClick OK only if email delivery is already working." ) ); ?> ) ) {
					e.target.checked = false;
				}
			} );
			</script>
		</td>
	</tr>
	<script>
	// The main "Save Changes" button stays disabled (grey) until BOTH gates
	// are checked — "Email delivery confirmed" and "Lockout risk acknowledged"
	// — then becomes the normal primary (blue) button. (The "Send Test Email"
	// button is separate and stays clickable so email can be verified first.)
	//
	// Deferred to DOMContentLoaded on purpose: this <script> is emitted inside
	// the settings table, BEFORE submit_button() prints #submit later on the
	// page, so running immediately would find no button and silently do nothing.
	( function () {
		function init() {
			var ack  = document.getElementById( 'gm_otp_lockout_ack_checkbox' );
			var smtp = document.getElementById( 'gm_otp_smtp_confirmed_checkbox' );
			var save = document.getElementById( 'submit' );
			if ( ! save ) { return; }
			function sync() {
				save.disabled = ! ( ack && ack.checked && smtp && smtp.checked );
			}
			if ( ack ) { ack.addEventListener( 'change', sync ); }
			if ( smtp ) { smtp.addEventListener( 'change', sync ); }
			sync();
		}
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	} )();
	</script>
	<?php
}

/**
 * Network Admin > Settings > GM OTP — the network-wide switch.
 * update_site_option() has no options.php equivalent, so this page
 * handles its own form submission instead of using the Settings API.
 */
add_action( 'network_admin_menu', function () {
	add_submenu_page( 'settings.php', 'GM OTP', 'GM OTP', 'manage_network_options', 'gm-otp-network', 'gm_otp_render_network_settings_page' );
} );

function gm_otp_render_network_settings_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	$test_result = gm_otp_maybe_send_test_email();

	if ( wp_verify_nonce( gm_otp_input( $_POST, 'gm_otp_network_nonce' ), 'gm_otp_network_save' ) ) {
		$smtp_confirmed = ! empty( $_POST[ GM_OTP_SMTP_CONFIRMED_OPTION ] );
		$lockout_ack    = ! empty( $_POST[ GM_OTP_LOCKOUT_ACK_OPTION ] );
		$wants_enabled  = ! empty( $_POST[ GM_OTP_OPTION ] );

		if ( $wants_enabled && ! $smtp_confirmed ) {
			$wants_enabled = false;
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Not enabled: you must confirm email delivery works (send a test email and check the box below) before OTP login can be turned on.' ) . '</p></div>';
		}

		if ( $wants_enabled && ! $lockout_ack ) {
			$wants_enabled = false;
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Not enabled: you must acknowledge the lockout risk (check the recovery-awareness box below) before OTP login can be turned on.' ) . '</p></div>';
		}

		update_site_option( GM_OTP_OPTION, $wants_enabled );
		update_site_option( GM_OTP_SMTP_CONFIRMED_OPTION, $smtp_confirmed );
		update_site_option( GM_OTP_LOCKOUT_ACK_OPTION, $lockout_ack );
		update_site_option( GM_OTP_MAX_ATTEMPTS_OPTION, max( 1, absint( $_POST[ GM_OTP_MAX_ATTEMPTS_OPTION ] ?? 3 ) ) );
		update_site_option( GM_OTP_LOCKOUT_MINUTES_OPTION, max( 1, absint( $_POST[ GM_OTP_LOCKOUT_MINUTES_OPTION ] ?? 15 ) ) );
		update_site_option( GM_OTP_LOGO_OPTION, isset( $_POST[ GM_OTP_LOGO_OPTION ] ) ? sanitize_url( wp_unslash( $_POST[ GM_OTP_LOGO_OPTION ] ) ) : '' );
		// gm_otp_sanitize_exempt_roles()/_users() sanitize every element (sanitize_key()/absint()) internally.
		update_site_option( GM_OTP_EXEMPT_ROLES_OPTION, gm_otp_sanitize_exempt_roles( wp_unslash( $_POST[ GM_OTP_EXEMPT_ROLES_OPTION ] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_site_option( GM_OTP_EXEMPT_USERS_OPTION, gm_otp_sanitize_exempt_users( wp_unslash( $_POST[ GM_OTP_EXEMPT_USERS_OPTION ] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.' ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1>GM OTP — <?php esc_html_e( 'Network Settings' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: version, 2: build timestamp */
				esc_html__( 'Version %1$s — updated %2$s' ),
				esc_html( GM_OTP_VERSION ),
				esc_html( GM_OTP_BUILD_TIME )
			);
			?>
		</p>
		<p><?php esc_html_e( 'Applies to every site on this network.' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'gm_otp_network_save', 'gm_otp_network_nonce' ); ?>
			<table class="form-table">
				<?php
				gm_otp_render_enable_field(
					gm_otp_option( GM_OTP_OPTION ),
					gm_otp_option( GM_OTP_SMTP_CONFIRMED_OPTION ),
					$test_result,
					__( 'Require email code after password on login, on every site' ),
					gm_otp_option( GM_OTP_LOCKOUT_ACK_OPTION )
				);
				?>
				<?php gm_otp_render_attempts_row( gm_otp_option( GM_OTP_MAX_ATTEMPTS_OPTION, 3 ), gm_otp_option( GM_OTP_LOCKOUT_MINUTES_OPTION, 15 ) ); ?>
				<?php gm_otp_render_logo_field( gm_otp_option( GM_OTP_LOGO_OPTION, '' ) ); ?>
				<?php gm_otp_render_exemptions_field( gm_otp_option( GM_OTP_EXEMPT_ROLES_OPTION, array() ), gm_otp_option( GM_OTP_EXEMPT_USERS_OPTION, array() ) ); ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr />
		<?php gm_otp_render_log_viewer( network_admin_url( 'settings.php?page=gm-otp-network' ) ); ?>
	</div>
	<?php
}

/**
 * Runs after core's wp_authenticate_username_password (priority 20).
 * $user is either a WP_User (creds ok) or WP_Error (creds bad).
 *
 * The code field lives directly on the login form (rendered by
 * gm_otp_render_field(), hooked on 'login_form') rather than a separate
 * page/action — needed for compatibility with AJAX-based login flows
 * (e.g. Wordfence Login Security posts to admin-ajax.php, which broke the
 * old raw-HTML redirect page entirely). On success we simply return the
 * WP_User: core's own wp_signon() sets the auth cookie, fires 'wp_login',
 * and redirects — we don't need to touch any of that ourselves.
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
		gm_otp_log( 'skipped: gm_otp_enabled is on but email delivery was never confirmed in settings — refusing to risk locking users out' );
		return $user;
	}

	// Some security plugins (Wordfence Login Security's own captcha is the
	// known case, error code "wfls_captcha_verify") run their own check
	// *before* this filter and reject the login outright if it fails —
	// independent of whether the username/password were actually correct.
	// Since GM OTP is already acting as the second factor here, a second,
	// broken captcha in front of it just locks real users out. Re-verify
	// the credentials directly against core; if they're genuinely valid,
	// proceed with our own OTP flow instead of honoring that rejection.
	if ( is_wp_error( $user ) && 'wfls_captcha_verify' === $user->get_error_code() ) {
		gm_otp_log( "Wordfence captcha rejected username='{$username}' (wfls_captcha_verify) — re-checking credentials directly against core, bypassing that check" );
		$real_user = wp_authenticate_username_password( null, $username, $password );
		if ( $real_user instanceof WP_User ) {
			gm_otp_log( "credentials for '{$username}' are genuinely valid — proceeding despite the captcha rejection" );
			$user = $real_user;
		} else {
			gm_otp_log( "credentials for '{$username}' are also invalid on their own — leaving the original rejection in place" );
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

	// Two-phase AJAX login handling. Some security plugins (Wordfence Login
	// Security's reCAPTCHA is the known case) authenticate ONCE over
	// admin-ajax.php to pre-validate credentials, then submit the real login
	// form to wp-login.php — running this filter TWICE for a single login. If
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
			gm_otp_log( "user_id={$user->ID}: consuming OTP grace token — already passed OTP in this login's AJAX phase, skipping the second demand" );
			delete_transient( 'gm_otp_grace_' . $user->ID );
			setcookie( 'gm_otp_grace', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
			return $user;
		}
	}

	// Password is genuinely correct on THIS submission — now see if there's
	// a pending code (from an earlier submission) for this SAME user. Only
	// ever honored when the cookie's stored user_id matches $user->ID, so a
	// valid password for user A can never consume user B's pending code.
	$token  = isset( $_COOKIE[ GM_OTP_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GM_OTP_COOKIE ] ) ) : '';
	$stored = $token ? get_transient( 'gm_otp_' . $token ) : false;
	if ( $stored && (int) $stored['user_id'] !== (int) $user->ID ) {
		$stored = false; // stale/foreign cookie — ignore it, start fresh below
	}

	if ( gm_otp_get_lockout( $user->ID ) ) {
		if ( $token ) {
			gm_otp_clear_pending( $token );
		}
		gm_otp_log( "blocked: user_id={$user->ID} is locked out" );
		return new WP_Error( 'gm_otp_locked', gm_otp_lockout_message( $user->ID ) );
	}

	if ( ! $stored ) {
		return gm_otp_start_new_code( $user );
	}

	if ( ! empty( $_POST['gm_otp_resend'] ) ) {
		return gm_otp_resend_code( $token, $stored, $user );
	}

	$submitted = isset( $_POST['gm_otp_code'] ) ? preg_replace( '/\D/', '', gm_otp_input( $_POST, 'gm_otp_code' ) ) : '';

	if ( '' === $submitted ) {
		// Code field wasn't filled in on this submission — just re-show
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
			gm_otp_log( "user_id={$user->ID}: correct code during AJAX phase — minted grace token for the follow-up wp-login.php POST" );
		}

		gm_otp_log( "user_id={$user->ID} entered correct code — login proceeding" );
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
		gm_otp_log( "user_id={$user->ID} hit max wrong-code attempts — locked out for {$lockout_mins}m" );
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
 * script polls for. It only signals "a code is pending, reveal the field" —
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
 * username/password — hooked on 'login_form', so it lives INSIDE the login
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
 * $GLOBALS['gm_otp_pending'] set and the field is already visible — the
 * cookie-poll path is simply never needed there.
 */
add_action( 'login_form', 'gm_otp_render_field' );
function gm_otp_render_field() {
	$pending = null;

	if ( isset( $GLOBALS['gm_otp_pending'] ) ) {
		$pending = $GLOBALS['gm_otp_pending'];
	} else {
		$token  = isset( $_COOKIE[ GM_OTP_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GM_OTP_COOKIE ] ) ) : '';
		$stored = $token ? get_transient( 'gm_otp_' . $token ) : false;
		if ( $stored ) {
			$pending_user = get_userdata( $stored['user_id'] );
			if ( $pending_user ) {
				$pending = array(
					'masked_email' => gm_otp_mask_email( $pending_user->user_email ),
					'seconds_left' => max( 0, GM_OTP_RESEND_WAIT - ( time() - (int) $stored['last_sent'] ) ),
				);
			}
		}
	}

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
			<?php // Hidden flag, not the button's own name — button values aren't reliably serialized by AJAX login submitters. ?>
			<input type="hidden" name="gm_otp_resend" id="gm_otp_resend_flag" value="" />
			<?php // type="button", NOT submit — otherwise pressing Enter in the code field would fire this (the first submit button) and resend instead of logging in. ?>
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
