<?php
/**
 * Upgrade Screen
 *
 * @package     EDD
 * @subpackage  Admin/Upgrades
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.3.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Publigo's Stripe integration still uses this screen between migration batches.
// Core EDD upgrades and their AJAX runner have been retired from WPI-Cart.
function edd_upgrades_screen() {
	$action = isset( $_GET['edd-upgrade'] ) && is_string( $_GET['edd-upgrade'] ) ? $_GET['edd-upgrade'] : '';

	if ( ! current_user_can( 'manage_shop_settings' ) || 'stripe_customer_id_migration' !== $action || ! has_action( 'edd_stripe_customer_id_migration' ) ) {
		wp_die( __( 'This action is not allowed.', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
		return;
	}

	$step = isset( $_GET['step'] ) && is_scalar( $_GET['step'] ) ? max( 1, absint( $_GET['step'] ) ) : 1;
	$total = isset( $_GET['total'] ) && is_scalar( $_GET['total'] ) ? absint( $_GET['total'] ) : 0;
	$number = isset( $_GET['number'] ) && is_scalar( $_GET['number'] ) ? max( 1, absint( $_GET['number'] ) ) : 10;
	$steps = max( $step, (int) ceil( $total / $number ) );

	update_option( 'edd_doing_upgrade', array(
		'page' => 'edd-upgrades',
		'edd-upgrade' => $action,
		'step' => $step,
		'total' => $total,
		'number' => $number,
		'steps' => $steps,
	) );

	$url = add_query_arg( array(
		'edd_action' => $action,
		'step' => $step,
		'total' => $total,
		'number' => $number,
	), admin_url( 'index.php' ) );
	?>
	<div class="wrap">
		<h2><?php _e( 'Easy Digital Downloads - Upgrades', 'easy-digital-downloads' ); ?></h2>
		<div id="edd-upgrade-status">
			<p><?php _e( 'The upgrade process has started, please be patient. This could take several minutes. You will be automatically redirected when the upgrade is finished.', 'easy-digital-downloads' ); ?></p>
			<?php if ( $total ) : ?>
				<p><strong><?php printf( __( 'Step %d of approximately %d running', 'easy-digital-downloads' ), $step, $steps ); ?></strong></p>
			<?php endif; ?>
		</div>
		<script type="text/javascript">
			setTimeout(function() { document.location.href = <?php echo wp_json_encode( $url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>; }, 250);
		</script>
	</div>
	<?php
}
