<?php
/**
 * Frontend Onboarding Form Handler.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Form_Handler
 */
class SOM_Form_Handler {

	/**
	 * Initialize form hooks.
	 */
	public static function init() {
		add_shortcode( 'som_onboarding_form', array( __CLASS__, 'render_shortcode' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_som_check_duplicate', array( __CLASS__, 'ajax_check_duplicate' ) );
		add_action( 'wp_ajax_som_submit_shop', array( __CLASS__, 'ajax_submit_shop' ) );

		// Assets.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue frontend CSS and JavaScript.
	 */
	public static function enqueue_assets() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'som_onboarding_form' ) ) {
			return;
		}

		wp_enqueue_style(
			'som-frontend-style',
			SOM_PLUGIN_URL . 'assets/css/som-frontend.css',
			array(),
			SOM_VERSION
		);

		wp_enqueue_script(
			'som-frontend-script',
			SOM_PLUGIN_URL . 'assets/js/som-frontend.js',
			array( 'jquery' ),
			SOM_VERSION,
			true
		);

		wp_localize_script(
			'som-frontend-script',
			'somConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'som_frontend_onboard_nonce' ),
				'i18n'    => array(
					'locationFetching'  => __( 'Fetching GPS location...', 'shop-onboarding-manager' ),
					'locationSuccess'   => __( 'GPS Location Captured', 'shop-onboarding-manager' ),
					'locationError'     => __( 'Unable to auto-get location. (Browser requires HTTPS for location API). You can manually enter coordinates below.', 'shop-onboarding-manager' ),
					'duplicateWarning'  => __( 'A shop with this phone number or name & address already exists.', 'shop-onboarding-manager' ),
					'submitting'        => __( 'Registering Shop...', 'shop-onboarding-manager' ),
					'successMessage'    => __( 'Shop successfully registered!', 'shop-onboarding-manager' ),
				),
			)
		);
	}

	/**
	 * AJAX endpoint to check for potential duplicate shops.
	 */
	public static function ajax_check_duplicate() {
		check_ajax_referer( 'som_frontend_onboard_nonce', 'nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shop-onboarding-manager' ) ) );
		}

		$phone     = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$shop_name = isset( $_POST['shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_name'] ) ) : '';
		$address   = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';

		$duplicates = array();

		// 1. Check by phone number if provided.
		if ( ! empty( $phone ) ) {
			$phone_query = new WP_Query(
				array(
					'post_type'      => 'shop',
					'post_status'    => 'any',
					'posts_per_page' => 3,
					'meta_query'     => array(
						array(
							'key'     => 'som_phone_number',
							'value'   => $phone,
							'compare' => '=',
						),
					),
				)
			);

			if ( $phone_query->have_posts() ) {
				foreach ( $phone_query->posts as $p ) {
					$duplicates[ $p->ID ] = array(
						'id'         => $p->ID,
						'title'      => get_the_title( $p->ID ),
						'owner'      => get_post_meta( $p->ID, 'som_owner_name', true ),
						'phone'      => get_post_meta( $p->ID, 'som_phone_number', true ),
						'reason'     => __( 'Matched by phone number', 'shop-onboarding-manager' ),
					);
				}
			}
		}

		// 2. Check by exact title (shop name).
		if ( ! empty( $shop_name ) ) {
			$name_query = new WP_Query(
				array(
					'post_type'      => 'shop',
					'post_status'    => 'any',
					'title'          => $shop_name,
					'posts_per_page' => 3,
				)
			);

			if ( $name_query->have_posts() ) {
				foreach ( $name_query->posts as $p ) {
					if ( ! isset( $duplicates[ $p->ID ] ) ) {
						$duplicates[ $p->ID ] = array(
							'id'     => $p->ID,
							'title'  => get_the_title( $p->ID ),
							'owner'  => get_post_meta( $p->ID, 'som_owner_name', true ),
							'phone'  => get_post_meta( $p->ID, 'som_phone_number', true ),
							'reason' => __( 'Matched by shop name', 'shop-onboarding-manager' ),
						);
					}
				}
			}
		}

		if ( ! empty( $duplicates ) ) {
			wp_send_json_success(
				array(
					'has_duplicate' => true,
					'matches'       => array_values( $duplicates ),
				)
			);
		}

		wp_send_json_success( array( 'has_duplicate' => false ) );
	}

	/**
	 * AJAX endpoint to handle shop registration.
	 */
	public static function ajax_submit_shop() {
		check_ajax_referer( 'som_frontend_onboard_nonce', 'nonce' );

		// Permission check: User must be logged in and capable of editing posts.
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		// Extract & Sanitize fields.
		$shop_name        = isset( $_POST['shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_name'] ) ) : '';
		$owner_name        = isset( $_POST['owner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['owner_name'] ) ) : '';
		$phone             = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$address           = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
		$shop_type         = isset( $_POST['shop_type'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_type'] ) ) : '';
		$status_slug       = isset( $_POST['shop_status'] ) ? sanitize_key( wp_unslash( $_POST['shop_status'] ) ) : 'contacted';
		$followup_date     = isset( $_POST['followup_date'] ) ? sanitize_text_field( wp_unslash( $_POST['followup_date'] ) ) : '';
		$notes             = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$concerns          = isset( $_POST['concerns'] ) ? sanitize_textarea_field( wp_unslash( $_POST['concerns'] ) ) : '';
		$latitude          = isset( $_POST['latitude'] ) ? SOM_Shop_Meta::sanitize_coordinate( $_POST['latitude'] ) : '';
		$longitude         = isset( $_POST['longitude'] ) ? SOM_Shop_Meta::sanitize_coordinate( $_POST['longitude'] ) : '';
		$gps_accuracy      = isset( $_POST['gps_accuracy'] ) ? SOM_Shop_Meta::sanitize_coordinate( $_POST['gps_accuracy'] ) : '';
		$merchant_user_id  = isset( $_POST['merchant_user_id'] ) ? absint( $_POST['merchant_user_id'] ) : 0;
		$create_m_account  = ! empty( $_POST['create_merchant_account'] );
		$m_username        = isset( $_POST['merchant_username'] ) ? sanitize_user( wp_unslash( $_POST['merchant_username'] ) ) : '';
		$m_password        = isset( $_POST['merchant_password'] ) ? $_POST['merchant_password'] : '';

		// Basic validation.
		if ( empty( $shop_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Shop name is required.', 'shop-onboarding-manager' ) ) );
		}

		// Insert CPT post.
		$post_data = array(
			'post_title'   => $shop_name,
			'post_type'    => 'shop',
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create shop post.', 'shop-onboarding-manager' ) ) );
		}

		// Handle Merchant Account Creation if requested.
		if ( $create_m_account && ! empty( $m_username ) && ! empty( $m_password ) ) {
			$m_user_id = SOM_Merchant_Manager::create_merchant( $m_username, $m_password, '', $post_id );
			if ( ! is_wp_error( $m_user_id ) ) {
				$merchant_user_id = $m_user_id;
			}
		}

		// Handle Photo Upload if present.
		$photo_id = 0;
		if ( ! empty( $_FILES['shop_photo']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$attachment_id = media_handle_upload( 'shop_photo', $post_id );
			if ( ! is_wp_error( $attachment_id ) ) {
				$photo_id = $attachment_id;
			}
		}

		// Save Meta.
		update_post_meta( $post_id, 'som_owner_name', $owner_name );
		update_post_meta( $post_id, 'som_phone_number', $phone );
		update_post_meta( $post_id, 'som_address', $address );
		update_post_meta( $post_id, 'som_shop_type', $shop_type );
		update_post_meta( $post_id, 'som_shop_photo_id', $photo_id );
		update_post_meta( $post_id, 'som_latitude', $latitude );
		update_post_meta( $post_id, 'som_longitude', $longitude );
		update_post_meta( $post_id, 'som_gps_accuracy', $gps_accuracy );
		update_post_meta( $post_id, 'som_merchant_user_id', $merchant_user_id );
		update_post_meta( $post_id, 'som_followup_date', $followup_date );
		update_post_meta( $post_id, 'som_notes', $notes );
		update_post_meta( $post_id, 'som_concerns', $concerns );

		// If a merchant user ID was selected or created, complete bidirectional link.
		if ( $merchant_user_id ) {
			SOM_Merchant_Manager::link_shop_to_merchant( $post_id, $merchant_user_id );
		}

		// Assign Taxonomy Status.
		if ( ! empty( $status_slug ) && term_exists( $status_slug, 'shop_status' ) ) {
			wp_set_object_terms( $post_id, $status_slug, 'shop_status' );
		} else {
			wp_set_object_terms( $post_id, 'contacted', 'shop_status' );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Shop registered successfully!', 'shop-onboarding-manager' ),
				'shop_id' => $post_id,
			)
		);
	}

	/**
	 * Render [som_onboarding_form] shortcode.
	 *
	 * @return string HTML output.
	 */
	public static function render_shortcode() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return '<div class="som-form-alert som-alert-error">' .
				esc_html__( 'Access restricted. Please log in with a field team account to access the shop onboarding form.', 'shop-onboarding-manager' ) .
				'</div>';
		}

		$shop_types = array(
			'Supermarket'        => __( 'Supermarket', 'shop-onboarding-manager' ),
			'Grocery'            => __( 'Grocery Store', 'shop-onboarding-manager' ),
			'Convenience Store'  => __( 'Convenience Store', 'shop-onboarding-manager' ),
			'Bakery'             => __( 'Bakery', 'shop-onboarding-manager' ),
			'Butchery'           => __( 'Butchery', 'shop-onboarding-manager' ),
			'Fruit & Vegetable'  => __( 'Fruit & Vegetable Market', 'shop-onboarding-manager' ),
			'Specialty Store'    => __( 'Specialty Store', 'shop-onboarding-manager' ),
			'Other'              => __( 'Other', 'shop-onboarding-manager' ),
		);

		$statuses = array(
			'contacted'  => __( 'Contacted', 'shop-onboarding-manager' ),
			'interested' => __( 'Interested', 'shop-onboarding-manager' ),
			'verified'   => __( 'Verified', 'shop-onboarding-manager' ),
			'committed'  => __( 'Committed', 'shop-onboarding-manager' ),
			'rejected'   => __( 'Rejected', 'shop-onboarding-manager' ),
		);

		$merchants = get_users(
			array(
				'role__in' => array( 'merchant', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		ob_start();
		?>
		<div class="som-onboarding-card">
			<div class="som-card-header">
				<h2><?php esc_html_e( 'Shop Onboarding Form', 'shop-onboarding-manager' ); ?></h2>
				<p><?php esc_html_e( 'Register a new supermarket or grocery store', 'shop-onboarding-manager' ); ?></p>
			</div>

			<!-- Duplicate Warning Banner -->
			<div id="som_duplicate_warning" class="som-duplicate-banner" style="display: none;">
				<div class="som-duplicate-header">
					<span class="som-icon-warning">⚠️</span>
					<strong><?php esc_html_e( 'Possible Duplicate Detected', 'shop-onboarding-manager' ); ?></strong>
				</div>
				<div id="som_duplicate_list" class="som-duplicate-list"></div>
				<p class="som-duplicate-note"><?php esc_html_e( 'You can still proceed if this is a distinct business.', 'shop-onboarding-manager' ); ?></p>
			</div>

			<form id="som_onboarding_form" enctype="multipart/form-data">
				<!-- Section 1: Basic Info -->
				<div class="som-form-group">
					<label for="som_f_shop_name" class="som-label required"><?php esc_html_e( 'Shop Name', 'shop-onboarding-manager' ); ?></label>
					<input type="text" id="som_f_shop_name" name="shop_name" class="som-input" required placeholder="e.g. Nearmart Fresh Supermarket" />
				</div>

				<div class="som-form-row">
					<div class="som-form-group">
						<label for="som_f_owner_name" class="som-label"><?php esc_html_e( 'Owner Name', 'shop-onboarding-manager' ); ?></label>
						<input type="text" id="som_f_owner_name" name="owner_name" class="som-input" placeholder="e.g. Ramesh Kumar" />
					</div>

					<div class="som-form-group">
						<label for="som_f_phone" class="som-label"><?php esc_html_e( 'Phone Number', 'shop-onboarding-manager' ); ?></label>
						<input type="tel" id="som_f_phone" name="phone" class="som-input" placeholder="e.g. 9876543210" />
					</div>
				</div>

				<div class="som-form-group">
					<label for="som_f_address" class="som-label"><?php esc_html_e( 'Address', 'shop-onboarding-manager' ); ?></label>
					<textarea id="som_f_address" name="address" class="som-textarea" rows="3" placeholder="Full street address, area, landmark"></textarea>
				</div>

				<div class="som-form-row">
					<div class="som-form-group">
						<label for="som_f_shop_type" class="som-label"><?php esc_html_e( 'Shop Type', 'shop-onboarding-manager' ); ?></label>
						<select id="som_f_shop_type" name="shop_type" class="som-select">
							<option value=""><?php esc_html_e( '-- Select Type --', 'shop-onboarding-manager' ); ?></option>
							<?php foreach ( $shop_types as $val => $lbl ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="som-form-group">
						<label for="som_f_shop_status" class="som-label"><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></label>
						<select id="som_f_shop_status" name="shop_status" class="som-select">
							<?php foreach ( $statuses as $val => $lbl ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Section 2: Photo Capture -->
				<div class="som-form-group">
					<label class="som-label"><?php esc_html_e( 'Shop Photo', 'shop-onboarding-manager' ); ?></label>
					<div class="som-file-uploader">
						<input type="file" id="som_f_shop_photo" name="shop_photo" accept="image/*" capture="environment" class="som-file-input" />
						<label for="som_f_shop_photo" class="som-file-btn">
							📷 <?php esc_html_e( 'Take / Upload Photo', 'shop-onboarding-manager' ); ?>
						</label>
						<div id="som_photo_preview_container" class="som-photo-preview" style="display: none;">
							<img id="som_photo_img" src="" alt="Preview" />
							<button type="button" id="som_remove_photo" class="som-remove-btn">✕ Remove</button>
						</div>
					</div>
				</div>

				<!-- Section 3: GPS Coordinates -->
				<div class="som-form-group som-gps-box">
					<div class="som-gps-header">
						<label class="som-label"><?php esc_html_e( 'GPS Location', 'shop-onboarding-manager' ); ?></label>
						<button type="button" id="som_btn_get_location" class="som-gps-btn">
							📍 <?php esc_html_e( 'Capture GPS', 'shop-onboarding-manager' ); ?>
						</button>
					</div>
					<div id="som_gps_status_msg" class="som-gps-status"></div>

					<div class="som-form-row som-gps-fields">
						<div class="som-form-group">
							<label for="som_f_latitude" class="som-sublabel"><?php esc_html_e( 'Latitude', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="any" id="som_f_latitude" name="latitude" class="som-input" placeholder="e.g. 12.971598" />
						</div>
						<div class="som-form-group">
							<label for="som_f_longitude" class="som-sublabel"><?php esc_html_e( 'Longitude', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="any" id="som_f_longitude" name="longitude" class="som-input" placeholder="e.g. 77.594566" />
						</div>
						<div class="som-form-group">
							<label for="som_f_gps_accuracy" class="som-sublabel"><?php esc_html_e( 'Accuracy (m)', 'shop-onboarding-manager' ); ?></label>
							<input type="number" step="any" id="som_f_gps_accuracy" name="gps_accuracy" class="som-input" placeholder="Meters" />
						</div>
					</div>
				</div>

				<!-- Section 4: Onboarding Details -->
				<div class="som-form-row">
					<div class="som-form-group">
						<label for="som_f_followup_date" class="som-label"><?php esc_html_e( 'Follow-up Date', 'shop-onboarding-manager' ); ?></label>
						<input type="date" id="som_f_followup_date" name="followup_date" class="som-input" />
					</div>

					<div class="som-form-group">
						<label for="som_f_merchant_user_id" class="som-label"><?php esc_html_e( 'Assign Existing Merchant', 'shop-onboarding-manager' ); ?></label>
						<select id="som_f_merchant_user_id" name="merchant_user_id" class="som-select">
							<option value=""><?php esc_html_e( '-- Select Merchant --', 'shop-onboarding-manager' ); ?></option>
							<?php foreach ( $merchants as $m ) : ?>
								<option value="<?php echo esc_attr( $m->ID ); ?>">
									<?php echo esc_html( $m->display_name . ' (' . $m->user_login . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Section 5: Create New Merchant Account Option -->
				<div class="som-form-group som-merchant-creation-box">
					<label class="som-checkbox-label">
						<input type="checkbox" id="som_toggle_create_merchant" name="create_merchant_account" value="1" />
						<strong><?php esc_html_e( 'Create New Merchant Login Account', 'shop-onboarding-manager' ); ?></strong>
					</label>

					<div id="som_merchant_account_fields" class="som-form-row" style="display: none; margin-top: 10px;">
						<div class="som-form-group">
							<label for="som_f_merchant_username" class="som-sublabel"><?php esc_html_e( 'Merchant Username', 'shop-onboarding-manager' ); ?></label>
							<input type="text" id="som_f_merchant_username" name="merchant_username" class="som-input" placeholder="e.g. shopowner123" />
						</div>
						<div class="som-form-group">
							<label for="som_f_merchant_password" class="som-sublabel"><?php esc_html_e( 'Merchant Password', 'shop-onboarding-manager' ); ?></label>
							<input type="password" id="som_f_merchant_password" name="merchant_password" class="som-input" placeholder="Set secure password" />
						</div>
					</div>
				</div>

				<div class="som-form-group">
					<label for="som_f_notes" class="som-label"><?php esc_html_e( 'Field Notes', 'shop-onboarding-manager' ); ?></label>
					<textarea id="som_f_notes" name="notes" class="som-textarea" rows="2" placeholder="Observations, store size, traffic..."></textarea>
				</div>

				<div class="som-form-group">
					<label for="som_f_concerns" class="som-label"><?php esc_html_e( 'Shopkeeper Concerns', 'shop-onboarding-manager' ); ?></label>
					<textarea id="som_f_concerns" name="concerns" class="som-textarea" rows="2" placeholder="Commission rate, delivery timing, payment terms..."></textarea>
				</div>

				<!-- Submit Button -->
				<div class="som-form-actions">
					<button type="submit" id="som_btn_submit" class="som-submit-btn">
						🚀 <?php esc_html_e( 'Register Shop', 'shop-onboarding-manager' ); ?>
					</button>
				</div>

				<div id="som_form_message" class="som-response-msg"></div>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#som_toggle_create_merchant').on('change', function() {
				if ($(this).is(':checked')) {
					$('#som_merchant_account_fields').slideDown();
				} else {
					$('#som_merchant_account_fields').slideUp();
				}
			});
		});
		</script>
		<?php
		return ob_get_clean();
	}
}