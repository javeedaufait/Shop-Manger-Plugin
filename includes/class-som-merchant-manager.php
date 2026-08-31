<?php
/**
 * Merchant Accounts Management Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Merchant_Manager
 */
class SOM_Merchant_Manager {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_shortcode( 'som_merchant_login', array( __CLASS__, 'render_login_shortcode' ) );
		add_shortcode( 'som_join_nearmart_page', array( __CLASS__, 'render_join_shortcode' ) );

		// AJAX endpoints for login & account creation.
		add_action( 'wp_ajax_nopriv_som_merchant_login', array( __CLASS__, 'ajax_merchant_login' ) );
		add_action( 'wp_ajax_som_merchant_login', array( __CLASS__, 'ajax_merchant_login' ) );
		add_action( 'wp_ajax_som_create_merchant_account', array( __CLASS__, 'ajax_create_merchant_account' ) );

		// Enforce Admin Restrictions & Admin Bar removal for Merchants.
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin_access' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_merchant_admin_bar' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue assets for login shortcode.
	 */
	public static function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'som_merchant_login' ) || is_page( 'merchant-login' ) ) ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_style(
				'som-frontend-style',
				SOM_PLUGIN_URL . 'assets/css/som-frontend.css',
				array(),
				SOM_VERSION
			);
		}
	}

	/**
	 * Restrict merchant role users from accessing wp-admin.
	 */
	public static function restrict_admin_access() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( is_user_logged_in() && self::is_user_merchant( get_current_user_id() ) && ! current_user_can( 'administrator' ) ) {
			wp_safe_redirect( home_url( '/merchant-dashboard/' ) );
			exit;
		}
	}

	/**
	 * Hide Admin Bar for merchant role users.
	 *
	 * @param bool $show Current admin bar visibility.
	 * @return bool
	 */
	public static function hide_merchant_admin_bar( $show ) {
		if ( is_user_logged_in() && self::is_user_merchant( get_current_user_id() ) && ! current_user_can( 'administrator' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Check if a user has the merchant role.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_user_merchant( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'merchant', (array) $user->roles, true );
	}

	/**
	 * Link a Shop CPT post with a Merchant User ID (2-way link).
	 *
	 * @param int $shop_id Post ID of the shop.
	 * @param int $merchant_user_id WordPress User ID of the merchant.
	 * @return bool
	 */
	public static function link_shop_to_merchant( $shop_id, $merchant_user_id ) {
		if ( ! $shop_id || ! $merchant_user_id ) {
			return false;
		}

		// Store post meta on shop.
		update_post_meta( $shop_id, 'som_merchant_user_id', absint( $merchant_user_id ) );

		// Store user meta on merchant.
		update_user_meta( $merchant_user_id, 'som_shop_id', absint( $shop_id ) );

		return true;
	}

	/**
	 * Create a new Merchant user programmatically.
	 *
	 * @param string $username Username.
	 * @param string $password Password.
	 * @param string $email Email address.
	 * @param int    $shop_id Optional shop ID to link.
	 * @return int|WP_Error User ID or WP_Error.
	 */
	public static function create_merchant( $username, $password, $email = '', $shop_id = 0 ) {
		if ( empty( $username ) || empty( $password ) ) {
			return new WP_Error( 'missing_fields', __( 'Username and password are required.', 'nearmart' ) );
		}

		if ( username_exists( $username ) ) {
			return new WP_Error( 'username_exists', __( 'A merchant with this username already exists.', 'nearmart' ) );
		}

		if ( ! empty( $email ) && email_exists( $email ) ) {
			return new WP_Error( 'email_exists', __( 'A merchant with this email address already exists.', 'nearmart' ) );
		}

		// Generate fallback email if none provided.
		if ( empty( $email ) ) {
			$email = $username . '@merchant.nearmart.local';
		}

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Set role to merchant.
		$user = new WP_User( $user_id );
		$user->set_role( 'merchant' );

		// If shop ID provided, link them.
		if ( $shop_id ) {
			self::link_shop_to_merchant( $shop_id, $user_id );
		}

		return $user_id;
	}

	/**
	 * AJAX handler for merchant login.
	 */
	public static function ajax_merchant_login() {
		check_ajax_referer( 'som_merchant_login_nonce', 'nonce' );

		$username = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? $_POST['pwd'] : '';

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter both username and password.', 'nearmart' ) ) );
		}

		$credentials = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		);

		$user = wp_signon( $credentials, is_ssl() );

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid username or password.', 'nearmart' ) ) );
		}

		// Verify user is a merchant or admin.
		if ( ! self::is_user_merchant( $user->ID ) && ! in_array( 'administrator', (array) $user->roles, true ) ) {
			wp_logout();
			wp_send_json_error( array( 'message' => __( 'Account is not registered as a Merchant.', 'nearmart' ) ) );
		}

		$shop_id = get_user_meta( $user->ID, 'som_shop_id', true );
		$shop_name = $shop_id ? get_the_title( $shop_id ) : __( 'Unassigned', 'nearmart' );

		wp_send_json_success(
			array(
				'message'      => __( 'Login successful!', 'nearmart' ),
				'user'         => $user->display_name,
				'shop_id'      => $shop_id,
				'shop_name'    => $shop_name,
				'redirect_url' => home_url( '/merchant-dashboard/' ),
			)
		);
	}

	/**
	 * AJAX handler for creating a merchant account (authorized field team / admins).
	 */
	public static function ajax_create_merchant_account() {
		check_ajax_referer( 'som_frontend_onboard_nonce', 'nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nearmart' ) ) );
		}

		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? $_POST['password'] : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$shop_id  = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;

		$result = self::create_merchant( $username, $password, $email, $shop_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Merchant account created successfully!', 'nearmart' ),
				'user_id' => $result,
			)
		);
	}

	/**
	 * Render [som_join_nearmart_page] public shopkeeper info shortcode.
	 *
	 * @return string HTML content.
	 */
	public static function render_join_shortcode() {
		ob_start();
		?>
		<div class="nm-join-page-wrap" style="max-width: 840px; margin: 20px auto; font-family: var(--font-main, sans-serif);">
			<div class="nm-join-hero-card" style="background: linear-gradient(135deg, #15803D 0%, #16A34A 100%); color: #ffffff; padding: 40px 24px; border-radius: 16px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.08);">
				<span style="background: rgba(255,255,255,0.2); color:#ffffff; padding: 4px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display:inline-block; margin-bottom:12px;">
					<?php esc_html_e( 'For Shop Owners', 'nearmart' ); ?>
				</span>
				<h1 style="color: #ffffff; font-size: 2.4rem; font-weight: 800; margin: 0 0 14px 0; line-height: 1.2;">
					<?php esc_html_e( 'Join NearMart as a Partner Shop', 'nearmart' ); ?>
				</h1>
				<p style="color: rgba(255,255,255,0.92); font-size: 1.15rem; max-width: 640px; margin: 0 auto; line-height: 1.6;">
					<?php esc_html_e( 'NearMart connects local supermarkets and grocery stores directly with nearby neighborhood customers for convenient, queue-free pickup.', 'nearmart' ); ?>
				</p>
			</div>

			<div class="nm-join-benefits-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin: 36px 0;">
				<div style="background: #ffffff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
					<div style="font-size: 2.2rem; margin-bottom: 12px;">👥</div>
					<h3 style="font-size: 1.15rem; margin: 0 0 6px 0; color: #172033;"><?php esc_html_e( 'Reach Nearby Customers', 'nearmart' ); ?></h3>
					<p style="font-size: 0.95rem; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e( 'Get discovered by local shoppers living right in your neighborhood.', 'nearmart' ); ?></p>
				</div>

				<div style="background: #ffffff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
					<div style="font-size: 2.2rem; margin-bottom: 12px;">📲</div>
					<h3 style="font-size: 1.15rem; margin: 0 0 6px 0; color: #172033;"><?php esc_html_e( 'Get Orders Through NearMart', 'nearmart' ); ?></h3>
					<p style="font-size: 0.95rem; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e( 'Receive advance grocery orders so you can pack items ahead of time.', 'nearmart' ); ?></p>
				</div>

				<div style="background: #ffffff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
					<div style="font-size: 2.2rem; margin-bottom: 12px;">🚀</div>
					<h3 style="font-size: 1.15rem; margin: 0 0 6px 0; color: #172033;"><?php esc_html_e( 'No Setup Fee', 'nearmart' ); ?></h3>
					<p style="font-size: 0.95rem; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e( 'Free to join during our initial launch for early partner stores.', 'nearmart' ); ?></p>
				</div>

				<div style="background: #ffffff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
					<div style="font-size: 2.2rem; margin-bottom: 12px;">💻</div>
					<h3 style="font-size: 1.15rem; margin: 0 0 6px 0; color: #172033;"><?php esc_html_e( 'Easy Merchant Portal', 'nearmart' ); ?></h3>
					<p style="font-size: 0.95rem; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e( 'Simple mobile-friendly dashboard to confirm details and agreements.', 'nearmart' ); ?></p>
				</div>
			</div>

			<div class="nm-join-contact-card" style="background: #ffffff; border: 2px solid #16A34A; border-radius: 16px; padding: 36px 24px; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
				<h2 style="font-size: 1.8rem; font-weight: 800; margin: 0 0 10px 0; color: #172033;"><?php esc_html_e( 'Interested? Contact Our Team', 'nearmart' ); ?></h2>
				<p style="font-size: 1.05rem; color: #64748B; margin: 0 0 24px 0;"><?php esc_html_e( 'Our field team will visit your shop to set up your partner account.', 'nearmart' ); ?></p>

				<div style="display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;">
					<a href="https://wa.me/919123456789?text=Hello%20NearMart%20Team%2C%20I%20am%20a%20shopkeeper%20interested%20in%20joining%20NearMart." target="_blank" style="background: #25D366; color: #ffffff; text-decoration: none; padding: 14px 24px; border-radius: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem;">
						💬 <?php esc_html_e( 'Chat on WhatsApp (+91 91234 56789)', 'nearmart' ); ?>
					</a>
					<a href="mailto:support@nearmart.local" style="background: #2563EB; color: #ffffff; text-decoration: none; padding: 14px 24px; border-radius: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem;">
						✉️ <?php esc_html_e( 'Email Support (support@nearmart.local)', 'nearmart' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render [som_merchant_login] shortcode.
	 *
	 * @return string HTML content.
	 */
	public static function render_login_shortcode() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'som-frontend-style', SOM_PLUGIN_URL . 'assets/css/som-frontend.css', array(), SOM_VERSION );

		$server_msg = '';
		$server_error = false;

		// Handle direct POST fallback submission.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['log'], $_POST['pwd'], $_POST['som_m_login_submit'] ) ) {
			$username = sanitize_user( wp_unslash( $_POST['log'] ) );
			$password = $_POST['pwd'];

			if ( ! empty( $username ) && ! empty( $password ) ) {
				$credentials = array(
					'user_login'    => $username,
					'user_password' => $password,
					'remember'      => true,
				);

				$user = wp_signon( $credentials, is_ssl() );

				if ( is_wp_error( $user ) ) {
					$server_msg = __( 'Invalid username or password.', 'nearmart' );
					$server_error = true;
				} elseif ( ! self::is_user_merchant( $user->ID ) && ! in_array( 'administrator', (array) $user->roles, true ) ) {
					wp_logout();
					$server_msg = __( 'Account is not registered as a Merchant.', 'nearmart' );
					$server_error = true;
				} else {
					wp_safe_redirect( home_url( '/merchant-dashboard/' ) );
					exit;
				}
			} else {
				$server_msg = __( 'Please enter both username and password.', 'nearmart' );
				$server_error = true;
			}
		}

		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$user_id = $current_user->ID;
			$shop_id = get_user_meta( $user_id, 'som_shop_id', true );
			$shop_title = $shop_id ? get_the_title( $shop_id ) : __( 'No shop linked yet', 'nearmart' );
			$owner_name = $shop_id ? get_post_meta( $shop_id, 'som_owner_name', true ) : '';
			$phone      = $shop_id ? get_post_meta( $shop_id, 'som_phone_number', true ) : '';
			$logout_url = wp_logout_url( home_url( '/merchant-login/' ) );
			$dashboard_url = home_url( '/merchant-dashboard/' );

			ob_start();
			?>
			<div class="som-merchant-card">
				<div class="som-card-header">
					<h2><?php esc_html_e( 'Merchant Portal', 'nearmart' ); ?></h2>
					<p><?php printf( esc_html__( 'Logged in as %s', 'nearmart' ), '<strong>' . esc_html( $current_user->display_name ) . '</strong>' ); ?></p>
				</div>
				<div class="som-merchant-info">
					<div class="som-info-row">
						<span><?php esc_html_e( 'Linked Shop:', 'nearmart' ); ?></span>
						<strong><?php echo esc_html( $shop_title ); ?></strong>
					</div>
					<?php if ( $owner_name ) : ?>
					<div class="som-info-row">
						<span><?php esc_html_e( 'Owner:', 'nearmart' ); ?></span>
						<strong><?php echo esc_html( $owner_name ); ?></strong>
					</div>
					<?php endif; ?>
					<?php if ( $phone ) : ?>
					<div class="som-info-row">
						<span><?php esc_html_e( 'Phone:', 'nearmart' ); ?></span>
						<strong><?php echo esc_html( $phone ); ?></strong>
					</div>
					<?php endif; ?>
				</div>
				<div class="som-card-actions" style="display:flex; gap:12px; align-items:center;">
					<a href="<?php echo esc_url( $dashboard_url ); ?>" class="som-submit-btn som-btn-secondary" style="flex:2; text-decoration:none; text-align:center; display:flex; align-items:center; justify-content:center; padding:12px 16px; height:48px; min-height:48px; font-size:1rem; white-space:nowrap;"><?php esc_html_e( 'Go to Dashboard →', 'nearmart' ); ?></a>
					<a href="<?php echo esc_url( $logout_url ); ?>" class="som-btn-logout" style="flex:1; text-decoration:none; text-align:center; display:flex; align-items:center; justify-content:center; padding:12px 16px; height:48px; min-height:48px; font-size:1rem; white-space:nowrap; margin-top:0;"><?php esc_html_e( 'Log Out', 'nearmart' ); ?></a>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$nonce = wp_create_nonce( 'som_merchant_login_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );

		ob_start();
		?>
		<div class="som-merchant-card">
			<div class="som-card-header">
				<h2>🔑 <?php esc_html_e( 'Merchant Login', 'nearmart' ); ?></h2>
				<p><?php esc_html_e( 'Access your shop onboarding portal', 'nearmart' ); ?></p>
			</div>

			<?php if ( ! empty( $server_msg ) ) : ?>
				<div class="som-response-msg <?php echo $server_error ? 'error' : 'success'; ?>" style="display: block; margin-bottom: 16px;">
					<?php echo esc_html( $server_msg ); ?>
				</div>
			<?php endif; ?>

			<form id="som_merchant_login_form" method="post" action="">
				<input type="hidden" name="som_m_login_submit" value="1" />
				<div class="som-form-group">
					<label for="som_m_username" class="som-label"><?php esc_html_e( 'Username', 'nearmart' ); ?></label>
					<input type="text" id="som_m_username" name="log" class="som-input" required placeholder="<?php esc_attr_e( 'Enter username', 'nearmart' ); ?>" />
				</div>

				<div class="som-form-group">
					<label for="som_m_password" class="som-label"><?php esc_html_e( 'Password', 'nearmart' ); ?></label>
					<input type="password" id="som_m_password" name="pwd" class="som-input" required placeholder="<?php esc_attr_e( 'Enter password', 'nearmart' ); ?>" />
				</div>

				<div class="som-form-actions">
					<button type="submit" id="som_btn_merchant_login" class="som-submit-btn">
						<?php esc_html_e( 'Log In', 'nearmart' ); ?>
					</button>
				</div>

				<div id="som_login_msg" class="som-response-msg"></div>
			</form>
		</div>

		<script>
		if (typeof jQuery !== 'undefined') {
			jQuery(document).ready(function($) {
				$('#som_merchant_login_form').on('submit', function(e) {
					e.preventDefault();
					var $btn = $('#som_btn_merchant_login');
					var $msg = $('#som_login_msg');
					$btn.prop('disabled', true).text('Logging in...');
					$msg.hide();

					$.ajax({
						url: '<?php echo esc_url( $ajax_url ); ?>',
						type: 'POST',
						data: {
							action: 'som_merchant_login',
							nonce: '<?php echo esc_js( $nonce ); ?>',
							log: $('#som_m_username').val(),
							pwd: $('#som_m_password').val()
						},
						success: function(res) {
							$btn.prop('disabled', false).text('Log In');
							if (res.success) {
								$msg.removeClass('error').addClass('success').text(res.data.message).show();
								setTimeout(function() {
									window.location.href = res.data.redirect_url || '<?php echo esc_js( home_url( '/merchant-dashboard/' ) ); ?>';
								}, 600);
							} else {
								$msg.removeClass('success').addClass('error').text(res.data.message).show();
							}
						},
						error: function() {
							$btn.prop('disabled', false).text('Log In');
							$msg.removeClass('success').addClass('error').text('Server error. Please try again.').show();
						}
					});
				});
			});
		}
		</script>
		<?php
		return ob_get_clean();
	}
}