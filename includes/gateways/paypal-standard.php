<?php
/**
 * PayPal Standard Gateway
 *
 * @package     EDD
 * @subpackage  Gateways
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PayPal Remove CC Form
 *
 * PayPal Standard does not need a CC form, so remove it.
 *
 * @access private
 * @since 1.0
 */
add_action( 'edd_paypal_cc_form', '__return_false' );

/**
 * Process PayPal Purchase
 *
 * @since 1.0
 * @param array   $purchase_data Purchase Data
 * @return void
 */
function edd_process_paypal_purchase( $purchase_data ) {
	if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
		wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
	}

	// Collect payment data
	$payment_data = array(
		'price'         => $purchase_data['price'],
		'date'          => $purchase_data['date'],
		'user_email'    => $purchase_data['user_email'],
		'purchase_key'  => $purchase_data['purchase_key'],
		'currency'      => edd_get_currency(),
		'downloads'     => $purchase_data['downloads'],
		'user_info'     => $purchase_data['user_info'],
		'cart_details'  => $purchase_data['cart_details'],
		'gateway'       => 'paypal',
		'status'        => 'pending'
	);

	// Record the pending payment
	$payment = edd_insert_payment( $payment_data );

	// Check payment
	if ( ! $payment ) {
		// Record the error
		edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'Payment creation failed before sending buyer to PayPal. Payment data: %s', 'easy-digital-downloads' ), json_encode( $payment_data ) ), $payment );
		// Problems? send back
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	} else {
		// Only send to PayPal if the pending payment is created successfully
		$listener_url = add_query_arg( 'edd-listener', 'IPN', home_url( 'index.php' ) );

		// Get the success url
		$return_url = add_query_arg( array(
				'payment-confirmation' => 'paypal',
				'payment-id' => $payment
			), get_permalink( edd_get_option( 'success_page', false ) ) );

		// Get the PayPal redirect uri
		$paypal_redirect = trailingslashit( edd_get_paypal_redirect() ) . '?';

		// Setup PayPal arguments
		$paypal_args = array(
			'business'      => edd_get_option( 'paypal_email', false ),
			'email'         => $purchase_data['user_email'],
			'first_name'    => $purchase_data['user_info']['first_name'],
			'last_name'     => $purchase_data['user_info']['last_name'],
			'invoice'       => $purchase_data['purchase_key'],
			'no_shipping'   => '1',
			'shipping'      => '0',
			'no_note'       => '1',
			'currency_code' => edd_get_currency(),
			'charset'       => get_bloginfo( 'charset' ),
			'custom'        => $payment,
			'rm'            => '2',
			'return'        => $return_url,
			'cancel_return' => edd_get_failed_transaction_uri( '?payment-id=' . $payment ),
			'notify_url'    => $listener_url,
			'page_style'    => edd_get_paypal_page_style(),
			'cbt'           => get_bloginfo( 'name' ),
			'bn'            => 'EasyDigitalDownloads_SP'
		);

		if ( ! empty( $purchase_data['user_info']['address'] ) ) {
			$paypal_args['address1'] = $purchase_data['user_info']['address']['line1'];
			$paypal_args['address2'] = $purchase_data['user_info']['address']['line2'];
			$paypal_args['city']     = $purchase_data['user_info']['address']['city'];
			$paypal_args['country']  = $purchase_data['user_info']['address']['country'];
		}

		$paypal_extra_args = array(
			'cmd'    => '_cart',
			'upload' => '1'
		);

		$paypal_args = array_merge( $paypal_extra_args, $paypal_args );

		// Add cart items
		$i = 1;
		if( is_array( $purchase_data['cart_details'] ) && ! empty( $purchase_data['cart_details'] ) ) {
			foreach ( $purchase_data['cart_details'] as $item ) {

				$item_amount = round( ( $item['subtotal'] / $item['quantity'] ) - ( $item['discount'] / $item['quantity'] ), 2 );

				if( $item_amount <= 0 ) {
					$item_amount = 0;
				}

				$paypal_args['item_name_' . $i ] = stripslashes_deep( html_entity_decode( edd_get_cart_item_name( $item ), ENT_COMPAT, 'UTF-8' ) );
				$paypal_args['quantity_' . $i ]  = $item['quantity'];
				$paypal_args['amount_' . $i ]    = $item_amount;

				if ( edd_use_skus() ) {
					$paypal_args['item_number_' . $i ] = edd_get_download_sku( $item['id'] );
				}

				$i++;

			}
		}

		// Calculate discount
		$discounted_amount = 0.00;
		if ( ! empty( $purchase_data['fees'] ) ) {
			$i = empty( $i ) ? 1 : $i;
			foreach ( $purchase_data['fees'] as $fee ) {
				if ( floatval( $fee['amount'] ) > '0' ) {
					// this is a positive fee
					$paypal_args['item_name_' . $i ] = stripslashes_deep( html_entity_decode( wp_strip_all_tags( $fee['label'] ), ENT_COMPAT, 'UTF-8' ) );
					$paypal_args['quantity_' . $i ]  = '1';
					$paypal_args['amount_' . $i ]    = edd_sanitize_amount( $fee['amount'] );
					$i++;
				} else {
					// This is a negative fee (discount)
					$discounted_amount += abs( $fee['amount'] );
				}
			}
		}

		if ( $discounted_amount > '0' ) {
			$paypal_args['discount_amount_cart'] = edd_sanitize_amount( $discounted_amount );
		}

		// Add taxes to the cart
		if ( edd_use_taxes() ) {

			$paypal_args['tax_cart'] = edd_sanitize_amount( $purchase_data['tax'] );

		}

		$paypal_args = apply_filters( 'edd_paypal_redirect_args', $paypal_args, $purchase_data );

		// Build query
		$paypal_redirect .= http_build_query( $paypal_args );

		// Fix for some sites that encode the entities
		$paypal_redirect = str_replace( '&amp;', '&', $paypal_redirect );

		// Get rid of cart contents
		edd_empty_cart();

		// Redirect to PayPal
		wp_redirect( $paypal_redirect );
		exit;
	}

}
add_action( 'edd_gateway_paypal', 'edd_process_paypal_purchase' );

/**
 * Listens for a PayPal IPN requests and then sends to the processing function
 *
 * @since 1.0
 * @return void
 */
function edd_listen_for_paypal_ipn() {
	// Regular PayPal IPN
	if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'IPN' ) {
		do_action( 'edd_verify_paypal_ipn' );
	}
}
add_action( 'init', 'edd_listen_for_paypal_ipn' );

/**
 * Process PayPal IPN
 *
 * @since 1.0
 * @return void
 */
function edd_process_paypal_ipn() {
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
		return;
	}
	$post_data = file_get_contents( 'php://input' );
	if ( ! is_string( $post_data ) || $post_data === '' ) {
		return;
	}

	parse_str( $post_data, $encoded_data_array );
	foreach ( $encoded_data_array as $value ) {
		if ( ! is_string( $value ) ) {
			return;
		}
	}
	if ( isset( $encoded_data_array['cmd'] ) ) {
		return;
	}

	$sandbox = edd_is_test_mode();
	if ( isset( $encoded_data_array['test_ipn'] ) && $encoded_data_array['test_ipn'] !== ( $sandbox ? '1' : '0' ) ) {
		return;
	}
	$verification_url = $sandbox
		? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'
		: 'https://ipnpb.paypal.com/cgi-bin/webscr';

	$remote_post_vars = array(
		'method'           => 'POST',
		'timeout'          => 45,
		'redirection'      => 0,
		'httpversion'      => '1.1',
		'blocking'         => true,
		'headers'          => array(
			'connection'   => 'close',
			'content-type' => 'application/x-www-form-urlencoded',
		),
		'sslverify'        => true,
		// Preserve the original fields, encoding and order for the PayPal postback.
		'body'             => 'cmd=_notify-validate&' . $post_data
	);
	$api_response = wp_remote_post( $verification_url, $remote_post_vars );
	if ( is_wp_error( $api_response ) || wp_remote_retrieve_response_code( $api_response ) !== 200 || wp_remote_retrieve_body( $api_response ) !== 'VERIFIED' ) {
		if ( is_wp_error( $api_response ) || wp_remote_retrieve_response_code( $api_response ) !== 200 ) {
			status_header( 503 );
		}
		edd_record_gateway_error( __( 'IPN Error', 'easy-digital-downloads' ), __( 'PayPal IPN verification failed. No payment changes were made.', 'easy-digital-downloads' ) );
		return;
	}

	$defaults = array(
		'txn_type'       => '',
		'payment_status' => ''
	);

	$encoded_data_array = wp_parse_args( $encoded_data_array, $defaults );

	if ( empty( $encoded_data_array['custom'] ) || ! ctype_digit( $encoded_data_array['custom'] ) ) {
		return;
	}
	$payment_id = absint( $encoded_data_array['custom'] );

	if ( has_action( 'edd_paypal_' . $encoded_data_array['txn_type'] ) ) {
		// Allow PayPal IPN types to be processed separately
		do_action( 'edd_paypal_' . $encoded_data_array['txn_type'], $encoded_data_array, $payment_id );
	} else {
		// Fallback to web accept just in case the txn_type isn't present
		do_action( 'edd_paypal_web_accept', $encoded_data_array, $payment_id );
	}
	exit;
}
add_action( 'edd_verify_paypal_ipn', 'edd_process_paypal_ipn' );

function edd_paypal_validate_payment_data( $data, $payment_id ) {
	$required = array( 'payment_status', 'mc_gross', 'mc_currency', 'txn_id' );
	foreach ( $required as $field ) {
		if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || $data[ $field ] === '' ) {
			return false;
		}
	}
	if ( ! $payment_id || get_post_type( $payment_id ) !== 'edd_payment' || edd_get_payment_gateway( $payment_id ) !== 'paypal' ) {
		return false;
	}
	$mode = edd_get_payment_meta( $payment_id, '_edd_payment_mode' );
	if ( $mode !== ( edd_is_test_mode() ? 'test' : 'live' ) ) {
		return false;
	}
	$payment_meta = edd_get_payment_meta( $payment_id );
	$recipient = isset( $data['business'] ) && is_string( $data['business'] ) && is_email( $data['business'] )
		? trim( $data['business'] ) : ( $data['receiver_email'] ?? '' );
	$expected_recipient = trim( (string) edd_get_option( 'paypal_email', '' ) );
	if ( ! is_string( $recipient ) || ! is_email( $recipient ) || $expected_recipient === '' || strcasecmp( trim( $recipient ), $expected_recipient ) !== 0 ) {
		return false;
	}
	if ( empty( $payment_meta['currency'] ) || strcasecmp( $data['mc_currency'], $payment_meta['currency'] ) !== 0 ) {
		return false;
	}
	if ( ! preg_match( '/^[A-Za-z0-9-]+$/D', $data['txn_id'] ) || ! preg_match( '/^-?\d+(?:\.\d{1,2})?$/D', $data['mc_gross'] ) ) {
		return false;
	}
	$status = strtolower( $data['payment_status'] );
	if ( $status === 'refunded' || $status === 'reversed' ) {
		$transaction_id = edd_get_payment_transaction_id( $payment_id );
		return ! empty( $transaction_id ) && isset( $data['parent_txn_id'] )
			&& is_string( $data['parent_txn_id'] ) && hash_equals( (string) $transaction_id, $data['parent_txn_id'] )
			&& (float) $data['mc_gross'] < 0;
	}
	$purchase_key = $data['invoice'] ?? ( $data['item_number'] ?? '' );
	$expected_key = (string) edd_get_payment_key( $payment_id );
	if ( ! is_string( $purchase_key ) || $expected_key === '' || ! hash_equals( $expected_key, $purchase_key ) ) {
		return false;
	}
	$transaction_payment = edd_get_purchase_id_by_transaction_id( $data['txn_id'] );
	if ( $transaction_payment && (int) $transaction_payment !== (int) $payment_id ) {
		return false;
	}
	$transaction_id = edd_get_payment_transaction_id( $payment_id );
	if ( $transaction_id && ! hash_equals( (string) $transaction_id, $data['txn_id'] ) ) {
		return false;
	}
	// Keep accepting overpayments, without comparing formatted strings containing thousands separators.
	return round( (float) $data['mc_gross'], 2 ) >= round( (float) edd_get_payment_amount( $payment_id ), 2 );
}

/**
 * Process web accept (one time) payment IPNs
 *
 * @since 1.3.4
 * @param array   $data IPN Data
 * @return void
 */
function edd_process_paypal_web_accept_and_cart( $data, $payment_id ) {
	if ( ! edd_paypal_validate_payment_data( $data, $payment_id ) ) {
		edd_record_gateway_error( __( 'IPN Error', 'easy-digital-downloads' ), __( 'PayPal IPN does not match the payment. No payment changes were made.', 'easy-digital-downloads' ), $payment_id );
		return;
	}
	if ( ( $data['txn_type'] ?? '' ) != 'web_accept' && ( $data['txn_type'] ?? '' ) != 'cart' && ! in_array( strtolower( $data['payment_status'] ), array( 'refunded', 'reversed' ), true ) ) {
		return;
	}

	// Collect payment details
	$payment_status = strtolower( $data['payment_status'] );
	$payment_meta   = edd_get_payment_meta( $payment_id );

	if ( ! edd_get_payment_user_email( $payment_id ) ) {

		// This runs when a Buy Now purchase was made. It bypasses checkout so no personal info is collected until PayPal

		// No email associated with purchase, so store from PayPal
		edd_update_payment_meta( $payment_id, '_edd_payment_user_email', $data['payer_email'] );

		// Setup and store the customers's details
		$address = array();
		$address['line1']   = ! empty( $data['address_street']       ) ? sanitize_text_field( $data['address_street'] )       : false;
		$address['city']    = ! empty( $data['address_city']         ) ? sanitize_text_field( $data['address_city'] )         : false;
		$address['state']   = ! empty( $data['address_state']        ) ? sanitize_text_field( $data['address_state'] )        : false;
		$address['country'] = ! empty( $data['address_country_code'] ) ? sanitize_text_field( $data['address_country_code'] ) : false;
		$address['zip']     = ! empty( $data['address_zip']          ) ? sanitize_text_field( $data['address_zip'] )          : false;

		$user_info = array(
			'id'         => '-1',
			'email'      => sanitize_text_field( $data['payer_email'] ),
			'first_name' => sanitize_text_field( $data['first_name'] ),
			'last_name'  => sanitize_text_field( $data['last_name'] ),
			'discount'   => '',
			'address'    => $address
		);

		$payment_meta['user_info'] = $user_info;
		edd_update_payment_meta( $payment_id, '_edd_payment_meta', $payment_meta );
	}

	if ( $payment_status == 'refunded' || $payment_status == 'reversed' ) {

		// Process a refund
		edd_process_paypal_refund( $data, $payment_id );

	} else {

		if ( get_post_status( $payment_id ) == 'publish' ) {
			return; // Only complete payments once
		}

		if ( 'completed' == $payment_status ) {

			edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Transaction ID: %s', 'easy-digital-downloads' ) , $data['txn_id'] ) );
			edd_set_payment_transaction_id( $payment_id, $data['txn_id'] );
			edd_update_payment_status( $payment_id, 'publish' );

		} else if ( 'pending' == $payment_status && isset( $data['pending_reason'] ) ) {

			// Look for possible pending reasons, such as an echeck

			$note = '';

			switch( strtolower( $data['pending_reason'] ) ) {

				case 'echeck' :

					$note = __( 'Payment made via eCheck and will clear automatically in 5-8 days', 'easy-digital-downloads' );

					break;

				case 'address' :

					$note = __( 'Payment requires a confirmed customer address and must be accepted manually through PayPal', 'easy-digital-downloads' );

					break;

				case 'intl' :

					$note = __( 'Payment must be accepted manually through PayPal due to international account regulations', 'easy-digital-downloads' );

					break;

				case 'multi-currency' :

					$note = __( 'Payment received in non-shop currency and must be accepted manually through PayPal', 'easy-digital-downloads' );

					break;

				case 'paymentreview' :
				case 'regulatory_review' :

					$note = __( 'Payment is being reviewed by PayPal staff as high-risk or in possible violation of government regulations', 'easy-digital-downloads' );

					break;

				case 'unilateral' :

					$note = __( 'Payment was sent to non-confirmed or non-registered email address.', 'easy-digital-downloads' );

					break;

				case 'upgrade' :

					$note = __( 'PayPal account must be upgraded before this payment can be accepted', 'easy-digital-downloads' );

					break;

				case 'verify' :

					$note = __( 'PayPal account is not verified. Verify account in order to accept this payment', 'easy-digital-downloads' );

					break;

				case 'other' :

					$note = __( 'Payment is pending for unknown reasons. Contact PayPal support for assistance', 'easy-digital-downloads' );

					break;

			}

			if( ! empty( $note ) ) {

				edd_insert_payment_note( $payment_id, $note );

			}

		}
	}
}
add_action( 'edd_paypal_web_accept', 'edd_process_paypal_web_accept_and_cart', 10, 2 );

/**
 * Process PayPal IPN Refunds
 *
 * @since 1.3.4
 * @param array   $data IPN Data
 * @return void
 */
function edd_process_paypal_refund( $data, $payment_id = 0 ) {
	if ( ! edd_paypal_validate_payment_data( $data, $payment_id ) || ! in_array( strtolower( $data['payment_status'] ), array( 'refunded', 'reversed' ), true ) ) {
		return;
	}

	if ( get_post_status( $payment_id ) == 'refunded' ) {
		return; // Only refund payments once
	}

	$payment_amount = edd_get_payment_amount( $payment_id );
	$refund_amount  = $data['mc_gross'] * -1;

	if ( round( (float) $refund_amount, 2 ) < round( (float) $payment_amount, 2 ) ) {

		edd_insert_payment_note( $payment_id, sprintf( __( 'Partial PayPal refund processed: %s', 'easy-digital-downloads' ), $data['parent_txn_id'] ) );
		return; // This is a partial refund

	}

	edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Payment #%s Refunded for reason: %s', 'easy-digital-downloads' ), $data['parent_txn_id'], sanitize_text_field( $data['reason_code'] ?? '' ) ) );
	edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Refund Transaction ID: %s', 'easy-digital-downloads' ), $data['txn_id'] ) );
	edd_update_payment_status( $payment_id, 'refunded' );
}

/**
 * Get PayPal Redirect
 *
 * @since 1.0.8.2
 * @param bool    $ssl_check Is SSL?
 * @return string
 */
function edd_get_paypal_redirect( $ssl_check = false ) {
	if ( is_ssl() || ! $ssl_check ) {
		$protocal = 'https://';
	} else {
		$protocal = 'http://';
	}

	// Check the current payment mode
	if ( edd_is_test_mode() ) {
		// Test mode
		$paypal_uri = $protocal . 'www.sandbox.paypal.com/cgi-bin/webscr';
	} else {
		// Live mode
		$paypal_uri = $protocal . 'www.paypal.com/cgi-bin/webscr';
	}

	return apply_filters( 'edd_paypal_uri', $paypal_uri );
}

/**
 * Set the Page Style for PayPal Purchase page
 *
 * @since 1.4.1
 * @return string
 */
function edd_get_paypal_page_style() {
	$page_style = trim( edd_get_option( 'paypal_page_style', 'PayPal' ) );
	return apply_filters( 'edd_paypal_page_style', $page_style );
}

/**
 * Shows "Purchase Processing" message for PayPal payments are still pending on site return
 *
 * This helps address the Race Condition, as detailed in issue #1839
 *
 * @since 1.9
 * @return string
 */
function edd_paypal_success_page_content( $content ) {

	if ( ! isset( $_GET['payment-id'] ) && ! edd_get_purchase_session() ) {
		return $content;
	}

	$payment_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : false;

	if ( ! $payment_id ) {
		$session    = edd_get_purchase_session();
		$payment_id = edd_get_purchase_id_by_key( $session['purchase_key'] );
	}

	$payment = get_post( $payment_id );

	if ( $payment && 'pending' == $payment->post_status ) {

		// Payment is still pending so show processing indicator to fix the Race Condition, issue #
		ob_start();

		edd_get_template_part( 'payment', 'processing' );

		$content = ob_get_clean();

	}

	return $content;

}
add_filter( 'edd_payment_confirm_paypal', 'edd_paypal_success_page_content' );

/**
 * Given a Payment ID, extract the transaction ID
 *
 * @since  2.1
 * @param  string $payment_id       Payment ID
 * @return string                   Transaction ID
 */
function edd_paypal_get_payment_transaction_id( $payment_id ) {

	$transaction_id = '';
	$notes = edd_get_payment_notes( $payment_id );

	foreach ( $notes as $note ) {
		if ( preg_match( '/^PayPal Transaction ID: ([^\s]+)/', $note->comment_content, $match ) ) {
			$transaction_id = $match[1];
			continue;
		}
	}

	return apply_filters( 'edd_paypal_set_payment_transaction_id', $transaction_id, $payment_id );
}
add_filter( 'edd_get_payment_transaction_id-paypal', 'edd_paypal_get_payment_transaction_id', 10, 1 );

/**
 * Given a transaction ID, generate a link to the PayPal transaction ID details
 *
 * @since  2.2
 * @param  string $transaction_id The Transaction ID
 * @param  int    $payment_id     The payment ID for this transaction
 * @return string                 A link to the PayPal transaction details
 */
function edd_paypal_link_transaction_id( $transaction_id, $payment_id ) {

	$paypal_base_url = 'https://www.paypal.com/webscr?cmd=_history-details-from-hub&id=';
	$transaction_url = '<a href="' . esc_url( $paypal_base_url . $transaction_id ) . '" target="_blank">' . $transaction_id . '</a>';

	return apply_filters( 'edd_paypal_link_payment_details_transaction_id', $transaction_url );

}
add_filter( 'edd_payment_details_transaction_id-paypal', 'edd_paypal_link_transaction_id', 10, 2 );
