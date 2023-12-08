<?php
abstract class PMPro_Member_Edit_Panel {
	/**
	 * @var string The slug for this panel.
	 */
	protected $slug = '';

	/**
	 * @var string The title for this panel.
	 */
	protected $title = '';

	/**
	 * @var string The title link for this panel.
	 */
	protected $title_link = '';

	/**
	 * @var string The submit text for this panel. If empty, no submit button will be shown.
	 */
	protected $submit_text = '';

	/**
	 * Get the slug for this panel.
	 *
	 * @since TBD
	 *
	 * @return string
	 */
	final public function get_slug() {
		return $this->slug;
	}

	/**
	 * Display the tab for this panel.
	 *
	 * @since TBD
	 *
	 * @param bool $is_selected True if this is the selected panel, false otherwise.
	 */
	final public function display_tab( $is_selected ) {
		?>
		<button
			role="tab"
			aria-selected="<?php echo $is_selected ? 'true' : 'false' ?>"
			aria-controls="pmpro-member-edit-<?php echo esc_attr( $this->slug ) ?>-panel"
			id="pmpro-member-edit-<?php echo esc_attr( $this->slug ) ?>-tab"
			<?php echo ( empty( self::get_user()->ID ) && $this->slug != 'user_info'  ) ? 'disabled="disabled"' : ''; ?>
			tabindex="<?php echo ( $is_selected ) ? '0' : '-1' ?>"
		>
			<?php
				echo esc_attr( ( strlen( $this->title ) > 40 ) ? substr( $this->title, 0, 40 ) . '...' : $this->title );
			?>
		</button>
		<?php
	}

	/**
	 * Display the panel.
	 *
	 * @since TBD
	 *
	 * @param bool $is_selected True if this is the selected panel, false otherwise.
	 */
	final public function display_panel( $is_selected ) {
		?>
		<div
			id="pmpro-member-edit-<?php echo esc_attr( $this->slug ) ?>-panel"
			role="tabpanel"
			tabindex="<?php echo $is_selected ? '0' : '-1' ?>"
			aria-labelledby="pmpro-member-edit-<?php echo esc_attr( $this->slug ) ?>-tab"
			<?php echo $is_selected ? '' : 'hidden'; ?>
		>
			<h2><?php echo esc_html( $this->title ); ?></h2>
			<?php
			echo wp_kses( $this->title_link, array( 'a' => array( 'href' => array(), 'target' => array(), 'class' => array() ) ) );

			// Get the URL to submit to.
			$submit_url = add_query_arg( 'user_id', self::get_user()->ID, admin_url( 'admin.php?page=pmpro-member' ) );
			?>
			<form class="pmpro-members" action="<?php echo esc_url( $submit_url ); ?>" method="post">
				<input type="hidden" name="pmpro_member_edit_panel" value="<?php echo esc_attr( $this->slug ); ?>">
				<?php
				// Add a nonce.
				wp_nonce_field( 'pmpro_member_edit_saved_panel_' . $this->slug, 'pmpro_member_edit_saved_panel_nonce' );

				// Display the panel.
				$this->display_panel_contents();

				// Display the submit button.
				if ( ! empty( $this->submit_text ) ) {
					// If this is the selected panel, set the submit button ID.
					// Needed for the 'user-profile' script on the user info panel when creating a new user.
					$submit_id = $is_selected ? 'submit' : ''; 
					?>
					<p class="submit">
						<input id="<?php echo esc_attr( $submit_id ); ?>" type="submit" name="submit" class="button button-primary" value="<?php echo esc_attr( $this->submit_text ); ?>">
					</p>
					<?php
				}
				?>
			</form>
		</div>			
		<?php
	}

	/**
	 * Get the user that we are editing.
	 *
	 * @since TBD
	 *
	 * @return WP_User
	 */
	final public static function get_user() {
		static $user = null;

		if ( ! empty( $user ) ) {
			return $user;
		}

		// Get the user that we are editing.
		if ( ! empty( $_REQUEST['user_id'] ) ) {
			$check_user = get_userdata( intval( $_REQUEST['user_id'] ) );
			if ( ! empty( $check_user->ID ) ) {
				$user = $check_user;
			}
		}

		// If $user is still empty, get a blank user.
		if ( empty( $user ) ) {
			$user = new WP_User();
		}

		return $user;
	}

	/**
	 * Save the panel.
	 *
	 * @since TBD
	 */
	public function save() {
		// Hook into this to save the options and return true/false based on custom conditions.
		$saved = apply_filters( 'pmpro_member_' . $this->slug . '_panel_save', true, self::get_user() );

		if ( $saved ) {
			pmpro_setMessage( __( 'Member updated successfully.', 'paid-memberships-pro' ), 'pmpro_success' );
		} else {
			pmpro_setMessage( __( 'An error occurred, please try again.', 'paid-memberships-pro' ), 'pmpro_error' );
		}
	}

	/**
	 * Display the contents of the panel.
	 *
	 * @since TBD
	 */
	abstract protected function display_panel_contents();
}
