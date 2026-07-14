<?php
/**
 * GM OTP — core: logging, log viewer, option/exemption helpers (split out of gm-otp.php for maintainability).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logging. Writes to wp-content/gm-otp-logs/otp.log (outside the plugin
 * folder so it survives updates) with an .htaccess/index.php pair so the
 * file can't be requested directly over HTTP â€” it can contain email
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
 * Fires the instant this file is parsed â€” before plugins_loaded, before any
 * of our own hooks run. If a login attempt never produces even this line,
 * the plugin file itself isn't executing for that request (wrong site,
 * not actually active there, or something fataled earlier in bootstrap) â€”
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
	// being requested, THIS fires instead of login_form_gm_otp â€” proving
	// core's own validity check rejected our action for this request.
	add_action( 'login_form_login', function () {
		gm_otp_log( sprintf(
			'login_form_login fired (fallback) â€” GET[action] at this point=%s, REQUEST[action]=%s',
			gm_otp_input( $_GET, 'action', '(unset)' ),
			gm_otp_input( $_REQUEST, 'action', '(unset)' )
		) );
	}, 1 );
}

function gm_otp_read_log_tail( $max_bytes = 50000 ) {
	if ( ! file_exists( GM_OTP_LOG_FILE ) ) {
		return '';
	}
	// file_get_contents() + substr() instead of fopen()/fseek()/fread() â€”
	// avoids the low-level file handle entirely for what's just a tail read.
	$contents = file_get_contents( GM_OTP_LOG_FILE );
	return strlen( $contents ) > $max_bytes ? substr( $contents, -$max_bytes ) : $contents;
}

/**
 * ini_get('error_log') is often empty even though PHP errors are landing
 * *somewhere* on disk (PHP-FPM/Apache/Nginx frequently have their own
 * error log independent of PHP's own setting). Rather than give up, probe
 * the handful of paths that cover the vast majority of real hosting setups
 * â€” first one that's actually readable from this PHP process wins.
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
 * Tail whichever file gm_otp_find_php_error_log() locates â€” no FTP/SSH/
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

	if ( wp_verify_nonce( gm_otp_input( $_POST, 'gm_otp_clear_php_log_nonce' ), 'gm_otp_clear_php_log' ) ) {
		$php_log_path = gm_otp_find_php_error_log();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( $php_log_path && is_writable( $php_log_path ) ) {
			file_put_contents( $php_log_path, '' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'PHP error log cleared.' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not clear the PHP error log â€” none found, or the file is not writable by PHP (it may be a shared system log). Clear it via SSH/hosting panel instead.' ) . '</p></div>';
		}
	}

	$show_log  = ! empty( $_GET['gm_otp_view_log'] );
	$check_dir = file_exists( GM_OTP_LOG_DIR ) ? GM_OTP_LOG_DIR : WP_CONTENT_DIR;
	// Read-only diagnostic display, not a file modification â€” WP_Filesystem is unnecessary here.
	$writable = is_writable( $check_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	?>
	<div style="display:flex;gap:40px;flex-wrap:wrap;align-items:flex-start;">
	<div style="flex:1;min-width:340px;">
	<h2><?php esc_html_e( 'Log' ); ?></h2>
	<p>
		<?php if ( $writable ) : ?>
			<span style="color:#008a20;">&#10003;</span> <?php esc_html_e( 'Log directory is writable â€” logging should work.' ); ?>
		<?php else : ?>
			<span style="color:#d63638;">&#10007;</span>
			<?php
			printf(
				/* translators: %s: log directory path */
				esc_html__( 'NOT writable: %s â€” file logging will silently fail here. Check error_log() / your host\'s PHP error log instead.' ),
				'<code>' . esc_html( GM_OTP_LOG_DIR ) . '</code>'
			);
			?>
		<?php endif; ?>
	</p>
	<p style="display:flex;gap:8px;align-items:center;">
		<a href="<?php echo esc_url( add_query_arg( 'gm_otp_view_log', '1', $base_url ) ); ?>" class="button">
			<?php esc_html_e( 'View Log' ); ?>
		</a>
		<button type="submit" form="gm_otp_clear_log_form" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Clear the GM OTP log?' ) ); ?>');">
			<?php esc_html_e( 'Clear Log' ); ?>
		</button>
	</p>
	<?php // Hidden form the always-visible "Clear Log" button submits (nonce is verified at the top of this function). ?>
	<form id="gm_otp_clear_log_form" method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:none;">
		<?php wp_nonce_field( 'gm_otp_clear_log', 'gm_otp_clear_log_nonce' ); ?>
	</form>
	<?php if ( $show_log ) : ?>
		<p>
			<textarea readonly rows="20" style="width:100%;max-width:900px;font-family:monospace;font-size:12px;" onclick="this.select()"><?php
				$log = gm_otp_read_log_tail();
				echo esc_textarea( $log ? $log : __( '(log is empty)' ) );
			?></textarea>
		</p>
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
				echo ' ' . esc_html__( '(not the one PHP itself is configured to use â€” found by checking common hosting paths instead).' );
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
			'<code>' . esc_html( $configured_path ? $configured_path : '(not set â€” logging to stderr/syslog, not a file)' ) . '</code>'
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
	<p style="display:flex;gap:8px;align-items:center;">
		<a href="<?php echo esc_url( add_query_arg( 'gm_otp_view_php_log', '1', $base_url ) ); ?>" class="button">
			<?php esc_html_e( 'View PHP Error Log' ); ?>
		</a>
		<button type="submit" form="gm_otp_clear_php_log_form" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Clear the PHP error log? (only works if PHP can write to it)' ) ); ?>');">
			<?php esc_html_e( 'Clear PHP Error Log' ); ?>
		</button>
	</p>
	<form id="gm_otp_clear_php_log_form" method="post" action="<?php echo esc_url( $base_url ); ?>" style="display:none;">
		<?php wp_nonce_field( 'gm_otp_clear_php_log', 'gm_otp_clear_php_log_nonce' ); ?>
	</form>
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
 * True if $user should skip OTP entirely â€” either their user ID or any of
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