<?php
/**
 * Shop Data Model & Meta Fields Management Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Shop_Meta
 */
class SOM_Shop_Meta {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta_fields' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_shop_meta_box' ) );
		add_action( 'save_post_shop', array( __CLASS__, 'save_shop_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Register post meta schema for REST API and WP data layer consistency.
	 */
	public static function register_meta_fields() {
		$fields = array(
			'som_owner_name'             => array( 'type' => 'string', 'description' => 'Owner full name', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_phone_number'           => array( 'type' => 'string', 'description' => 'Shop phone number', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_address'                => array( 'type' => 'string', 'description' => 'Full street address', 'sanitize_callback' => 'sanitize_textarea_field' ),
			'som_shop_type'              => array( 'type' => 'string', 'description' => 'Category or type of shop', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_shop_photo_id'          => array( 'type' => 'integer', 'description' => 'Attachment ID of the shop photo', 'sanitize_callback' => 'absint' ),
			'som_latitude'               => array( 'type' => 'number', 'description' => 'GPS Latitude decimal', 'sanitize_callback' => array( __CLASS__, 'sanitize_coordinate' ) ),
			'som_longitude'              => array( 'type' => 'number', 'description' => 'GPS Longitude decimal', 'sanitize_callback' => array( __CLASS__, 'sanitize_coordinate' ) ),
			'som_gps_accuracy'           => array( 'type' => 'number', 'description' => 'GPS Accuracy in meters', 'sanitize_callback' => array( __CLASS__, 'sanitize_coordinate' ) ),
			'som_merchant_user_id'       => array( 'type' => 'integer', 'description' => 'WordPress Merchant User ID', 'sanitize_callback' => 'absint' ),
			'som_followup_date'          => array( 'type' => 'string', 'description' => 'Follow-up date', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_notes'                  => array( 'type' => 'string', 'description' => 'Field notes', 'sanitize_callback' => 'sanitize_textarea_field' ),
			'som_concerns'               => array( 'type' => 'string', 'description' => 'Shopkeeper concerns', 'sanitize_callback' => 'sanitize_textarea_field' ),
			'som_details_confirmed'      => array( 'type' => 'boolean', 'description' => 'Details confirmed by merchant', 'sanitize_callback' => 'rest_sanitize_boolean' ),
			'som_details_confirmed_at'   => array( 'type' => 'string', 'description' => 'Confirmation timestamp', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_details_confirmed_by'   => array( 'type' => 'integer', 'description' => 'Confirmation user ID', 'sanitize_callback' => 'absint' ),
			'som_agreement_accepted'     => array( 'type' => 'boolean', 'description' => 'Participation agreement accepted', 'sanitize_callback' => 'rest_sanitize_boolean' ),
			'som_agreement_version'      => array( 'type' => 'string', 'description' => 'Agreement version', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_agreement_accepted_at'  => array( 'type' => 'string', 'description' => 'Agreement acceptance timestamp', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_agreement_accepted_by'  => array( 'type' => 'integer', 'description' => 'Agreement accepting user ID', 'sanitize_callback' => 'absint' ),
			'som_verified'               => array( 'type' => 'boolean', 'description' => 'Verified by admin', 'sanitize_callback' => 'rest_sanitize_boolean' ),
			'som_verified_at'            => array( 'type' => 'string', 'description' => 'Verification timestamp', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_verified_by'            => array( 'type' => 'integer', 'description' => 'Verifying admin user ID', 'sanitize_callback' => 'absint' ),
			'som_rejection_reason'       => array( 'type' => 'string', 'description' => 'Rejection reason', 'sanitize_callback' => 'sanitize_text_field' ),
			'som_rejection_notes'        => array( 'type' => 'string', 'description' => 'Rejection notes', 'sanitize_callback' => 'sanitize_textarea_field' ),
		);

		foreach ( $fields as $meta_key => $args ) {
			register_post_meta(
				'shop',
				$meta_key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => $args['type'],
					'description'       => $args['description'],
					'sanitize_callback' => $args['sanitize_callback'],
				)
			);
		}
	}

	/**
	 * Custom sanitizer for float/coordinate numbers.
	 *
	 * @param mixed $val Value to sanitize.
	 * @return float|string
	 */
	public static function sanitize_coordinate( $val ) {
		if ( '' === $val || null === $val ) {
			return '';
		}
		return is_numeric( $val ) ? (float) $val : '';
	}

	/**
	 * Register the Shop Details Meta Box.
	 */
	public static function add_shop_meta_box() {
		add_meta_box(
			'som_shop_details_meta_box',
			__( 'Shop Details & Merchant Status', 'nearmart' ),
			array( __CLASS__, 'render_shop_meta_box' ),
			'shop',
			'normal',
			'high'
		);
	}

	/**
	 * Render the Shop Details Meta Box form in WP Admin.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_shop_meta_box( $post ) {
		wp_nonce_field( 'som_save_shop_meta', 'som_shop_meta_nonce' );

		$owner_name       = get_post_meta( $post->ID, 'som_owner_name', true );
		$phone_number     = get_post_meta( $post->ID, 'som_phone_number', true );
		$address          = get_post_meta( $post->ID, 'som_address', true );
		$shop_type        = get_post_meta( $post->ID, 'som_shop_type', true );
		$photo_id         = get_post_meta( $post->ID, 'som_shop_photo_id', true );
		$latitude         = get_post_meta( $post->ID, 'som_latitude', true );
		$longitude        = get_post_meta( $post->ID, 'som_longitude', true );
		$gps_accuracy     = get_post_meta( $post->ID, 'som_gps_accuracy', true );
		$merchant_user_id = get_post_meta( $post->ID, 'som_merchant_user_id', true );
		$followup_date    = get_post_meta( $post->ID, 'som_followup_date', true );
		$notes            = get_post_meta( $post->ID, 'som_notes', true );
		$concerns         = get_post_meta( $post->ID, 'som_concerns', true );

		$details_confirmed    = get_post_meta( $post->ID, 'som_details_confirmed', true );
		$confirmed_at        = get_post_meta( $post->ID, 'som_details_confirmed_at', true );
		$agreement_accepted  = get_post_meta( $post->ID, 'som_agreement_accepted', true );
		$agreement_version   = get_post_meta( $post->ID, 'som_agreement_version', true );
		$agreement_at        = get_post_meta( $post->ID, 'som_agreement_accepted_at', true );
		$is_verified         = get_post_meta( $post->ID, 'som_verified', true );
		$verified_at         = get_post_meta( $post->ID, 'som_verified_at', true );
		$verified_by         = get_post_meta( $post->ID, 'som_verified_by', true );
		$rejection_reason    = get_post_meta( $post->ID, 'som_rejection_reason', true );
		$change_requests     = get_post_meta( $post->ID, 'som_change_requests', true );

		$pending_requests = array();
		if ( is_array( $change_requests ) ) {
			foreach ( $change_requests as $req ) {
				if ( isset( $req['status'] ) && 'pending' === $req['status'] ) {
					$pending_requests[] = $req;
				}
			}
		}

		$photo_url = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : '';

		$shop_types = array(
			'Supermarket'        => __( 'Supermarket', 'nearmart' ),
			'Grocery'            => __( 'Grocery Store', 'nearmart' ),
			'Convenience Store'  => __( 'Convenience Store', 'nearmart' ),
			'Bakery'             => __( 'Bakery', 'nearmart' ),
			'Butchery'           => __( 'Butchery', 'nearmart' ),
			'Fruit & Vegetable'  => __( 'Fruit & Vegetable Market', 'nearmart' ),
			'Specialty Store'    => __( 'Specialty Store', 'nearmart' ),
			'Other'              => __( 'Other', 'nearmart' ),
		);

		$merchants = get_users(
			array(
				'role__in' => array( 'merchant', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);
		?>
		<style>
			.som-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px; }
			.som-meta-field { margin-bottom: 12px; }
			.som-meta-field.full-width { grid-column: span 2; }
			.som-meta-field label { display: block; font-weight: 600; margin-bottom: 4px; }
			.som-meta-field input[type="text"],
			.som-meta-field input[type="number"],
			.som-meta-field input[type="date"],
			.som-meta-field select,
			.som-meta-field textarea { width: 100%; }
			.som-status-card-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom: 14px; }
			.som-photo-preview-wrap img { max-width: 150px; height: auto; border: 1px solid #ccc; padding: 4px; background: #fff; display: block; margin-bottom: 8px; }
		</style>

		<!-- Verification & Agreement Status -->
		<div class="som-status-card-box">
			<h4 style="margin:0 0 8px 0;"><?php esc_html_e( 'Verification & Agreement Status', 'nearmart' ); ?></h4>
			<p style="margin:2px 0;">
				<strong><?php esc_html_e( 'Verified by Admin:', 'nearmart' ); ?></strong>
				<?php echo $is_verified ? '✅ ' . sprintf( __( 'Verified on %s by User #%d', 'nearmart' ), esc_html( $verified_at ), $verified_by ) : '❌ ' . __( 'Not Verified', 'nearmart' ); ?>
			</p>
			<p style="margin:2px 0;">
				<strong><?php esc_html_e( 'Details Confirmed by Merchant:', 'nearmart' ); ?></strong>
				<?php echo $details_confirmed ? '✅ ' . sprintf( __( 'Confirmed on %s', 'nearmart' ), esc_html( $confirmed_at ) ) : '❌ ' . __( 'No', 'nearmart' ); ?>
			</p>
			<p style="margin:2px 0;">
				<strong><?php esc_html_e( 'Participation Agreement:', 'nearmart' ); ?></strong>
				<?php echo $agreement_accepted ? '✅ ' . sprintf( __( 'Accepted (%s on %s)', 'nearmart' ), esc_html( $agreement_version ), esc_html( $agreement_at ) ) : '❌ ' . __( 'Pending', 'nearmart' ); ?>
			</p>
			<?php if ( $rejection_reason ) : ?>
				<p style="margin:2px 0; color:#dc2626;">
					<strong><?php esc_html_e( 'Rejection Reason:', 'nearmart' ); ?></strong>
					<?php echo esc_html( $rejection_reason ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Pending Change Requests Box -->
		<?php if ( ! empty( $pending_requests ) ) : ?>
			<div class="som-status-card-box" style="background:#fffbebf5; border-color:#f59e0b;">
				<h4 style="margin:0 0 8px 0; color:#b45309;"><?php esc_html_e( 'Pending Correction Requests from Merchant', 'nearmart' ); ?></h4>
				<ul style="margin:0; padding-left:18px;">
					<?php foreach ( $pending_requests as $req ) : ?>
						<li style="margin-bottom:6px;">
							<strong>[<?php echo esc_html( $req['change_type'] ); ?>]</strong>:
							<?php echo esc_html( $req['description'] ); ?>
							<em>(Submitted on <?php echo esc_html( $req['created_at'] ); ?>)</em>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="som-meta-grid">
			<div class="som-meta-field">
				<label for="som_owner_name"><?php esc_html_e( 'Owner Name', 'nearmart' ); ?></label>
				<input type="text" id="som_owner_name" name="som_owner_name" value="<?php echo esc_attr( $owner_name ); ?>" />
			</div>

			<div class="som-meta-field">
				<label for="som_phone_number"><?php esc_html_e( 'Phone Number', 'nearmart' ); ?></label>
				<input type="text" id="som_phone_number" name="som_phone_number" value="<?php echo esc_attr( $phone_number ); ?>" />
			</div>

			<div class="som-meta-field full-width">
				<label for="som_address"><?php esc_html_e( 'Address', 'nearmart' ); ?></label>
				<textarea id="som_address" name="som_address" rows="3"><?php echo esc_textarea( $address ); ?></textarea>
			</div>

			<div class="som-meta-field">
				<label for="som_shop_type"><?php esc_html_e( 'Shop Type', 'nearmart' ); ?></label>
				<select id="som_shop_type" name="som_shop_type">
					<option value=""><?php esc_html_e( '-- Select Shop Type --', 'nearmart' ); ?></option>
					<?php foreach ( $shop_types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $shop_type, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="som-meta-field">
				<label for="som_merchant_user_id"><?php esc_html_e( 'Merchant User', 'nearmart' ); ?></label>
				<select id="som_merchant_user_id" name="som_merchant_user_id">
					<option value=""><?php esc_html_e( '-- Select Merchant User --', 'nearmart' ); ?></option>
					<?php foreach ( $merchants as $merchant ) : ?>
						<option value="<?php echo esc_attr( $merchant->ID ); ?>" <?php selected( $merchant_user_id, $merchant->ID ); ?>>
							<?php echo esc_html( $merchant->display_name . ' (' . $merchant->user_login . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="som-meta-field">
				<label for="som_latitude"><?php esc_html_e( 'Latitude', 'nearmart' ); ?></label>
				<input type="number" step="any" id="som_latitude" name="som_latitude" value="<?php echo esc_attr( $latitude ); ?>" placeholder="e.g. 12.971598" />
			</div>

			<div class="som-meta-field">
				<label for="som_longitude"><?php esc_html_e( 'Longitude', 'nearmart' ); ?></label>
				<input type="number" step="any" id="som_longitude" name="som_longitude" value="<?php echo esc_attr( $longitude ); ?>" placeholder="e.g. 77.594566" />
			</div>

			<div class="som-meta-field">
				<label for="som_gps_accuracy"><?php esc_html_e( 'GPS Accuracy (meters)', 'nearmart' ); ?></label>
				<input type="number" step="any" id="som_gps_accuracy" name="som_gps_accuracy" value="<?php echo esc_attr( $gps_accuracy ); ?>" placeholder="e.g. 5.2" />
			</div>

			<div class="som-meta-field">
				<label for="som_followup_date"><?php esc_html_e( 'Follow-up Date', 'nearmart' ); ?></label>
				<input type="date" id="som_followup_date" name="som_followup_date" value="<?php echo esc_attr( $followup_date ); ?>" />
			</div>

			<div class="som-meta-field full-width">
				<label for="som_notes"><?php esc_html_e( 'Field Notes', 'nearmart' ); ?></label>
				<textarea id="som_notes" name="som_notes" rows="2"><?php echo esc_textarea( $notes ); ?></textarea>
			</div>

			<div class="som-meta-field full-width">
				<label for="som_concerns"><?php esc_html_e( 'Shopkeeper Concerns', 'nearmart' ); ?></label>
				<textarea id="som_concerns" name="som_concerns" rows="2"><?php echo esc_textarea( $concerns ); ?></textarea>
			</div>

			<div class="som-meta-field full-width">
				<label><?php esc_html_e( 'Shop Photo', 'nearmart' ); ?></label>
				<input type="hidden" id="som_shop_photo_id" name="som_shop_photo_id" value="<?php echo esc_attr( $photo_id ); ?>" />
				<div class="som-photo-preview-wrap">
					<img id="som_shop_photo_preview" src="<?php echo esc_url( $photo_url ); ?>" style="<?php echo $photo_url ? '' : 'display:none;'; ?>" alt="<?php esc_attr_e( 'Shop Photo Preview', 'nearmart' ); ?>" />
				</div>
				<button type="button" class="button button-secondary" id="som_select_photo_btn">
					<?php echo $photo_id ? esc_html__( 'Change Photo', 'nearmart' ) : esc_html__( 'Select Photo', 'nearmart' ); ?>
				</button>
				<button type="button" class="button button-link-delete" id="som_remove_photo_btn" style="<?php echo $photo_id ? '' : 'display:none;'; ?>">
					<?php esc_html_e( 'Remove Photo', 'nearmart' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts for WordPress Media Uploader integration.
	 *
	 * @param string $hook Admin page hook string.
	 */
	public static function enqueue_admin_scripts( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'shop' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		$inline_js = "
		jQuery(document).ready(function($){
			var mediaUploader;
			$('#som_select_photo_btn').on('click', function(e) {
				e.preventDefault();
				if (mediaUploader) {
					mediaUploader.open();
					return;
				}
				mediaUploader = wp.media.frames.file_frame = wp.media({
					title: '" . esc_js( __( 'Select Shop Photo', 'nearmart' ) ) . "',
					button: { text: '" . esc_js( __( 'Use Photo', 'nearmart' ) ) . "' },
					multiple: false
				});
				mediaUploader.on('select', function() {
					var attachment = mediaUploader.state().get('selection').first().toJSON();
					$('#som_shop_photo_id').val(attachment.id);
					var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
					$('#som_shop_photo_preview').attr('src', url).show();
					$('#som_remove_photo_btn').show();
					$('#som_select_photo_btn').text('" . esc_js( __( 'Change Photo', 'nearmart' ) ) . "');
				});
				mediaUploader.open();
			});

			$('#som_remove_photo_btn').on('click', function(e) {
				e.preventDefault();
				$('#som_shop_photo_id').val('');
				$('#som_shop_photo_preview').attr('src', '').hide();
				$(this).hide();
				$('#som_select_photo_btn').text('" . esc_js( __( 'Select Photo', 'nearmart' ) ) . "');
			});
		});
		";

		wp_add_inline_script( 'media-editor', $inline_js );
	}

	/**
	 * Save Shop Details meta fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_shop_meta( $post_id, $post ) {
		if ( ! isset( $_POST['som_shop_meta_nonce'] ) || ! wp_verify_nonce( $_POST['som_shop_meta_nonce'], 'som_save_shop_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['som_owner_name'] ) ) {
			update_post_meta( $post_id, 'som_owner_name', sanitize_text_field( wp_unslash( $_POST['som_owner_name'] ) ) );
		}

		if ( isset( $_POST['som_phone_number'] ) ) {
			update_post_meta( $post_id, 'som_phone_number', sanitize_text_field( wp_unslash( $_POST['som_phone_number'] ) ) );
		}

		if ( isset( $_POST['som_address'] ) ) {
			update_post_meta( $post_id, 'som_address', sanitize_textarea_field( wp_unslash( $_POST['som_address'] ) ) );
		}

		if ( isset( $_POST['som_shop_type'] ) ) {
			update_post_meta( $post_id, 'som_shop_type', sanitize_text_field( wp_unslash( $_POST['som_shop_type'] ) ) );
		}

		if ( isset( $_POST['som_shop_photo_id'] ) ) {
			update_post_meta( $post_id, 'som_shop_photo_id', absint( $_POST['som_shop_photo_id'] ) );
		}

		if ( isset( $_POST['som_latitude'] ) ) {
			$lat = self::sanitize_coordinate( $_POST['som_latitude'] );
			update_post_meta( $post_id, 'som_latitude', $lat );
		}

		if ( isset( $_POST['som_longitude'] ) ) {
			$lng = self::sanitize_coordinate( $_POST['som_longitude'] );
			update_post_meta( $post_id, 'som_longitude', $lng );
		}

		if ( isset( $_POST['som_gps_accuracy'] ) ) {
			$acc = self::sanitize_coordinate( $_POST['som_gps_accuracy'] );
			update_post_meta( $post_id, 'som_gps_accuracy', $acc );
		}

		if ( isset( $_POST['som_merchant_user_id'] ) ) {
			$m_user_id = absint( $_POST['som_merchant_user_id'] );
			update_post_meta( $post_id, 'som_merchant_user_id', $m_user_id );
			if ( $m_user_id ) {
				SOM_Merchant_Manager::link_shop_to_merchant( $post_id, $m_user_id );
			}
		}

		if ( isset( $_POST['som_followup_date'] ) ) {
			update_post_meta( $post_id, 'som_followup_date', sanitize_text_field( wp_unslash( $_POST['som_followup_date'] ) ) );
		}

		if ( isset( $_POST['som_notes'] ) ) {
			update_post_meta( $post_id, 'som_notes', sanitize_textarea_field( wp_unslash( $_POST['som_notes'] ) ) );
		}

		if ( isset( $_POST['som_concerns'] ) ) {
			update_post_meta( $post_id, 'som_concerns', sanitize_textarea_field( wp_unslash( $_POST['som_concerns'] ) ) );
		}

		// Re-evaluate commitment status in case status was set to Verified in admin.
		if ( class_exists( 'SOM_Merchant_Dashboard' ) ) {
			SOM_Merchant_Dashboard::evaluate_commitment_status( $post_id );
		}
	}
}