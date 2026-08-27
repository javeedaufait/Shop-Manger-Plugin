<?php
/**
 * Frontend Merchant Dashboard Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Merchant_Dashboard
 */
class SOM_Merchant_Dashboard {

	/**
	 * Agreement Version constant.
	 */
	const AGREEMENT_VERSION = 'v1.0';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'som_merchant_dashboard', array( __CLASS__, 'render_dashboard_shortcode' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_som_merchant_confirm_details', array( __CLASS__, 'ajax_confirm_details' ) );
		add_action( 'wp_ajax_som_merchant_accept_agreement', array( __CLASS__, 'ajax_accept_agreement' ) );
		add_action( 'wp_ajax_som_merchant_request_change', array( __CLASS__, 'ajax_request_change' ) );
	}

	/**
	 * Evaluate and update shop status to 'committed' if conditions are met.
	 *
	 * Conditions:
	 * 1. Shop has taxonomy status 'verified'.
	 * 2. Merchant has accepted agreement (som_agreement_accepted == 1).
	 *
	 * @param int $shop_id Shop Post ID.
	 * @return bool Whether status was changed to committed.
	 */
	public static function evaluate_commitment_status( $shop_id ) {
		$agreement_accepted = (bool) get_post_meta( $shop_id, 'som_agreement_accepted', true );
		$is_verified        = has_term( 'verified', 'shop_status', $shop_id );

		if ( $is_verified && $agreement_accepted ) {
			wp_set_object_terms( $shop_id, 'committed', 'shop_status' );
			return true;
		}

		return false;
	}

	/**
	 * AJAX endpoint: Confirm Shop Details.
	 */
	public static function ajax_confirm_details() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! SOM_Merchant_Manager::is_user_merchant( $user_id ) && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		$shop_id = get_user_meta( $user_id, 'som_shop_id', true );
		if ( ! $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'No shop linked to your account.', 'shop-onboarding-manager' ) ) );
		}

		$now = current_time( 'mysql' );
		update_post_meta( $shop_id, 'som_details_confirmed', 1 );
		update_post_meta( $shop_id, 'som_details_confirmed_at', $now );
		update_post_meta( $shop_id, 'som_details_confirmed_by', $user_id );

		wp_send_json_success(
			array(
				'message'      => __( 'Shop details successfully confirmed.', 'shop-onboarding-manager' ),
				'confirmed_at' => $now,
			)
		);
	}

	/**
	 * AJAX endpoint: Accept Participation Agreement.
	 */
	public static function ajax_accept_agreement() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! SOM_Merchant_Manager::is_user_merchant( $user_id ) && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		$shop_id = get_user_meta( $user_id, 'som_shop_id', true );
		if ( ! $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'No shop linked to your account.', 'shop-onboarding-manager' ) ) );
		}

		$now = current_time( 'mysql' );
		update_post_meta( $shop_id, 'som_agreement_accepted', 1 );
		update_post_meta( $shop_id, 'som_agreement_version', self::AGREEMENT_VERSION );
		update_post_meta( $shop_id, 'som_agreement_accepted_at', $now );
		update_post_meta( $shop_id, 'som_agreement_accepted_by', $user_id );

		// Check if shop transitions to 'committed'.
		$became_committed = self::evaluate_commitment_status( $shop_id );

		$status_terms = wp_get_post_terms( $shop_id, 'shop_status', array( 'fields' => 'names' ) );
		$current_status = ! empty( $status_terms ) ? $status_terms[0] : __( 'Contacted', 'shop-onboarding-manager' );

		wp_send_json_success(
			array(
				'message'          => __( 'Participation agreement accepted successfully!', 'shop-onboarding-manager' ),
				'became_committed' => $became_committed,
				'current_status'   => $current_status,
				'accepted_at'      => $now,
			)
		);
	}

	/**
	 * AJAX endpoint: Request Data Correction.
	 */
	public static function ajax_request_change() {
		check_ajax_referer( 'som_merchant_dashboard_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! SOM_Merchant_Manager::is_user_merchant( $user_id ) && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'shop-onboarding-manager' ) ) );
		}

		$shop_id = get_user_meta( $user_id, 'som_shop_id', true );
		if ( ! $shop_id ) {
			wp_send_json_error( array( 'message' => __( 'No shop linked to your account.', 'shop-onboarding-manager' ) ) );
		}

		$change_type = isset( $_POST['change_type'] ) ? sanitize_text_field( wp_unslash( $_POST['change_type'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( empty( $change_type ) || empty( $description ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select a field and provide a description of the change.', 'shop-onboarding-manager' ) ) );
		}

		$existing_requests = get_post_meta( $shop_id, 'som_change_requests', true );
		if ( ! is_array( $existing_requests ) ) {
			$existing_requests = array();
		}

		$new_request = array(
			'id'          => uniqid( 'req_' ),
			'user_id'     => $user_id,
			'change_type' => $change_type,
			'description' => $description,
			'status'      => 'pending',
			'created_at'  => current_time( 'mysql' ),
		);

		$existing_requests[] = $new_request;
		update_post_meta( $shop_id, 'som_change_requests', $existing_requests );

		wp_send_json_success(
			array(
				'message' => __( 'Change request submitted for admin review.', 'shop-onboarding-manager' ),
				'request' => $new_request,
			)
		);
	}

	/**
	 * Render [som_merchant_dashboard] shortcode.
	 *
	 * @return string HTML output.
	 */
	public static function render_dashboard_shortcode() {
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! SOM_Merchant_Manager::is_user_merchant( $user_id ) && ! current_user_can( 'administrator' ) ) {
			return '<div class="som-onboarding-card"><div class="som-response-msg error" style="display:block;">' .
				esc_html__( 'Access restricted. Please log in with a merchant account.', 'shop-onboarding-manager' ) .
				' <a href="' . esc_url( home_url( '/merchant-login/' ) ) . '">' . esc_html__( 'Login here', 'shop-onboarding-manager' ) . '</a></div></div>';
		}

		$shop_id = get_user_meta( $user_id, 'som_shop_id', true );
		if ( ! $shop_id ) {
			return '<div class="som-onboarding-card"><div class="som-response-msg error" style="display:block;">' .
				esc_html__( 'No shop is currently linked to your merchant account. Please contact field support.', 'shop-onboarding-manager' ) .
				'</div></div>';
		}

		$shop = get_post( $shop_id );
		if ( ! $shop || 'shop' !== $shop->post_type ) {
			return '<div class="som-onboarding-card"><div class="som-response-msg error" style="display:block;">' .
				esc_html__( 'Linked shop record not found.', 'shop-onboarding-manager' ) .
				'</div></div>';
		}

		// Evaluate commitment status in case verification was recently added.
		self::evaluate_commitment_status( $shop_id );

		// Fetch shop details.
		$shop_name       = get_the_title( $shop_id );
		$owner_name       = get_post_meta( $shop_id, 'som_owner_name', true );
		$phone            = get_post_meta( $shop_id, 'som_phone_number', true );
		$address          = get_post_meta( $shop_id, 'som_address', true );
		$shop_type        = get_post_meta( $shop_id, 'som_shop_type', true );
		$photo_id         = get_post_meta( $shop_id, 'som_shop_photo_id', true );
		$photo_url        = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : '';

		// Fetch status.
		$status_terms    = wp_get_post_terms( $shop_id, 'shop_status', array( 'fields' => 'names' ) );
		$verification_status = ! empty( $status_terms ) ? $status_terms[0] : __( 'Contacted', 'shop-onboarding-manager' );
		$is_verified     = has_term( 'verified', 'shop_status', $shop_id ) || has_term( 'committed', 'shop_status', $shop_id );

		// Confirmation & Agreement states.
		$details_confirmed = (bool) get_post_meta( $shop_id, 'som_details_confirmed', true );
		$confirmed_at     = get_post_meta( $shop_id, 'som_details_confirmed_at', true );

		$agreement_accepted = (bool) get_post_meta( $shop_id, 'som_agreement_accepted', true );
		$accepted_at       = get_post_meta( $shop_id, 'som_agreement_accepted_at', true );

		// Change requests.
		$change_requests  = get_post_meta( $shop_id, 'som_change_requests', true );
		if ( ! is_array( $change_requests ) ) {
			$change_requests = array();
		}

		$nonce    = wp_create_nonce( 'som_merchant_dashboard_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );

		ob_start();
		?>
		<div class="som-merchant-dashboard-wrap">
			<!-- Header -->
			<div class="som-dashboard-header">
				<div class="som-header-title">
					<h2>🏪 <?php echo esc_html( $shop_name ); ?></h2>
					<p><?php printf( esc_html__( 'Merchant Portal • Logged in as %s', 'shop-onboarding-manager' ), '<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>' ); ?></p>
				</div>
				<div class="som-status-badges">
					<span class="som-badge som-badge-verification <?php echo strtolower( $verification_status ); ?>">
						<?php printf( esc_html__( 'Status: %s', 'shop-onboarding-manager' ), esc_html( $verification_status ) ); ?>
					</span>
					<span class="som-badge <?php echo $agreement_accepted ? 'som-badge-success' : 'som-badge-pending'; ?>">
						<?php echo $agreement_accepted ? esc_html__( 'Agreement Signed', 'shop-onboarding-manager' ) : esc_html__( 'Agreement Pending', 'shop-onboarding-manager' ); ?>
					</span>
				</div>
			</div>

			<div class="som-dashboard-grid">
				<!-- Card 1: Shop Information Summary -->
				<div class="som-dash-card">
					<h3>📋 <?php esc_html_e( 'Shop Information', 'shop-onboarding-manager' ); ?></h3>

					<?php if ( $photo_url ) : ?>
						<div class="som-dash-photo">
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $shop_name ); ?>" />
						</div>
					<?php endif; ?>

					<div class="som-info-list">
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Shop Name:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $shop_name ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Owner Name:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $owner_name ? $owner_name : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Phone:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $phone ? $phone : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Address:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $address ? $address : '—' ); ?></span>
						</div>
						<div class="som-info-item">
							<span class="som-info-label"><?php esc_html_e( 'Shop Type:', 'shop-onboarding-manager' ); ?></span>
							<span class="som-info-val"><?php echo esc_html( $shop_type ? $shop_type : '—' ); ?></span>
						</div>
					</div>

					<!-- Feature 1: Confirm Details Form -->
					<div class="som-action-box">
						<h4>1. <?php esc_html_e( 'Confirm Shop Information', 'shop-onboarding-manager' ); ?></h4>
						<?php if ( $details_confirmed ) : ?>
							<div class="som-response-msg success" style="display:block;">
								✓ <?php printf( esc_html__( 'Confirmed on %s', 'shop-onboarding-manager' ), esc_html( date_i18n( 'M j, Y g:i a', strtotime( $confirmed_at ) ) ) ); ?>
							</div>
						<?php else : ?>
							<p><?php esc_html_e( 'Please verify that the business details above are accurate.', 'shop-onboarding-manager' ); ?></p>
							<button type="button" id="som_btn_confirm_details" class="som-submit-btn som-btn-secondary">
								✓ <?php esc_html_e( 'I confirm my shop details are correct', 'shop-onboarding-manager' ); ?>
							</button>
							<div id="som_confirm_msg" class="som-response-msg"></div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Card 2: Participation Agreement & Commitment -->
				<div class="som-dash-card">
					<h3>📝 <?php esc_html_e( 'Participation Agreement', 'shop-onboarding-manager' ); ?></h3>

					<div class="som-agreement-box">
						<p class="som-agreement-text">
							"<?php esc_html_e( 'I agree to participate as a shop partner in the platform and allow my shop to be listed as a participating shop when the platform launches.', 'shop-onboarding-manager' ); ?>"
						</p>
						<span class="som-agreement-ver"><?php printf( esc_html__( 'Terms Version: %s', 'shop-onboarding-manager' ), self::AGREEMENT_VERSION ); ?></span>
					</div>

					<?php if ( $agreement_accepted ) : ?>
						<div class="som-response-msg success" style="display:block; margin-top: 14px;">
							✓ <?php printf( esc_html__( 'Agreement accepted on %s', 'shop-onboarding-manager' ), esc_html( date_i18n( 'M j, Y g:i a', strtotime( $accepted_at ) ) ) ); ?>
						</div>

						<div class="som-commitment-notice">
							<?php if ( 'committed' === strtolower( $verification_status ) ) : ?>
								<div class="som-alert-committed">
									🎉 <strong><?php esc_html_e( 'Shop Committed!', 'shop-onboarding-manager' ); ?></strong>
									<p><?php esc_html_e( 'Your shop is verified and your participation agreement is confirmed. Your shop will be featured at launch.', 'shop-onboarding-manager' ); ?></p>
								</div>
							<?php else : ?>
								<div class="som-alert-info">
									ℹ️ <strong><?php esc_html_e( 'Verification Pending', 'shop-onboarding-manager' ); ?></strong>
									<p><?php esc_html_e( 'Agreement is signed. Your shop will automatically become Committed once our field team completes verification.', 'shop-onboarding-manager' ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<div class="som-form-group" style="margin-top: 14px;">
							<label class="som-checkbox-label">
								<input type="checkbox" id="som_chk_agreement" value="1" />
								<span><?php esc_html_e( 'I accept the participation terms above', 'shop-onboarding-manager' ); ?></span>
							</label>
						</div>

						<button type="button" id="som_btn_accept_agreement" class="som-submit-btn" disabled>
							🖊️ <?php esc_html_e( 'Accept Agreement', 'shop-onboarding-manager' ); ?>
						</button>
						<div id="som_agreement_msg" class="som-response-msg"></div>
					<?php endif; ?>
				</div>

				<!-- Card 3: Request Change / Correction -->
				<div class="som-dash-card full-width">
					<h3>🔧 <?php esc_html_e( 'Request Information Correction', 'shop-onboarding-manager' ); ?></h3>
					<p><?php esc_html_e( 'Need to update your owner name, address, or phone number? Submit a change request for admin review.', 'shop-onboarding-manager' ); ?></p>

					<form id="som_change_request_form">
						<div class="som-form-row">
							<div class="som-form-group">
								<label for="som_cr_type" class="som-label"><?php esc_html_e( 'Field to Change', 'shop-onboarding-manager' ); ?></label>
								<select id="som_cr_type" name="change_type" class="som-select" required>
									<option value=""><?php esc_html_e( '-- Select Field --', 'shop-onboarding-manager' ); ?></option>
									<option value="Shop Name"><?php esc_html_e( 'Shop Name', 'shop-onboarding-manager' ); ?></option>
									<option value="Owner Name"><?php esc_html_e( 'Owner Name', 'shop-onboarding-manager' ); ?></option>
									<option value="Phone Number"><?php esc_html_e( 'Phone Number', 'shop-onboarding-manager' ); ?></option>
									<option value="Address"><?php esc_html_e( 'Address', 'shop-onboarding-manager' ); ?></option>
									<option value="Shop Type"><?php esc_html_e( 'Shop Type', 'shop-onboarding-manager' ); ?></option>
									<option value="Location/GPS"><?php esc_html_e( 'Location / GPS', 'shop-onboarding-manager' ); ?></option>
									<option value="Other"><?php esc_html_e( 'Other Request', 'shop-onboarding-manager' ); ?></option>
								</select>
							</div>
						</div>

						<div class="som-form-group">
							<label for="som_cr_desc" class="som-label"><?php esc_html_e( 'Description of Change', 'shop-onboarding-manager' ); ?></label>
							<textarea id="som_cr_desc" name="description" class="som-textarea" rows="2" required placeholder="<?php esc_attr_e( 'Describe the correction requested...', 'shop-onboarding-manager' ); ?>"></textarea>
						</div>

						<button type="submit" id="som_btn_request_change" class="som-submit-btn som-btn-outline">
							📨 <?php esc_html_e( 'Submit Correction Request', 'shop-onboarding-manager' ); ?>
						</button>
						<div id="som_cr_msg" class="som-response-msg"></div>
					</form>

					<?php if ( ! empty( $change_requests ) ) : ?>
						<div class="som-cr-history">
							<h4><?php esc_html_e( 'Submitted Requests History', 'shop-onboarding-manager' ); ?></h4>
							<div class="som-cr-list">
								<?php foreach ( array_reverse( $change_requests ) as $req ) : ?>
									<div class="som-cr-item">
										<div class="som-cr-head">
											<strong><?php echo esc_html( $req['change_type'] ); ?></strong>
											<span class="som-cr-status status-<?php echo esc_attr( $req['status'] ); ?>"><?php echo esc_html( ucfirst( $req['status'] ) ); ?></span>
										</div>
										<p><?php echo esc_html( $req['description'] ); ?></p>
										<small><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $req['created_at'] ) ) ); ?></small>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<script>
		(function() {
			function initDashboardScript() {
				var ajaxUrl = '<?php echo esc_url( $ajax_url ); ?>';
				var nonce   = '<?php echo esc_js( $nonce ); ?>';

				var btnConfirm = document.getElementById('som_btn_confirm_details');
				if (btnConfirm) {
					btnConfirm.addEventListener('click', function(e) {
						e.preventDefault();
						btnConfirm.disabled = true;
						btnConfirm.textContent = 'Confirming...';
						var msg = document.getElementById('som_confirm_msg');

						var formData = new FormData();
						formData.append('action', 'som_merchant_confirm_details');
						formData.append('nonce', nonce);

						fetch(ajaxUrl, {
							method: 'POST',
							body: formData
						})
						.then(function(res) { return res.json(); })
						.then(function(res) {
							if (res.success) {
								msg.className = 'som-response-msg success';
								msg.style.display = 'block';
								msg.textContent = res.data.message;
								setTimeout(function() { location.reload(); }, 600);
							} else {
								btnConfirm.disabled = false;
								btnConfirm.textContent = '✓ I confirm my shop details are correct';
								msg.className = 'som-response-msg error';
								msg.style.display = 'block';
								msg.textContent = res.data.message;
							}
						})
						.catch(function() {
							btnConfirm.disabled = false;
							btnConfirm.textContent = '✓ I confirm my shop details are correct';
						});
					});
				}

				var chkAgree = document.getElementById('som_chk_agreement');
				var btnAgree = document.getElementById('som_btn_accept_agreement');
				if (chkAgree && btnAgree) {
					chkAgree.addEventListener('change', function() {
						btnAgree.disabled = !chkAgree.checked;
					});
					btnAgree.addEventListener('click', function(e) {
						e.preventDefault();
						btnAgree.disabled = true;
						btnAgree.textContent = 'Processing...';
						var msg = document.getElementById('som_agreement_msg');

						var formData = new FormData();
						formData.append('action', 'som_merchant_accept_agreement');
						formData.append('nonce', nonce);

						fetch(ajaxUrl, {
							method: 'POST',
							body: formData
						})
						.then(function(res) { return res.json(); })
						.then(function(res) {
							if (res.success) {
								msg.className = 'som-response-msg success';
								msg.style.display = 'block';
								msg.textContent = res.data.message;
								setTimeout(function() { location.reload(); }, 600);
							} else {
								btnAgree.disabled = false;
								btnAgree.textContent = '🖊️ Accept Agreement';
								msg.className = 'som-response-msg error';
								msg.style.display = 'block';
								msg.textContent = res.data.message;
							}
						})
						.catch(function() {
							btnAgree.disabled = false;
							btnAgree.textContent = '🖊️ Accept Agreement';
						});
					});
				}

				var formCr = document.getElementById('som_change_request_form');
				if (formCr) {
					formCr.addEventListener('submit', function(e) {
						e.preventDefault();
						var btnCr = document.getElementById('som_btn_request_change');
						var msgCr = document.getElementById('som_cr_msg');
						btnCr.disabled = true;
						btnCr.textContent = 'Submitting...';

						var formData = new FormData(formCr);
						formData.append('action', 'som_merchant_request_change');
						formData.append('nonce', nonce);

						fetch(ajaxUrl, {
							method: 'POST',
							body: formData
						})
						.then(function(res) { return res.json(); })
						.then(function(res) {
							btnCr.disabled = false;
							btnCr.textContent = '📨 Submit Correction Request';
							if (res.success) {
								msgCr.className = 'som-response-msg success';
								msgCr.style.display = 'block';
								msgCr.textContent = res.data.message;
								formCr.reset();
								setTimeout(function() { location.reload(); }, 800);
							} else {
								msgCr.className = 'som-response-msg error';
								msgCr.style.display = 'block';
								msgCr.textContent = res.data.message;
							}
						})
						.catch(function() {
							btnCr.disabled = false;
							btnCr.textContent = '📨 Submit Correction Request';
						});
					});
				}
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initDashboardScript);
			} else {
				initDashboardScript();
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}