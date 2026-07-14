<?php
/**
 * GM OTP — admin: settings pages, menu, action links, field renderers (split out of gm-otp.php for maintainability).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page: available both as a top-level admin menu item and under
 * Settings > GM OTP (same slug/callback, so both entries open the same page).
 * On multisite this becomes read-only, pointing at the network settings
 * page instead â€” the network switch is what actually gates the feature.
 */
add_action( 'admin_menu', function () {
	add_menu_page( 'GM OTP', 'GM OTP', 'manage_options', 'gm-otp', 'gm_otp_render_settings_page', 'dashicons-lock', 80 );
} );

/**
 * "Settings" link next to Deactivate on the Plugins list, like other plugins.
 * On multisite the network Plugins screen points at the network settings page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( GM_OTP_PLUGIN_FILE ), function ( $links ) {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=gm-otp' ) ) . '">' . esc_html__( 'Settings' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
} );

add_filter( 'network_admin_plugin_action_links_' . plugin_basename( GM_OTP_PLUGIN_FILE ), function ( $links ) {
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
 * Shared "Max attempts" + "Lockout duration" row â€” the two live side by side
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
					<input type="text" id="gm_otp_exempt_users_filter" placeholder="<?php esc_attr_e( 'Filterâ€¦' ); ?>" style="width:320px;display:block;margin-bottom:6px;" />
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
						<p class="description" style="color:#d63638;max-width:320px;"><?php esc_html_e( 'Showing first 500 users only â€” use role exemptions for larger sets.' ); ?></p>
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
 * effect â€” self-reported via the checkbox, but backed by a real "Send Test
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
						<?php esc_html_e( 'I have sent a test email and confirmed it arrived â€” email/SMTP delivery works on this site.' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Required before "Enable OTP login" takes effect â€” otherwise users get locked out waiting for a code that never arrives.' ); ?></p>
					<p>
						<button type="submit" name="gm_otp_send_test_email" value="1" class="button"><?php esc_html_e( 'Send Test Email' ); ?></button>
						<?php wp_nonce_field( 'gm_otp_test_email', 'gm_otp_test_email_nonce', false ); ?>
					</p>
					<?php if ( null !== $test_result ) : ?>
						<?php if ( $test_result['sent'] ) : ?>
							<p><span style="color:#008a20;">&#10003;</span> <?php printf( esc_html__( 'wp_mail() reported success sending to %s â€” check that inbox to be sure it really arrived (and isn\'t in spam).' ), esc_html( $test_result['to'] ) ); ?></p>
						<?php else : ?>
							<p><span style="color:#d63638;">&#10007;</span> <?php esc_html_e( 'wp_mail() reported failure. Email is NOT working â€” do not enable OTP login until this is fixed (install/configure an SMTP plugin).' ); ?></p>
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
	// are checked â€” "Email delivery confirmed" and "Lockout risk acknowledged"
	// â€” then becomes the normal primary (blue) button. (The "Send Test Email"
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
 * Network Admin > Settings > GM OTP â€” the network-wide switch.
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
		<h1>GM OTP â€” <?php esc_html_e( 'Network Settings' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: version, 2: build timestamp */
				esc_html__( 'Version %1$s â€” updated %2$s' ),
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