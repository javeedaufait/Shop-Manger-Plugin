<?php
/**
 * Admin Management System Module.
 *
 * @package Shop_Onboarding_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOM_Admin_Manager
 */
class SOM_Admin_Manager {

	/**
	 * Rejection reasons list.
	 */
	const REJECTION_REASONS = array(
		'not_interested'        => 'Not interested',
		'too_much_staff_work'   => 'Too much staff work',
		'already_using_whatsapp'=> 'Already using WhatsApp',
		'no_perceived_benefit'   => "Doesn't see enough benefit",
		'other'                 => 'Other',
	);

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
	}

	/**
	 * Register Admin Menu Page.
	 */
	public static function register_admin_menu() {
		add_menu_page(
			__( 'Shop Onboarding', 'shop-onboarding-manager' ),
			__( 'Shop Onboarding', 'shop-onboarding-manager' ),
			'manage_options',
			'som-admin',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-store',
			56
		);
	}

	/**
	 * Apply approved change request values to actual post meta / post data.
	 *
	 * @param int   $shop_id Shop Post ID.
	 * @param array $req Change request object.
	 */
	public static function apply_approved_change_request( $shop_id, $req ) {
		$type = strtolower( trim( $req['change_type'] ) );
		$val  = trim( $req['description'] );

		if ( strpos( $type, 'owner' ) !== false ) {
			update_post_meta( $shop_id, 'som_owner_name', sanitize_text_field( $val ) );
		} elseif ( strpos( $type, 'shop name' ) !== false ) {
			wp_update_post(
				array(
					'ID'         => $shop_id,
					'post_title' => sanitize_text_field( $val ),
				)
			);
		} elseif ( strpos( $type, 'phone' ) !== false ) {
			update_post_meta( $shop_id, 'som_phone_number', sanitize_text_field( $val ) );
		} elseif ( strpos( $type, 'address' ) !== false ) {
			update_post_meta( $shop_id, 'som_address', sanitize_textarea_field( $val ) );
		} elseif ( strpos( $type, 'type' ) !== false ) {
			update_post_meta( $shop_id, 'som_shop_type', sanitize_text_field( $val ) );
		}
	}

	/**
	 * Handle admin action form submissions (Verify, Reject, Change Status, Resolve Change Request).
	 */
	public static function handle_admin_actions() {
		if ( ! isset( $_POST['som_admin_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'som_admin_action_nonce', 'som_nonce' );

		$action  = sanitize_text_field( wp_unslash( $_POST['som_admin_action'] ) );
		$shop_id = isset( $_POST['shop_id'] ) ? absint( $_POST['shop_id'] ) : 0;
		$user_id = get_current_user_id();

		if ( 'verify_shop' === $action && $shop_id ) {
			update_post_meta( $shop_id, 'som_verified', 1 );
			update_post_meta( $shop_id, 'som_verified_at', current_time( 'mysql' ) );
			update_post_meta( $shop_id, 'som_verified_by', $user_id );

			// Set taxonomy status to verified.
			wp_set_object_terms( $shop_id, 'verified', 'shop_status' );

			// Evaluate commitment transition.
			if ( class_exists( 'SOM_Merchant_Dashboard' ) ) {
				SOM_Merchant_Dashboard::evaluate_commitment_status( $shop_id );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'som-admin', 'tab' => 'tracker', 'msg' => 'verified' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'reject_shop' === $action && $shop_id ) {
			$reason = isset( $_POST['rejection_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';
			$notes  = isset( $_POST['rejection_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_notes'] ) ) : '';

			update_post_meta( $shop_id, 'som_rejection_reason', $reason );
			update_post_meta( $shop_id, 'som_rejection_notes', $notes );
			wp_set_object_terms( $shop_id, 'rejected', 'shop_status' );

			wp_safe_redirect( add_query_arg( array( 'page' => 'som-admin', 'tab' => 'tracker', 'msg' => 'rejected' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'change_status' === $action && $shop_id ) {
			$new_status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
			if ( ! empty( $new_status ) && term_exists( $new_status, 'shop_status' ) ) {
				wp_set_object_terms( $shop_id, $new_status, 'shop_status' );
				if ( 'verified' === $new_status ) {
					update_post_meta( $shop_id, 'som_verified', 1 );
					update_post_meta( $shop_id, 'som_verified_at', current_time( 'mysql' ) );
					update_post_meta( $shop_id, 'som_verified_by', $user_id );
				}
				if ( class_exists( 'SOM_Merchant_Dashboard' ) ) {
					SOM_Merchant_Dashboard::evaluate_commitment_status( $shop_id );
				}
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'som-admin', 'tab' => 'tracker', 'msg' => 'status_changed' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'resolve_change_request' === $action && $shop_id ) {
			$req_id     = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
			$new_status = isset( $_POST['request_status'] ) ? sanitize_key( wp_unslash( $_POST['request_status'] ) ) : 'approved';

			$requests = get_post_meta( $shop_id, 'som_change_requests', true );
			if ( is_array( $requests ) ) {
				foreach ( $requests as &$req ) {
					if ( isset( $req['id'] ) && $req['id'] === $req_id ) {
						$req['status']      = $new_status;
						$req['resolved_at'] = current_time( 'mysql' );
						$req['resolved_by'] = $user_id;

						if ( 'approved' === $new_status ) {
							self::apply_approved_change_request( $shop_id, $req );
						}
					}
				}
				update_post_meta( $shop_id, 'som_change_requests', $requests );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'som-admin', 'tab' => 'change_requests', 'msg' => 'cr_resolved' ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Calculate dynamic stats.
	 *
	 * @return array
	 */
	public static function get_dashboard_stats() {
		$total_shops = wp_count_posts( 'shop' )->publish;

		$get_count = function( $status_slug ) {
			$term = get_term_by( 'slug', $status_slug, 'shop_status' );
			return $term ? $term->count : 0;
		};

		return array(
			'total'      => $total_shops,
			'contacted'  => $get_count( 'contacted' ),
			'interested' => $get_count( 'interested' ),
			'verified'   => $get_count( 'verified' ),
			'committed'  => $get_count( 'committed' ),
			'rejected'   => $get_count( 'rejected' ),
		);
	}

	/**
	 * Render Main Admin Page.
	 */
	public static function render_admin_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$stats      = self::get_dashboard_stats();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">🏪 <?php esc_html_e( 'Shop Onboarding Management System', 'shop-onboarding-manager' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Action completed successfully.', 'shop-onboarding-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="?page=som-admin&tab=dashboard" class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📊 <?php esc_html_e( 'Dashboard', 'shop-onboarding-manager' ); ?>
				</a>
				<a href="?page=som-admin&tab=tracker" class="nav-tab <?php echo 'tracker' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📋 <?php esc_html_e( 'Shop Tracker', 'shop-onboarding-manager' ); ?>
				</a>
				<a href="?page=som-admin&tab=followups" class="nav-tab <?php echo 'followups' === $active_tab ? 'nav-tab-active' : ''; ?>">
					📅 <?php esc_html_e( 'Follow-ups', 'shop-onboarding-manager' ); ?>
				</a>
				<a href="?page=som-admin&tab=change_requests" class="nav-tab <?php echo 'change_requests' === $active_tab ? 'nav-tab-active' : ''; ?>">
					🔧 <?php esc_html_e( 'Change Requests', 'shop-onboarding-manager' ); ?>
				</a>
			</nav>

			<div class="som-admin-content" style="margin-top: 20px;">
				<?php
				switch ( $active_tab ) {
					case 'tracker':
						self::render_tracker_tab();
						break;
					case 'followups':
						self::render_followups_tab();
						break;
					case 'change_requests':
						self::render_change_requests_tab();
						break;
					case 'dashboard':
					default:
						self::render_dashboard_tab( $stats );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Section 1: Dashboard Tab.
	 *
	 * @param array $stats Dynamic statistics array.
	 */
	private static function render_dashboard_tab( $stats ) {
		?>
		<style>
			.som-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
			.som-stat-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
			.som-stat-num { font-size: 2.2rem; font-weight: 700; color: #1d2327; margin: 8px 0 4px 0; }
			.som-stat-label { font-size: 0.9rem; font-weight: 600; color: #646970; text-transform: uppercase; letter-spacing: 0.5px; }
			.som-stat-card.total { border-top: 4px solid #2271b1; }
			.som-stat-card.contacted { border-top: 4px solid #646970; }
			.som-stat-card.interested { border-top: 4px solid #f59e0b; }
			.som-stat-card.verified { border-top: 4px solid #0284c7; }
			.som-stat-card.committed { border-top: 4px solid #16a34a; }
			.som-stat-card.rejected { border-top: 4px solid #d97706; }
		</style>

		<div class="som-stats-grid">
			<div class="som-stat-card total">
				<div class="som-stat-label"><?php esc_html_e( 'Total Approached', 'shop-onboarding-manager' ); ?></div>
				<div class="som-stat-num"><?php echo esc_html( $stats['total'] ); ?></div>
			</div>
			<div class="som-stat-card contacted">
				<div class="som-stat-label"><?php esc_html_e( 'Contacted', 'shop-onboarding-manager' ); ?></div>
				<div class="som-stat-num"><?php echo esc_html( $stats['contacted'] ); ?></div>
			</div>
			<div class="som-stat-card interested">
				<div class="som-stat-label"><?php esc_html_e( 'Interested', 'shop-onboarding-manager' ); ?></div>
				<div class="som-stat-num"><?php echo esc_html( $stats['interested'] ); ?></div>
			</div>
			<div class="som-stat-card verified">
				<div class="som-stat-label"><?php esc_html_e( 'Verified', 'shop-onboarding-manager' ); ?></div>
				<div class="som-stat-num"><?php echo esc_html( $stats['verified'] ); ?></div>
			</div>
			<div class="som-stat-card committed">
				<div class="som-stat-label"><?php esc_html_e( 'Committed', 'shop-onboarding-manager' ); ?></div>
				<div class="som-stat-num"><?php echo esc_html( $stats['committed'] ); ?></div>
			</div>
			<div class="som-stat-card rejected">
				<div class="som-stat-label"><?php esc_html_e( 'Rejected', 'shop-onboarding-manager' ); ?></div>
				<div class="som-stat-num"><?php echo esc_html( $stats['rejected'] ); ?></div>
			</div>
		</div>

		<div class="postbox" style="padding: 20px; background:#fff;">
			<h2>📌 <?php esc_html_e( 'Quick System Overview', 'shop-onboarding-manager' ); ?></h2>
			<p><?php esc_html_e( 'All statistics above are computed dynamically from live shop post and taxonomy data.', 'shop-onboarding-manager' ); ?></p>
			<ul>
				<li><strong><?php esc_html_e( 'Shop Tracker Tab:', 'shop-onboarding-manager' ); ?></strong> <?php esc_html_e( 'Search, filter, verify, or reject shops.', 'shop-onboarding-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Follow-ups Tab:', 'shop-onboarding-manager' ); ?></strong> <?php esc_html_e( 'View upcoming and overdue field team follow-ups.', 'shop-onboarding-manager' ); ?></li>
				<li><strong><?php esc_html_e( 'Change Requests Tab:', 'shop-onboarding-manager' ); ?></strong> <?php esc_html_e( 'Review and approve merchant correction requests.', 'shop-onboarding-manager' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render Section 2: Shop Tracker Tab.
	 */
	private static function render_tracker_tab() {
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( wp_unslash( $_GET['status_filter'] ) ) : '';
		$type_filter   = isset( $_GET['type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['type_filter'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$args = array(
			'post_type'      => 'shop',
			'post_status'    => 'publish',
			'posts_per_page' => 15,
			'paged'          => $paged,
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		if ( ! empty( $status_filter ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'shop_status',
					'field'    => 'slug',
					'terms'    => $status_filter,
				),
			);
		}

		if ( ! empty( $type_filter ) ) {
			$args['meta_query'][] = array(
				'key'   => 'som_shop_type',
				'value' => $type_filter,
			);
		}

		$query = new WP_Query( $args );

		$statuses = array(
			'contacted'  => __( 'Contacted', 'shop-onboarding-manager' ),
			'interested' => __( 'Interested', 'shop-onboarding-manager' ),
			'verified'   => __( 'Verified', 'shop-onboarding-manager' ),
			'committed'  => __( 'Committed', 'shop-onboarding-manager' ),
			'rejected'   => __( 'Rejected', 'shop-onboarding-manager' ),
		);

		$shop_types = array( 'Supermarket', 'Grocery', 'Convenience Store', 'Bakery', 'Butchery', 'Fruit & Vegetable', 'Specialty Store', 'Other' );
		?>
		<form method="get" style="margin-bottom: 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
			<input type="hidden" name="page" value="som-admin" />
			<input type="hidden" name="tab" value="tracker" />

			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search shop, owner, phone...', 'shop-onboarding-manager' ); ?>" style="width: 220px;" />

			<select name="status_filter">
				<option value=""><?php esc_html_e( '-- Filter Status --', 'shop-onboarding-manager' ); ?></option>
				<?php foreach ( $statuses as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status_filter, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="type_filter">
				<option value=""><?php esc_html_e( '-- Filter Shop Type --', 'shop-onboarding-manager' ); ?></option>
				<?php foreach ( $shop_types as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type_filter, $t ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'shop-onboarding-manager' ); ?>" />
			<?php if ( $search || $status_filter || $type_filter ) : ?>
				<a href="?page=som-admin&tab=tracker" class="button button-link"><?php esc_html_e( 'Reset Filters', 'shop-onboarding-manager' ); ?></a>
			<?php endif; ?>
		</form>

		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Shop Name', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Owner', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Type', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Verified', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Participation', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Follow-up Date', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Onboarded By', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Date Added', 'shop-onboarding-manager' ); ?></th>
					<th style="width: 220px;"><?php esc_html_e( 'Actions', 'shop-onboarding-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $query->have_posts() ) : ?>
					<?php while ( $query->have_posts() ) : $query->the_post();
						$id = get_the_ID();
						$owner = get_post_meta( $id, 'som_owner_name', true );
						$phone = get_post_meta( $id, 'som_phone_number', true );
						$type  = get_post_meta( $id, 'som_shop_type', true );
						$followup = get_post_meta( $id, 'som_followup_date', true );
						$is_verified = get_post_meta( $id, 'som_verified', true );
						$agreement = get_post_meta( $id, 'som_agreement_accepted', true );
						$author = get_the_author_meta( 'display_name', $query->post->post_author );

						$terms = wp_get_post_terms( $id, 'shop_status', array( 'fields' => 'names' ) );
						$curr_status = ! empty( $terms ) ? $terms[0] : 'Contacted';
						?>
						<tr>
							<td><strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php the_title(); ?></a></strong></td>
							<td><?php echo esc_html( $owner ? $owner : '—' ); ?></td>
							<td><?php echo esc_html( $phone ? $phone : '—' ); ?></td>
							<td><?php echo esc_html( $type ? $type : '—' ); ?></td>
							<td><span class="post-state"><?php echo esc_html( $curr_status ); ?></span></td>
							<td><?php echo $is_verified ? '✅ Verified' : '❌ No'; ?></td>
							<td><?php echo $agreement ? '✅ Signed' : '⏳ Pending'; ?></td>
							<td><?php echo esc_html( $followup ? $followup : '—' ); ?></td>
							<td><?php echo esc_html( $author ); ?></td>
							<td><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></td>
							<td>
								<div style="display: flex; gap: 6px; flex-wrap: wrap;">
									<?php if ( ! $is_verified ) : ?>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'som_admin_action_nonce', 'som_nonce' ); ?>
											<input type="hidden" name="som_admin_action" value="verify_shop" />
											<input type="hidden" name="shop_id" value="<?php echo esc_attr( $id ); ?>" />
											<input type="submit" class="button button-small button-primary" value="<?php esc_attr_e( 'Verify', 'shop-onboarding-manager' ); ?>" />
										</form>
									<?php endif; ?>

									<button type="button" class="button button-small som-reject-toggle" data-target="reject_form_<?php echo esc_attr( $id ); ?>">
										<?php esc_html_e( 'Reject', 'shop-onboarding-manager' ); ?>
									</button>
								</div>

								<!-- Rejection Form Popup/Toggle -->
								<div id="reject_form_<?php echo esc_attr( $id ); ?>" style="display:none; margin-top:8px; background:#fff; border:1px solid #ccd0d4; padding:8px; border-radius:4px;">
									<form method="post">
										<?php wp_nonce_field( 'som_admin_action_nonce', 'som_nonce' ); ?>
										<input type="hidden" name="som_admin_action" value="reject_shop" />
										<input type="hidden" name="shop_id" value="<?php echo esc_attr( $id ); ?>" />
										<label style="display:block; font-size:11px; font-weight:600;"><?php esc_html_e( 'Rejection Reason:', 'shop-onboarding-manager' ); ?></label>
										<select name="rejection_reason" style="width:100%; font-size:12px; margin-bottom:6px;" required>
											<?php foreach ( self::REJECTION_REASONS as $key => $lbl ) : ?>
												<option value="<?php echo esc_attr( $lbl ); ?>"><?php echo esc_html( $lbl ); ?></option>
											<?php endforeach; ?>
										</select>
										<textarea name="rejection_notes" rows="2" style="width:100%; font-size:11px;" placeholder="<?php esc_attr_e( 'Notes...', 'shop-onboarding-manager' ); ?>"></textarea>
										<input type="submit" class="button button-small button-link-delete" value="<?php esc_attr_e( 'Confirm Reject', 'shop-onboarding-manager' ); ?>" style="margin-top:4px;" />
									</form>
								</div>
							</td>
						</tr>
					<?php endwhile; wp_reset_postdata(); ?>
				<?php else : ?>
					<tr><td colspan="11"><?php esc_html_e( 'No shops found.', 'shop-onboarding-manager' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<script>
		jQuery(document).ready(function($) {
			$('.som-reject-toggle').on('click', function(e) {
				e.preventDefault();
				var targetId = $(this).data('target');
				$('#' + targetId).toggle();
			});
		});
		</script>

		<!-- Pagination -->
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'   => $query->max_num_pages,
					'current' => $paged,
				) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Section 3: Follow-ups Tab.
	 */
	private static function render_followups_tab() {
		$today = current_time( 'Y-m-d' );
		$query = new WP_Query(
			array(
				'post_type'      => 'shop',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'     => 'som_followup_date',
						'value'   => '',
						'compare' => '!=',
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => 'som_followup_date',
				'order'          => 'ASC',
			)
		);
		?>
		<style>
			.som-overdue-row { background-color: #fcf0f0 !important; }
			.som-overdue-badge { background: #dc2626; color: #fff; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 11px; }
		</style>

		<h2>📅 <?php esc_html_e( 'Field Follow-ups Schedule', 'shop-onboarding-manager' ); ?></h2>
		<p><?php esc_html_e( 'Overdue follow-ups are highlighted in red.', 'shop-onboarding-manager' ); ?></p>

		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Follow-up Date', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Shop Name', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Owner', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Field Notes', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Shopkeeper Concerns', 'shop-onboarding-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $query->have_posts() ) : ?>
					<?php while ( $query->have_posts() ) : $query->the_post();
						$id = get_the_ID();
						$date = get_post_meta( $id, 'som_followup_date', true );
						$owner = get_post_meta( $id, 'som_owner_name', true );
						$phone = get_post_meta( $id, 'som_phone_number', true );
						$notes = get_post_meta( $id, 'som_notes', true );
						$concerns = get_post_meta( $id, 'som_concerns', true );
						$is_overdue = ( $date < $today );
						?>
						<tr class="<?php echo $is_overdue ? 'som-overdue-row' : ''; ?>">
							<td>
								<strong><?php echo esc_html( $date ); ?></strong>
								<?php if ( $is_overdue ) : ?>
									<span class="som-overdue-badge"><?php esc_html_e( 'OVERDUE', 'shop-onboarding-manager' ); ?></span>
								<?php endif; ?>
							</td>
							<td><strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php the_title(); ?></a></strong></td>
							<td><?php echo esc_html( $owner ? $owner : '—' ); ?></td>
							<td><?php echo esc_html( $phone ? $phone : '—' ); ?></td>
							<td><?php echo esc_html( $notes ? $notes : '—' ); ?></td>
							<td><?php echo esc_html( $concerns ? $concerns : '—' ); ?></td>
						</tr>
					<?php endwhile; wp_reset_postdata(); ?>
				<?php else : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No scheduled follow-ups found.', 'shop-onboarding-manager' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Section 4: Change Requests Tab.
	 */
	private static function render_change_requests_tab() {
		$shops = get_posts(
			array(
				'post_type'      => 'shop',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => 'som_change_requests',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$all_requests = array();
		foreach ( $shops as $s ) {
			$reqs = get_post_meta( $s->ID, 'som_change_requests', true );
			if ( is_array( $reqs ) ) {
				foreach ( $reqs as $r ) {
					$r['shop_id']    = $s->ID;
					$r['shop_title'] = $s->post_title;
					$all_requests[]  = $r;
				}
			}
		}

		// Sort newest first.
		usort( $all_requests, function( $a, $b ) {
			return strtotime( $b['created_at'] ) - strtotime( $a['created_at'] );
		} );
		?>
		<h2>🔧 <?php esc_html_e( 'Merchant Change & Correction Requests', 'shop-onboarding-manager' ); ?></h2>

		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Submitted Date', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Shop Name', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Requested Field', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Description', 'shop-onboarding-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'shop-onboarding-manager' ); ?></th>
					<th style="width: 160px;"><?php esc_html_e( 'Action', 'shop-onboarding-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $all_requests ) ) : ?>
					<?php foreach ( $all_requests as $req ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $req['created_at'] ) ) ); ?></td>
							<td><strong><a href="<?php echo esc_url( get_edit_post_link( $req['shop_id'] ) ); ?>"><?php echo esc_html( $req['shop_title'] ); ?></a></strong></td>
							<td><code><?php echo esc_html( $req['change_type'] ); ?></code></td>
							<td><?php echo esc_html( $req['description'] ); ?></td>
							<td>
								<span class="post-state status-<?php echo esc_attr( $req['status'] ); ?>">
									<?php echo esc_html( ucfirst( $req['status'] ) ); ?>
								</span>
							</td>
							<td>
								<?php if ( 'pending' === $req['status'] ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'som_admin_action_nonce', 'som_nonce' ); ?>
										<input type="hidden" name="som_admin_action" value="resolve_change_request" />
										<input type="hidden" name="shop_id" value="<?php echo esc_attr( $req['shop_id'] ); ?>" />
										<input type="hidden" name="request_id" value="<?php echo esc_attr( $req['id'] ); ?>" />
										<input type="hidden" name="request_status" value="approved" />
										<input type="submit" class="button button-small button-primary" value="<?php esc_attr_e( 'Approve', 'shop-onboarding-manager' ); ?>" />
									</form>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'som_admin_action_nonce', 'som_nonce' ); ?>
										<input type="hidden" name="som_admin_action" value="resolve_change_request" />
										<input type="hidden" name="shop_id" value="<?php echo esc_attr( $req['shop_id'] ); ?>" />
										<input type="hidden" name="request_id" value="<?php echo esc_attr( $req['id'] ); ?>" />
										<input type="hidden" name="request_status" value="dismissed" />
										<input type="submit" class="button button-small button-secondary" value="<?php esc_attr_e( 'Dismiss', 'shop-onboarding-manager' ); ?>" />
									</form>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'Resolved', 'shop-onboarding-manager' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No change requests found.', 'shop-onboarding-manager' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}