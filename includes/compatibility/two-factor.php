<?php
/**
 * Two Factor Authentication Compatibility
 * 
 * Integrates the Two Factor plugin into Paid Memberships Pro's frontend edit profile page.
 * This allows members to configure their two-factor authentication settings directly from
 * the frontend member profile edit page.
 * 
 * Requires: Two Factor plugin (https://wordpress.org/plugins/two-factor/)
 * 
 * @since TBD
 * @package Paid_Memberships_Pro
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load necessary WordPress admin files for Two Factor on the frontend.
 * 
 * The Two Factor plugin uses WP_List_Table and other admin classes that are not
 * automatically loaded on the frontend. This function ensures those dependencies
 * are available when displaying Two Factor options on the frontend profile page.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_load_admin_dependencies() {
	// Only load on frontend when not in admin.
	if ( is_admin() ) {
		return;
	}

	// Check if we're on a page that might display the Two Factor section.
	// This prevents loading unnecessary files on every frontend page.
	global $post;
	if ( empty( $post ) || ( ! has_shortcode( $post->post_content, 'pmpro_member_profile_edit' ) && ! has_block( 'pmpro/member-profile-edit' ) ) ) {
		return;
	}

	// CRITICAL: Set up a proper screen object BEFORE loading any admin files.
	// This prevents is_admin() from being called with an invalid $current_screen.
	global $current_screen;
	if ( empty( $current_screen ) || ! is_object( $current_screen ) || ! method_exists( $current_screen, 'in_admin' ) ) {
		// Create a minimal but functional WP_Screen-like object.
		// We need to do this before loading screen.php to prevent issues.
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		$current_screen = WP_Screen::get( 'profile' );
	}

	// Load the WP_List_Table class if it doesn't exist.
	// This is required by the FIDO U2F admin interface.
	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	// Load the WP_Screen functions if not available.
	// WP_List_Table uses get_current_screen() which requires these functions.
	if ( ! function_exists( 'get_current_screen' ) ) {
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}

	// Load the template functions which might be needed by some Two Factor providers.
	if ( ! function_exists( 'submit_button' ) ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}
}
add_action( 'wp', 'pmpro_two_factor_load_admin_dependencies', 1 );

/**
 * Display the Two Factor Authentication section on the frontend edit profile page.
 * 
 * This function hooks into PMPro's 'pmpro_show_user_profile' action to add a complete
 * Two Factor configuration section to the member profile edit form. It outputs a properly
 * styled fieldset that matches PMPro's design system and includes all Two Factor options.
 * 
 * @since TBD
 * 
 * @param WP_User $user The user object being displayed.
 * 
 * @return void
 */
function pmpro_two_factor_display_section( $user ) {
	// Verify that the Two Factor plugin is active and the core class exists.
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}

	// Make sure we have a valid user object.
	if ( ! $user || ! $user->exists() ) {
		return;
	}

	// Only show for the current user or users who can edit this user.
	if ( get_current_user_id() !== $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}
	?>

	<fieldset id="pmpro_member_profile_edit-two-factor" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset', 'pmpro_member_profile_edit-two-factor' ) ); ?>">
		<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
			
			<?php
		// The $current_screen global should already be set up by our dependency loader.
		// Just ensure it's still valid before calling Two Factor.
		global $current_screen;
		$original_screen = $current_screen;
		
		if ( ! is_admin() && ( empty( $current_screen ) || ! method_exists( $current_screen, 'in_admin' ) ) ) {
			// This shouldn't happen if our dependency loader ran, but as a safety fallback:
			if ( class_exists( 'WP_Screen' ) ) {
				$current_screen = WP_Screen::get( 'profile' );
			}
		}

		// Output the Two Factor plugin's user options.
		// This includes all provider checkboxes, configuration options, and primary method selection.
		Two_Factor_Core::user_two_factor_options( $user );

		// Restore the original screen object if we're on frontend.
		if ( ! is_admin() && $current_screen !== $original_screen ) {
			$current_screen = $original_screen;
		}
			?>
			
		</div> <!-- end pmpro_form_fields -->
	</fieldset> <!-- end pmpro_member_profile_edit-two-factor -->

	<?php
}
add_action( 'pmpro_show_user_profile', 'pmpro_two_factor_display_section', 20 );

/**
 * Save Two Factor Authentication settings when the frontend profile form is submitted.
 * 
 * This function hooks into PMPro's 'pmpro_personal_options_update' action to process
 * Two Factor settings when a user updates their profile. It delegates all validation
 * and saving logic to the Two Factor plugin's core functions.
 * 
 * @since TBD
 * 
 * @param int $user_id The ID of the user being updated.
 * 
 * @return void
 */
function pmpro_two_factor_save_settings( $user_id ) {
	// Verify that the Two Factor plugin is active and the core class exists.
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}

	// Make sure we have a valid user ID.
	if ( empty( $user_id ) ) {
		return;
	}

	// Check if the Two Factor form was actually submitted.
	// The Two Factor plugin uses its own nonce for security.
	if ( ! isset( $_POST['_nonce_user_two_factor_options'] ) ) {
		return;
	}

	// Let the Two Factor plugin handle all validation, nonce verification, and saving.
	// This ensures we maintain the plugin's security model and don't duplicate logic.
	Two_Factor_Core::user_two_factor_options_update( $user_id );
}
add_action( 'pmpro_personal_options_update', 'pmpro_two_factor_save_settings' );

/**
 * Enqueue necessary styles and scripts for Two Factor on the frontend edit profile page.
 * 
 * The Two Factor plugin enqueues its own CSS when displaying user options, but this
 * function ensures assets are loaded at the right time and adds any custom styling
 * needed for frontend integration.
 * 
 * CRITICAL: This function also ensures REST API authentication is properly set up
 * for the backup codes generation feature, which uses wp.apiRequest.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_enqueue_assets() {
	// Verify that the Two Factor plugin is active.
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}

	// Only load on the member profile edit page.
	global $post;
	if ( ! is_user_logged_in() || empty( $post ) || ! has_shortcode( $post->post_content, 'pmpro_member_profile_edit' ) ) {
		return;
	}

	// CRITICAL: Ensure REST API authentication is available for wp.apiRequest.
	// The backup codes generation button uses wp.apiRequest which requires the REST API nonce.
	// Without this, the "Generate new recovery codes" button will fail silently.
	wp_enqueue_script( 'wp-api-request' );
	
	// Also ensure jQuery is loaded since the backup codes script requires it.
	wp_enqueue_script( 'jquery' );
}
add_action( 'wp_enqueue_scripts', 'pmpro_two_factor_enqueue_assets' );

/**
 * Add visual feedback for backup codes generation button.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_backup_codes_visual_feedback() {
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}

	global $post;
	if ( ! is_user_logged_in() || empty( $post ) || ! has_shortcode( $post->post_content, 'pmpro_member_profile_edit' ) ) {
		return;
	}
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		// Use event delegation to catch clicks even if handlers are added later
		$(document).on('click', '.button-two-factor-backup-codes-generate', function(e) {
			e.preventDefault();
			e.stopImmediatePropagation(); // Stop other handlers from running
						
			// Check if wp.apiRequest is available
			if (typeof wp === 'undefined' || typeof wp.apiRequest === 'undefined') {
				console.error('wp.apiRequest is not available. Make sure wp-api-request script is loaded.');
				alert('Error: Unable to generate codes. Please refresh the page and try again.');
				return false;
			}
			
			var $btn = $(this);
			var originalText = $btn.text();
			
			$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Generating...', 'paid-memberships-pro' ) ); ?>');
						
			wp.apiRequest({
				method: 'POST',
				path: '<?php echo Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes'; ?>',
				data: { user_id: <?php echo get_current_user_id(); ?> }
			}).done(function(response) {
				
				var $codesList = $('.two-factor-backup-codes-unused-codes');
				$('.two-factor-backup-codes-wrapper').show();
				$codesList.html('');
				for (var i = 0; i < response.codes.length; i++) {
					$codesList.append('<li>' + response.codes[i] + '</li>');
				}
				$('.two-factor-backup-codes-count').html(response.i18n.count);
				$('#two-factor-backup-codes-download-link').attr('href', response.download_link);
				
				$btn.text('<?php echo esc_js( __( 'Generated!', 'paid-memberships-pro' ) ); ?>');
				setTimeout(function() {
					$btn.text(originalText).prop('disabled', false);
				}, 2000);
			}).fail(function(error) {
				console.error('PMPro 2FA: Failed to generate backup codes', error);
				
				$btn.text('<?php echo esc_js( __( 'Failed - Try Again', 'paid-memberships-pro' ) ); ?>');
				setTimeout(function() {
					$btn.text(originalText).prop('disabled', false);
				}, 3000);
			});
			
			return false;
		});
		
	});
	</script>
	<?php
}
add_action( 'wp_footer', 'pmpro_two_factor_backup_codes_visual_feedback' );

/**
 * Style Two-Factor notices to match PMPro styling on the frontend.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_style_notices() {
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}

	global $post;
	if ( ! is_user_logged_in() || empty( $post ) || ! has_shortcode( $post->post_content, 'pmpro_member_profile_edit' ) ) {
		return;
	}
	?>
	<style type="text/css">
		/* Style Two-Factor notices to match PMPro's error/warning styles */
		#two-factor-options .notice,
		#two-factor-options .notice.inline {
			background: #fff;
			border-left: 4px solid;
			box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
			margin: 1em 0;
			padding: 12px;
		}
		
		#two-factor-options .notice.notice-warning,
		#two-factor-options .notice-warning {
			border-left-color: #f0b849;
		}
		
		#two-factor-options .notice.notice-error,
		#two-factor-options .notice-error {
			border-left-color: #dc3232;
		}
		
		#two-factor-options .notice.notice-info,
		#two-factor-options .notice-info {
			border-left-color: #00a0d2;
		}
		
		#two-factor-options .notice p {
			margin: 0.5em 0;
		}
		
		#two-factor-options .notice p:first-child {
			margin-top: 0;
		}
		
		#two-factor-options .notice p:last-child {
			margin-bottom: 0;
		}
		
		#two-factor-options .notice .button {
			margin-top: 0.5em;
		}
		
		/* Make the revalidation warning more prominent */
		.two-factor-warning-revalidate-session {
			border-left-color: #f0b849 !important;
			background: #fff8e5 !important;
		}
		
		.two-factor-warning-revalidate-session p {
			font-weight: 600;
			color: #8a6d3b;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'pmpro_two_factor_style_notices' );

/**
 * Allow users to update Two Factor options on the frontend without requiring revalidation.
 * 
 * When users are on the frontend member profile edit page, they've already authenticated
 * to access the page. The Two Factor plugin's revalidation requirement is designed for
 * the WordPress admin where sessions might be longer-lived. On the frontend profile page,
 * we can safely allow updates without forcing revalidation.
 * 
 * This specifically enables the "Generate new recovery codes" button to work properly.
 * 
 * @since TBD
 * 
 * @param bool $can_update Whether the user can update Two Factor options.
 * @param int  $user_id    The user ID being edited.
 * 
 * @return bool True to allow updates on the frontend profile page.
 */
function pmpro_two_factor_allow_frontend_updates( $can_update, $user_id ) {
	// Only modify the check if we're on the frontend (not in admin).
	if ( is_admin() ) {
		return $can_update;
	}
	
	// Check if we're on the member profile edit page.
	global $post;
	if ( empty( $post ) || ( ! has_shortcode( $post->post_content, 'pmpro_member_profile_edit' ) && ! has_block( 'pmpro/member-profile-edit' ) ) ) {
		return $can_update;
	}
	
	// Verify the user is logged in and editing their own profile or has permission.
	if ( ! is_user_logged_in() ) {
		return false;
	}
	
	$current_user_id = get_current_user_id();
	
	// Allow if editing own profile or if user can edit the specified user.
	if ( $current_user_id === $user_id || current_user_can( 'edit_user', $user_id ) ) {
		return true;
	}
	
	return $can_update;
}
add_filter( 'two_factor_rest_api_can_edit_user', 'pmpro_two_factor_allow_frontend_updates', 10, 2 );

/**
 * Show custom revalidation notice on PMPro frontend profile edit page.
 * 
 * Checks if the user needs to revalidate and displays a PMPro-styled message
 * with a link to the PMPro login page to complete revalidation.
 * 
 * @since TBD
 * 
 * @param WP_User $user The user object.
 * 
 * @return void
 */
function pmpro_two_factor_show_revalidation_notice( $user ) {
	if ( ! class_exists( 'Two_Factor_Core' ) ) {
		return;
	}
	
	// Check if user needs to revalidate.
	if ( Two_Factor_Core::current_user_can_update_two_factor_options( 'display' ) ) {
		return; // User is good, no revalidation needed
	}
	
	// Build revalidation URL pointing to PMPro login.
	$profile_edit_url = pmpro_url( 'member_profile_edit' );
	$redirect_to = $profile_edit_url . '#two-factor-options';
	
	$revalidate_url = add_query_arg(
		array(
			'action'      => 'revalidate_2fa',
			'redirect_to' => urlencode( $redirect_to ),
		),
		pmpro_login_url()
	);
	?>
	
	<div id="pmpro_two_factor_revalidate_notice" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_message pmpro_alert', 'pmpro_two_factor_revalidate_notice' ) ); ?>">
		<p>
			<?php esc_html_e( 'To update your Two-Factor Authentication settings, you need to verify your identity.', 'paid-memberships-pro' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( $revalidate_url ); ?>" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit' ) ); ?>">
				<?php esc_html_e( 'Verify Identity', 'paid-memberships-pro' ); ?>
			</a>
		</p>
	</div>
	<script>
		jQuery(document).ready(function(){
			// Move the revalidate notice pmpro_two_factor_revalidate_notice below the H2 inside #pmpro_member_profile_edit-two-factor
			jQuery('#pmpro_two_factor_revalidate_notice').insertAfter(jQuery('#pmpro_member_profile_edit-two-factor h2').first());

			// Delete the notice #pmpro_member_profile_edit-two-factor .two-factor-warning-revalidate-session
			jQuery('#pmpro_member_profile_edit-two-factor .two-factor-warning-revalidate-session').remove();

			// Make the buttons disabled.
			jQuery('.button-two-factor-backup-codes-generate').prop('disabled', true);
			jQuery('.reset-totp-key').prop('disabled', true);

			// Add styliing to indicate disabled state.
			jQuery('.button-two-factor-backup-codes-generate, .reset-totp-key').css({
				'opacity': '0.5',
				'cursor': 'not-allowed'
			});
		});
	</script>
	<?php
}
add_action( 'pmpro_show_user_profile', 'pmpro_two_factor_show_revalidation_notice', 15 );

/**
 * Check if current request is a revalidation action and valid.
 * 
 * @since TBD
 * 
 * @return bool True if this is a revalidation request.
 */
function pmpro_two_factor_is_revalidation_request() {
	return ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] === 'revalidate_2fa' && is_user_logged_in();
}

/**
 * Show revalidation form on the current page.
 * 
 * @since TBD
 * 
 * @return string Revalidation form HTML.
 */
function pmpro_two_factor_show_revalidation_form( $content ) {
	global $pmpro_pages;

	// Only override the content if we're on the PMPro login page and not another page.
	if ( ! is_page( $pmpro_pages['login'] ) ) {
		return $content;
	}

	if ( ! pmpro_two_factor_is_revalidation_request() ) {
		return $content;
	}
	
 	// Make sure the member doesn't need to revalidate. If they're okay, just return the original content.
	if ( Two_Factor_Core::current_user_can_update_two_factor_options( 'display' ) ) {
		return $content;
	}

	// Builds a custom form for revalidating Two Factor Authentication.
	$content = pmpro_two_factor_revalidate_handler();
	return $content;
}
add_filter( 'the_content', 'pmpro_two_factor_show_revalidation_form', 1 );

/**
 * Display revalidation form.
 * 
 * @since TBD
 * 
 * @return string Revalidation form HTML.
 */
function pmpro_two_factor_revalidate_handler() {
	// Must be logged in to revalidate.
	if ( ! is_user_logged_in() ) {
		return '';
	}
	
	// Get current user.
	$user = wp_get_current_user();

	// Get the provider.
	$provider_key = ! empty( $_GET['provider'] ) ? sanitize_text_field( $_GET['provider'] ) : null;
	$provider = Two_Factor_Core::get_provider_for_user( $user, $provider_key );
	
	// Validate provider exists.
	if ( ! $provider ) {
		$content = '<div id="pmpro-two-factor-invalid-provider" class="' . esc_attr( pmpro_get_element_class( 'pmpro_message pmpro_error' ) ) . '">';
		$content .= '<p>' . esc_html__( 'Invalid verification method.', 'paid-memberships-pro' ) . '</p>';
		$content .= '<p><a href="' . esc_url( pmpro_url( 'account' ) ) . '">' . esc_html__( 'Back to Account', 'paid-memberships-pro' ) . '</a></p>';
		$content .= '</div>';
		return $content;
	}
	
	// Get backup providers for "Having Problems?" section.
	$available_providers = Two_Factor_Core::get_available_providers_for_user( $user );
	$backup_providers = array_diff_key( $available_providers, array( $provider->get_key() => null ) );
	
	// Get error message if validation failed.
	$error_msg = ! empty( $_GET['error'] ) ? urldecode( sanitize_text_field( $_GET['error'] ) ) : '';
	
	// Get redirect destination.
	$redirect_to = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( $_GET['redirect_to'] ) : pmpro_url( 'member_profile_edit' );
	
	// Start output buffering.
	ob_start();
	?>
	<div class="pmpro">
		<div id="pmpro_two_factor_revalidate" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_section' ) ); ?>">
			<div class="pmpro_card">
			<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
				
				<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_title pmpro_font-large' ) ); ?>">
					<?php esc_html_e( 'Verify Your Identity', 'paid-memberships-pro' ); ?>
				</h2>
				
				<?php if ( ! empty( $error_msg ) ) : ?>
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_message pmpro_error' ) ); ?>">
						<?php echo esc_html( $error_msg ); ?>
					</div>
				<?php endif; ?>
				
				<form id="pmpro_two_factor_revalidate_form" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form' ) ); ?>" action="<?php echo esc_url( pmpro_login_url() ); ?>" method="post" autocomplete="off">
					
					<input type="hidden" name="action" value="revalidate_2fa" />
					<input type="hidden" name="provider" value="<?php echo esc_attr( $provider->get_key() ); ?>" />
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
					
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
						<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field pmpro_two_factor_field' ) ); ?>">
							<?php
							// Let the Two-Factor provider render its authentication fields.
							$provider->authentication_page( $user );
							?>
						</div>
					</div>
					
				</form>
				
			</div>
			
			<?php if ( ! empty( $backup_providers ) ) : ?>
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_actions' ) ); ?>">
					<p class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_font-small' ) ); ?>">
						<strong><?php esc_html_e( 'Having Problems?', 'paid-memberships-pro' ); ?></strong>
					</p>
					<ul class="pmpro_two_factor_backup_methods">
						<?php 
						foreach ( $backup_providers as $backup_key => $backup_provider ) : 
							$backup_url = add_query_arg(
								array(
									'action'      => 'revalidate_2fa',
									'provider'    => $backup_key,
									'redirect_to' => urlencode( $redirect_to ),
								),
								pmpro_login_url()
							);
						?>
							<li>
								<a href="<?php echo esc_url( $backup_url ); ?>">
									<?php echo esc_html( $backup_provider->get_alternative_provider_label() ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			</div>
		</div>
	</div>
	
	<style type="text/css">
		/* Two-Factor Revalidation Form Styling */
		#pmpro_two_factor_revalidate .pmpro_two_factor_field {
			margin-bottom: 0;
		}
		
		#pmpro_two_factor_revalidate .two-factor-prompt {
			margin-bottom: 1em;
		}
		
		#pmpro_two_factor_revalidate label {
			display: block;
			margin-bottom: 0.5em;
			font-weight: 600;
		}
		
		#pmpro_two_factor_revalidate input[type="text"],
		#pmpro_two_factor_revalidate input.authcode {
			width: 100%;
			padding: 12px;
			border: 1px solid #ddd;
			border-radius: 4px;
			margin-bottom: 1em;
		}
		
		#pmpro_two_factor_revalidate input.authcode {
			letter-spacing: 0.3em;
			text-align: center;
			font-size: 1.2em;
		}
		
		#pmpro_two_factor_revalidate .button,
		#pmpro_two_factor_revalidate button[type="submit"],
		#pmpro_two_factor_revalidate input[type="submit"] {
			width: 100%;
			margin-top: 0.5em;
		}
		
		#pmpro_two_factor_revalidate .pmpro_two_factor_backup_methods {
			list-style: none;
			padding: 0;
			margin: 0.5em 0 0 0;
		}
		
		#pmpro_two_factor_revalidate .pmpro_two_factor_backup_methods li {
			margin-bottom: 0.5em;
		}
		
		#pmpro_two_factor_revalidate .pmpro_two_factor_backup_methods a {
			color: var(--pmpro--color--accent, #2997c8);
			text-decoration: none;
		}
		
		#pmpro_two_factor_revalidate .pmpro_two_factor_backup_methods a:hover {
			text-decoration: underline;
		}
	</style>
	
	<?php
	$content = ob_get_clean();
	
	return $content;
}

/**
 * Handle PMPro frontend revalidation flow.
 * 
 * When action=revalidate_2fa is detected on the PMPro login page,
 * this displays the 2FA verification form for revalidation.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_handle_revalidation() {
	// Only handle on login page with revalidate_2fa action.
	if ( empty( $_GET['action'] ) || $_GET['action'] !== 'revalidate_2fa' ) {
		return;
	}
	
	// User must be logged in to revalidate.
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( pmpro_login_url() );
		exit;
	}
	
	$user = wp_get_current_user();
	
	// Check if this is a form submission.
	$is_post = ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ) );
	
	if ( $is_post && ! empty( $_POST['action'] ) && $_POST['action'] === 'revalidate_2fa' ) {

		// Process the revalidation.
		$provider_key = ! empty( $_POST['provider'] ) ? sanitize_text_field( $_POST['provider'] ) : null;
		$provider = Two_Factor_Core::get_provider_for_user( $user, $provider_key );
		
		if ( $provider ) {
			$result = $provider->validate_authentication( $user );
			
			if ( true === $result ) {
				// Success! Update the session timestamp.
				$sessions = WP_Session_Tokens::get_instance( $user->ID );
				$token = wp_get_session_token();
				$session = $sessions->get( $token );
				
				if ( $session ) {
					$session['two-factor-login'] = time();
					$sessions->update( $token, $session );
				}
				
				// Redirect to the intended page.
				$redirect_to = ! empty( $_POST['redirect_to'] ) ? esc_url_raw( $_POST['redirect_to'] ) : pmpro_url( 'member_profile_edit' );
				wp_safe_redirect( $redirect_to );
				exit;
			}
		}
		
		// Failed - redirect back with error.
		$error_url = add_query_arg(
			array(
				'action'      => 'revalidate_2fa',
				'redirect_to' => ! empty( $_POST['redirect_to'] ) ? urlencode( $_POST['redirect_to'] ) : '',
				'error'       => urlencode( __( 'Invalid verification code.', 'paid-memberships-pro' ) ),
			),
			pmpro_login_url()
		);
		wp_safe_redirect( $error_url );
		exit;
	}
	
}
add_action( 'init', 'pmpro_two_factor_handle_revalidation', 1 );

/**
 * Adjust the Two Factor revalidation URL to work with PMPro's frontend profile page.
 * 
 * When Two Factor requires session revalidation, this filter ensures the redirect
 * points back to the PMPro edit profile page instead of the WordPress admin.
 * 
 * @since TBD
 * 
 * @param string $url The original revalidation redirect URL.
 * 
 * @return string The modified redirect URL.
 */
function pmpro_two_factor_revalidation_redirect( $url ) {
	// Only modify the URL if we're on the frontend profile edit page.
	if ( is_admin() ) {
		return $url;
	}

	// Check if we have a profile edit page URL.
	$profile_edit_url = pmpro_url( 'member_profile_edit' );
	if ( empty( $profile_edit_url ) ) {
		return $url;
	}

	// Parse the original URL to preserve query parameters.
	$parsed_url = wp_parse_url( $url );
	if ( ! empty( $parsed_url['query'] ) ) {
		parse_str( $parsed_url['query'], $query_args );
		
		// Preserve the Two Factor action parameters.
		if ( ! empty( $query_args ) ) {
			$profile_edit_url = add_query_arg( $query_args, $profile_edit_url );
		}
	}

	return $profile_edit_url;
}
/**
 * Intercept login flow to redirect to PMPro frontend 2FA page.
 * 
 * This function hooks into wp_login BEFORE Two_Factor_Core (priority 9 vs 10).
 * When a user with 2FA logs in via PMPro's frontend login form, we intercept
 * the flow and redirect to PMPro's login page with a 2FA prompt instead of
 * letting Two Factor redirect to wp-login.php.
 * 
 * @since TBD
 * 
 * @param string  $user_login Username.
 * @param WP_User $user       WP_User object of the logged-in user.
 * 
 * @return void
 */
function pmpro_two_factor_intercept_login( $user_login, $user ) {
	// Only intercept if this is a PMPro frontend login form submission.
	if ( empty( $_POST['pmpro_login_form_used'] ) ) {
		return; // Not PMPro form, let Two Factor handle normally
	}
	
	// Check if user has Two Factor authentication enabled.
	if ( ! class_exists( 'Two_Factor_Core' ) || ! Two_Factor_Core::is_user_using_two_factor( $user->ID ) ) {
		return; // No 2FA enabled, login proceeds normally
	}
	
	// Create Two Factor's login nonce (security token for 2FA session).
	$login_nonce = Two_Factor_Core::create_login_nonce( $user->ID );
	if ( ! $login_nonce ) {
		// Nonce creation failed, let Two Factor handle it
		return;
	}
	
	// Generate a unique session identifier.
	$session_id = 'pmpro_2fa_' . $user->ID . '_' . wp_generate_password( 20, false );
	
	// Determine the login URL to redirect to (preserve original login context).
	// This ensures we redirect back to PMPro login or wp-login.php depending on where user started.
	$login_url = pmpro_login_url();
	
	// Store 2FA session data in a transient (expires in 10 minutes).
	set_transient( $session_id, array(
		'user_id'     => $user->ID,
		'nonce_key'   => $login_nonce['key'],
		'redirect_to' => ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( $_REQUEST['redirect_to'] ) : pmpro_url( 'account' ),
		'rememberme'  => ! empty( $_POST['rememberme'] ),
		'provider'    => null, // User can choose or use primary
		'login_url'   => $login_url, // Store the original login page URL
		'timestamp'   => time(),
	), 10 * MINUTE_IN_SECONDS );
	
	// Invalidate the password-only authentication session (Two Factor security measure).
	Two_Factor_Core::destroy_current_session_for_user( $user );
	
	// Clear authentication cookies (user must complete 2FA first).
	wp_clear_auth_cookie();
	
	// Redirect to the same login page with 2FA verification action.
	// Use the stored login_url to preserve the original context.
	$verify_url = add_query_arg( array(
		'action'     => 'verify-2fa',
		'session_id' => $session_id,
	), $login_url );
	
	wp_safe_redirect( $verify_url );
	exit; // CRITICAL: Prevents Two_Factor_Core::wp_login from also exiting to wp-login.php
}
add_action( 'wp_login', 'pmpro_two_factor_intercept_login', 9, 2 );

/**
 * Display Two Factor authentication form on PMPro login page.
 * 
 * This function retrieves the 2FA session data and calls Two Factor's login_html()
 * function to render the authentication form. We capture the output and modify it
 * to submit to PMPro's login URL instead of wp-login.php.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_display_verification_form() {
	// Get session ID from URL parameter.
	$session_id = ! empty( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';
	
	// Retrieve session data from transient early to get the original login URL.
	$session_data = get_transient( $session_id );
	
	// Get the base login URL from session data (fallback to pmpro_login_url).
	$base_login_url = ! empty( $session_data['login_url'] ) ? $session_data['login_url'] : pmpro_login_url();
	
	if ( empty( $session_id ) ) {
		// No session ID, redirect to login with error.
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	if ( ! $session_data || empty( $session_data['user_id'] ) || empty( $session_data['nonce_key'] ) ) {
		// Session expired or invalid.
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Get the user object.
	$user = get_user_by( 'id', $session_data['user_id'] );
	if ( ! $user ) {
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Get provider (from URL parameter if switching, otherwise primary).
	$provider_key = ! empty( $_GET['provider'] ) ? sanitize_text_field( $_GET['provider'] ) : $session_data['provider'];
	$provider = Two_Factor_Core::get_provider_for_user( $user, $provider_key );
	
	if ( ! $provider ) {
		// Invalid provider.
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Get error message if validation failed.
	$error_msg = ! empty( $_GET['error'] ) ? urldecode( sanitize_text_field( $_GET['error'] ) ) : '';
	
	// Build the 2FA form manually to avoid JavaScript conflicts.
	// Get available providers for backup methods.
	$available_providers = Two_Factor_Core::get_available_providers_for_user( $user );
	$backup_providers = array_diff_key( $available_providers, array( $provider->get_key() => null ) );
	$rememberme = $session_data['rememberme'] ? 1 : 0;
	
	// Start building the form HTML.
	ob_start();
	?>
	
	<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_title pmpro_font-large' ) ); ?>">
		<?php esc_html_e( 'Two-Factor Authentication', 'paid-memberships-pro' ); ?>
	</h2>
	
	<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
		<form name="pmpro_validate_2fa_form" id="loginform" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form' ) ); ?>" action="<?php echo esc_url( $base_login_url ); ?>" method="post" autocomplete="off">
			<input type="hidden" name="action" value="pmpro_validate_2fa" />
			<input type="hidden" name="pmpro_login_form_used" value="1" />
			<input type="hidden" name="session_id" value="<?php echo esc_attr( $session_id ); ?>" />
			<input type="hidden" name="provider" value="<?php echo esc_attr( $provider->get_key() ); ?>" />
			<input type="hidden" name="wp-auth-id" value="<?php echo esc_attr( $user->ID ); ?>" />
			<input type="hidden" name="wp-auth-nonce" value="<?php echo esc_attr( $session_data['nonce_key'] ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $session_data['redirect_to'] ); ?>" />
			<input type="hidden" name="rememberme" value="<?php echo esc_attr( $rememberme ); ?>" />
			
		<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
			<div class="pmpro_two_factor_provider_content">
				<?php
				// Let the provider render its authentication page (input fields, instructions, etc.).
				$provider->authentication_page( $user );
				?>
			</div>
		</div>
	</form>
	
	<?php
	// Add auto-submit JavaScript for numeric input fields (TOTP, Email codes, etc.).
	// This is the same JavaScript that Two Factor uses for auto-submitting when
	// the expected number of digits is entered.
	?>
	<script type="text/javascript">
	(function() {
		// Enforce numeric-only input for numeric inputmode elements.
		const form = document.querySelector( '#loginform' ),
			inputEl = document.querySelector( 'input.authcode[inputmode="numeric"]' ),
			expectedLength = inputEl?.dataset.digits || 0,
			submitButton = form ? form.querySelector( 'button[type="submit"], input[type="submit"]' ) : null;

		if ( inputEl && form ) {
			let spaceInserted = false;
			let isSubmitting = false;
			
			// Set maxlength to prevent more than expected digits from being entered.
			// For 6-digit codes, maxlength is 7 to account for the space (e.g., "123 456").
			if ( expectedLength ) {
				inputEl.setAttribute( 'maxlength', expectedLength + 1 );
			}
			
			// If there's an error message on the page, ensure button is enabled.
			const errorMessage = document.querySelector( '.pmpro_error' );
			if ( errorMessage && submitButton ) {
				submitButton.disabled = false;
			}
			
			// Disable button on form submit (manual or auto).
			form.addEventListener( 'submit', function() {
				if ( submitButton && !isSubmitting ) {
					isSubmitting = true;
					submitButton.disabled = true;
					submitButton.textContent = submitButton.textContent || '<?php esc_html_e( 'Verifying...', 'paid-memberships-pro' ); ?>';
				}
			});
			
			inputEl.addEventListener(
				'input',
				function() {
					// Re-enable button when user types (after an error).
					if ( submitButton && isSubmitting ) {
						submitButton.disabled = false;
						isSubmitting = false;
						// Restore original button text if it was changed.
						const originalText = submitButton.getAttribute('data-original-text');
						if ( originalText ) {
							submitButton.textContent = originalText;
						}
					}
					
					let value = this.value.replace( /[^0-9 ]/g, '' ).trimStart();
					
					// Enforce maximum length (remove excess digits).
					const digitsOnly = value.replace( / /g, '' );
					if ( expectedLength && digitsOnly.length > expectedLength ) {
						value = digitsOnly.substring( 0, expectedLength );
						// Re-add space if needed.
						if ( value.length > Math.floor( expectedLength / 2 ) ) {
							const firstHalf = value.substring( 0, Math.floor( expectedLength / 2 ) );
							const secondHalf = value.substring( Math.floor( expectedLength / 2 ) );
							value = firstHalf + ' ' + secondHalf;
							spaceInserted = true;
						}
					}

					// Add space in the middle for better readability (e.g., "123 456").
					if ( ! spaceInserted && expectedLength && value.length === Math.floor( expectedLength / 2 ) ) {
						value += ' ';
						spaceInserted = true;
					} else if ( spaceInserted && ! this.value ) {
						spaceInserted = false;
					}

					this.value = value;

					// Auto-submit when the expected number of digits is entered.
					if ( expectedLength && value.replace( / /g, '' ).length == expectedLength ) {
						if ( undefined !== form.requestSubmit ) {
							// Store original button text before changing.
							if ( submitButton && !submitButton.getAttribute('data-original-text') ) {
								submitButton.setAttribute('data-original-text', submitButton.textContent);
							}
							form.requestSubmit();
						}
					}
				}
			);
			
			// Focus the input field automatically and clear it.
			setTimeout( function() {
				try {
					inputEl.value = '';
					inputEl.focus();
					// Ensure button is enabled on fresh load.
					if ( submitButton && !errorMessage ) {
						submitButton.disabled = false;
					}
				} catch(e) {}
			}, 200 );
		}
	})();
	</script>
	
</div> <!-- end pmpro_card_content -->
	
	<?php
	// Show backup provider links if available.
	if ( ! empty( $backup_providers ) ) :
	?>
		<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_actions' ) ); ?>">
			<p><?php esc_html_e( 'Having Problems?', 'paid-memberships-pro' ); ?></p>
			<ul class="pmpro_two_factor_backup_methods">
				<?php 
				// Use the stored login URL to preserve the original login context.
				$base_login_url = ! empty( $session_data['login_url'] ) ? $session_data['login_url'] : pmpro_login_url();
				
				foreach ( $backup_providers as $backup_key => $backup_provider ) : 
					$backup_url = add_query_arg( array(
						'action'     => 'verify-2fa',
						'session_id' => $session_id,
						'provider'   => $backup_key,
					), $base_login_url );
				?>
					<li>
						<a href="<?php echo esc_url( $backup_url ); ?>">
							<?php echo esc_html( $backup_provider->get_alternative_provider_label() ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif;
	
	$two_factor_html = ob_get_clean();
	
	// Wrap in PMPro card styling and output.
	echo '<div class="' . esc_attr( pmpro_get_element_class( 'pmpro_card pmpro_two_factor_wrap', 'pmpro_two_factor_wrap' ) ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $two_factor_html;
	echo '</div>';
}

/**
 * Process Two Factor authentication form submission from PMPro login page.
 * 
 * This function handles the 2FA validation when the user submits the verification
 * form. It delegates to Two Factor's validation logic but handles the session
 * management and redirects appropriately.
 * 
 * @since TBD
 * 
 * @return void
 */
function pmpro_two_factor_process_validation() {
	// Only process if this is a 2FA validation form submission.
	if ( empty( $_POST['action'] ) || $_POST['action'] !== 'pmpro_validate_2fa' ) {
		return;
	}
	
	// Verify this is from PMPro's login form.
	if ( empty( $_POST['pmpro_login_form_used'] ) ) {
		return;
	}
	
	// Get session ID.
	$session_id = ! empty( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
	
	// Retrieve session data early to get the original login URL.
	$session_data = get_transient( $session_id );
	
	// Get the base login URL from session (preserves whether user used PMPro or wp-login.php).
	$base_login_url = ! empty( $session_data['login_url'] ) ? $session_data['login_url'] : pmpro_login_url();
	
	if ( empty( $session_id ) ) {
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	if ( ! $session_data || empty( $session_data['user_id'] ) || empty( $session_data['nonce_key'] ) ) {
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Get user object.
	$user = get_user_by( 'id', $session_data['user_id'] );
	
	if ( ! $user ) {
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Get provider from form submission.
	$provider_key = ! empty( $_POST['provider'] ) ? sanitize_text_field( $_POST['provider'] ) : null;
	$provider = Two_Factor_Core::get_provider_for_user( $user, $provider_key );
	
	if ( ! $provider ) {
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Verify the login nonce.
	if ( ! Two_Factor_Core::verify_login_nonce( $user->ID, $session_data['nonce_key'] ) ) {
		delete_transient( $session_id );
		wp_safe_redirect( add_query_arg( 'action', 'two_factor_session_expired', $base_login_url ) );
		exit;
	}
	
	// Process the provider's validation.
	$result = Two_Factor_Core::process_provider( $provider, $user, true );
	
	if ( true !== $result ) {
		// Validation failed.
		$error_msg = __( 'Invalid verification code.', 'paid-memberships-pro' );
		
		if ( is_wp_error( $result ) ) {
			$error_msg = $result->get_error_message();
		}
		
		// Redirect back to verification form with error.
		// Use the stored login URL to preserve the original login context.
		$error_url = add_query_arg( array(
			'action'     => 'verify-2fa',
			'session_id' => $session_id,
			'error'      => urlencode( $error_msg ),
		), $base_login_url );
		
		wp_safe_redirect( $error_url );
		exit;
	}
	
	// SUCCESS! Clean up session data.
	delete_transient( $session_id );
	Two_Factor_Core::delete_login_nonce( $user->ID );
	delete_user_meta( $user->ID, Two_Factor_Core::USER_RATE_LIMIT_KEY );
	delete_user_meta( $user->ID, Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY );
	
	// Add Two Factor metadata to session.
	$session_callback = function( $session, $user_id ) use ( $provider, $user ) {
		if ( $user->ID === $user_id ) {
			$session['two-factor-login'] = time();
			$session['two-factor-provider'] = $provider->get_key();
		}
		return $session;
	};
	
	add_filter( 'attach_session_information', $session_callback, 10, 2 );
	
	// Set authentication cookie.
	wp_set_auth_cookie( $user->ID, $session_data['rememberme'] );
	
	remove_filter( 'attach_session_information', $session_callback, 10 );
	
	// Redirect to intended destination.
	wp_safe_redirect( $session_data['redirect_to'] );
	exit;
}
add_action( 'init', 'pmpro_two_factor_process_validation', 5 );
