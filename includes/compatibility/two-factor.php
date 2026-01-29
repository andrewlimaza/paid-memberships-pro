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

	/**
	 * Fires before the Two Factor section is displayed.
	 * 
	 * @since TBD
	 * 
	 * @param WP_User $user The user object being displayed.
	 */
	do_action( 'pmpro_two_factor_before_section', $user );
	?>

	<fieldset id="pmpro_member_profile_edit-two-factor" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset', 'pmpro_member_profile_edit-two-factor' ) ); ?>">
		<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
			
			<?php
			/**
			 * Fires before the Two Factor options are displayed.
			 * 
			 * @since TBD
			 * 
			 * @param WP_User $user The user object being displayed.
			 */
			do_action( 'pmpro_two_factor_before_options', $user );

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

				/**
				 * Fires after the Two Factor options are displayed.
				 * 
				 * @since TBD
				 * 
				 * @param WP_User $user The user object being displayed.
				 */
				do_action( 'pmpro_two_factor_after_options', $user );
			?>
			
		</div> <!-- end pmpro_form_fields -->
	</fieldset> <!-- end pmpro_member_profile_edit-two-factor -->

	<?php
	/**
	 * Fires after the Two Factor section is displayed.
	 * 
	 * @since TBD
	 * 
	 * @param WP_User $user The user object being displayed.
	 */
	do_action( 'pmpro_two_factor_after_section', $user );
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

	/**
	 * Fires before Two Factor settings are saved.
	 * 
	 * @since TBD
	 * 
	 * @param int $user_id The ID of the user being updated.
	 */
	do_action( 'pmpro_two_factor_before_save', $user_id );

	// Let the Two Factor plugin handle all validation, nonce verification, and saving.
	// This ensures we maintain the plugin's security model and don't duplicate logic.
	Two_Factor_Core::user_two_factor_options_update( $user_id );

	/**
	 * Fires after Two Factor settings are saved.
	 * 
	 * @since TBD
	 * 
	 * @param int $user_id The ID of the user being updated.
	 */
	do_action( 'pmpro_two_factor_after_save', $user_id );
}
add_action( 'pmpro_personal_options_update', 'pmpro_two_factor_save_settings' );

/**
 * Enqueue necessary styles and scripts for Two Factor on the frontend edit profile page.
 * 
 * The Two Factor plugin enqueues its own CSS when displaying user options, but this
 * function ensures assets are loaded at the right time and adds any custom styling
 * needed for frontend integration.
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

	/**
	 * Allow developers to add custom CSS for Two Factor integration.
	 * 
	 * @since TBD
	 */
	$custom_css = apply_filters( 'pmpro_two_factor_custom_css', '' );

	if ( ! empty( $custom_css ) ) {
		wp_add_inline_style( 'pmpro_frontend', $custom_css );
	}

	/**
	 * Fires when Two Factor assets are enqueued on the frontend.
	 * 
	 * @since TBD
	 */
	do_action( 'pmpro_two_factor_enqueue_assets' );
}
add_action( 'wp_enqueue_scripts', 'pmpro_two_factor_enqueue_assets' );

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

	/**
	 * Filter the revalidation redirect URL for the frontend profile page.
	 * 
	 * @since TBD
	 * 
	 * @param string $profile_edit_url The modified redirect URL.
	 * @param string $url              The original redirect URL.
	 */
	return apply_filters( 'pmpro_two_factor_revalidation_url', $profile_edit_url, $url );
}
