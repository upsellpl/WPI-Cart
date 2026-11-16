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
		header( 'Allow: POST' );
		edd_paypal_finish_ipn( new WP_Error( 'invalid_method', '', [ 'status' => 405 ] ) );
	}

	$body = edd_paypal_read_ipn_body();
	if ( is_wp_error( $body ) ) {
		edd_paypal_finish_ipn( $body );
	}
	$data = edd_paypal_parse_ipn( $body );
	if ( is_wp_error( $data ) ) {
		edd_paypal_finish_ipn( $data );
	}
	$payment_id = edd_paypal_find_ipn_payment( $data );
	if ( is_wp_error( $payment_id ) ) {
		edd_paypal_finish_ipn( $payment_id );
	}
	$mode = edd_paypal_get_ipn_mode( $data, $payment_id );
	if ( is_wp_error( $mode ) ) {
		edd_paypal_finish_ipn( $mode );
	}
	$verified = edd_paypal_verify_ipn( $body, $mode );
	if ( is_wp_error( $verified ) ) {
		edd_paypal_finish_ipn( $verified );
	}

	$data += [ 'txn_type' => '', 'payment_status' => '' ];
	$status = strtolower( $data['payment_status'] );
	$is_refund = in_array( $status, [ 'refunded', 'reversed' ], true );
	$is_standard = in_array( $data['txn_type'], [ 'web_accept', 'cart' ], true ) || $is_refund;
	// Validate before extension hooks; public handlers also protect direct calls.
	if ( $is_standard && in_array( $status, [ 'completed', 'pending', 'refunded', 'reversed' ], true ) ) {
		$valid = edd_paypal_validate_ipn_payment( $data, $payment_id );
		if ( is_wp_error( $valid ) ) {
			edd_paypal_finish_ipn( $valid );
		}
	}
	$hook = 'edd_paypal_' . $data['txn_type'];
	if ( ! has_action( $hook ) ) {
		$hook = 'edd_paypal_web_accept';
	}
	status_header( 200 );
	try {
		do_action( $hook, $data, $payment_id );
	} catch ( Throwable $error ) {
		edd_paypal_finish_ipn( new WP_Error( 'processing_unavailable', '', [ 'status' => 503 ] ) );
	}
	exit;
}

function edd_paypal_read_ipn_body(): string|WP_Error {
	// The outer request may contain an encoded transport envelope; the IPN itself is limited separately.
	$raw_body = file_get_contents( 'php://input', false, null, 0, 786433 );
	if ( is_string( $raw_body ) && strlen( $raw_body ) > 786432 ) {
		return new WP_Error( 'request_too_large', '', [ 'status' => 413 ] );
	}
	// null: direct IPN; string: original body from an adapter; WP_Error: reject without fallback.
	$body = apply_filters( 'edd_paypal_ipn_body', null, $raw_body );
	if ( is_wp_error( $body ) ) {
		return $body;
	}
	if ( $body === null ) {
		if ( $raw_body === false ) {
			return new WP_Error( 'ipn_read_failed', '', [ 'status' => 503 ] );
		}
		$body = $raw_body;
	}
	if ( ! is_string( $body ) || $body === '' ) {
		return new WP_Error( 'missing_ipn_body' );
	}
	if ( strlen( $body ) > 131072 ) {
		return new WP_Error( 'ipn_too_large', '', [ 'status' => 413 ] );
	}
	return $body;
}

function edd_paypal_parse_ipn( string $body ): array|WP_Error {
	$fields = explode( '&', $body );
	if ( count( $fields ) > 512 ) {
		return new WP_Error( 'too_many_ipn_fields', '', [ 'status' => 413 ] );
	}
	$data = [];
	foreach ( $fields as $field ) {
		if ( strpos( $field, '=' ) === false || preg_match( '/%(?![0-9a-fA-F]{2})/', $field ) ) {
			return new WP_Error( 'invalid_ipn_encoding' );
		}
		[ $name, $value ] = explode( '=', $field, 2 );
		$name = urldecode( $name );
		$value = urldecode( $value );
		if ( strlen( $name ) > 128 || ! preg_match( '/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $name )
			|| strcasecmp( $name, 'cmd' ) === 0 || array_key_exists( $name, $data ) ) {
			return new WP_Error( 'ambiguous_ipn_fields' );
		}
		if ( strlen( $value ) > 65536 ) {
			return new WP_Error( 'ipn_field_too_large', '', [ 'status' => 413 ] );
		}
		$data[ $name ] = $value;
	}
	return $data;
}

function edd_paypal_find_ipn_payment( array $data ): int|WP_Error {
	$payment_id = 0;
	$is_refund = in_array( strtolower( $data['payment_status'] ?? '' ), [ 'refunded', 'reversed' ], true );
	$is_standard_payment = in_array( $data['txn_type'] ?? '', [ 'cart', 'web_accept' ], true ) || $is_refund;
	if ( isset( $data['custom'] ) ) {
		if ( ! preg_match( '/\A[1-9][0-9]*\z/', $data['custom'] ) || strlen( $data['custom'] ) > strlen( (string) PHP_INT_MAX )
			|| (string) (int) $data['custom'] !== $data['custom'] ) {
			// Extensions may use their own custom reference; only Standard requires an EDD order ID.
			return $is_standard_payment ? new WP_Error( 'invalid_payment_reference' ) : 0;
		}
		$payment_id = (int) $data['custom'];
	}
	if ( $is_refund ) {
		$parent = $data['parent_txn_id'] ?? '';
		$parent_payment = $parent !== '' ? edd_get_purchase_id_by_transaction_id( $parent ) : 0;
		if ( $parent_payment && $payment_id && (int) $parent_payment !== $payment_id ) {
			return new WP_Error( 'conflicting_payment_reference' );
		}
		$payment_id = $payment_id ?: (int) $parent_payment;
	}
	if ( $is_standard_payment && ! $payment_id ) {
		return new WP_Error( 'missing_payment_reference' );
	}
	return $payment_id;
}

function edd_paypal_get_ipn_mode( array $data, int $payment_id ): string|WP_Error {
	$mode = $payment_id ? edd_get_payment_meta( $payment_id, '_edd_payment_mode' ) : '';
	if ( $mode === '' || $mode === false || $mode === null ) {
		// Historical orders without mode may be verified against live only, never inferred as sandbox orders.
		$mode = $payment_id ? 'live' : ( edd_is_test_mode() ? 'test' : 'live' );
	}
	if ( ! in_array( $mode, [ 'live', 'test' ], true )
		|| ( isset( $data['test_ipn'] ) && $data['test_ipn'] !== ( $mode === 'test' ? '1' : '0' ) ) ) {
		return new WP_Error( 'payment_mode_mismatch' );
	}
	return $mode;
}

function edd_paypal_verify_ipn( string $body, string $mode ): bool|WP_Error {
	$url = $mode === 'test' ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
	$response = wp_remote_post( $url, [
		'timeout' => 45,
		'redirection' => 0,
		'httpversion' => '1.1',
		'limit_response_size' => 1024,
		'sslverify' => true,
		'user-agent' => 'WPI-Cart-PayPal-IPN/1.0',
		'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded', 'Connection' => 'close' ],
		'body' => 'cmd=_notify-validate&' . $body,
	] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return new WP_Error( 'verification_unavailable', '', [ 'status' => 503 ] );
	}
	$result = wp_remote_retrieve_body( $response );
	if ( $result !== 'VERIFIED' ) {
		return new WP_Error( $result === 'INVALID' ? 'verification_rejected' : 'unexpected_verification_response', '', [ 'status' => $result === 'INVALID' ? 400 : 503 ] );
	}
	return true;
}

function edd_paypal_log_ipn_error( WP_Error $error ): WP_Error {
	$details = $error->get_error_data();
	$status = is_array( $details ) ? ( $details['status'] ?? 400 ) : 400;
	$status = in_array( $status, [ 400, 405, 413, 503 ], true ) ? $status : 400;
	status_header( $status );
	// Error messages and transport responses can contain personal data; record only a bounded reason code.
	$code = substr( sanitize_key( $error->get_error_code() ), 0, 80 );
	edd_record_gateway_error( __( 'IPN Error', 'easy-digital-downloads' ), 'PayPal IPN rejected: ' . $code );
	return $error;
}

function edd_paypal_finish_ipn( WP_Error $error ): void {
	edd_paypal_log_ipn_error( $error );
	exit;
}

add_action( 'edd_verify_paypal_ipn', 'edd_process_paypal_ipn' );

/**
 * Process web accept (one time) payment IPNs
 *
 * @since 1.3.4
 * @param array   $data IPN Data
 * @return void
 */
function edd_process_paypal_web_accept_and_cart( $data, $payment_id ) {
	$status = strtolower( $data['payment_status'] ?? '' );
	$is_refund = in_array( $status, [ 'refunded', 'reversed' ], true );
	if ( ! in_array( $data['txn_type'] ?? '', [ 'web_accept', 'cart' ], true ) && ! $is_refund ) {
		return;
	}
	if ( ! in_array( $status, [ 'completed', 'pending', 'refunded', 'reversed' ], true ) ) {
		return;
	}
	if ( $is_refund ) {
		return edd_process_paypal_refund( $data, $payment_id );
	}
	$valid = edd_paypal_validate_ipn_payment( $data, $payment_id );
	if ( is_wp_error( $valid ) ) {
		return edd_paypal_log_ipn_error( $valid );
	}
	// Completed orders must not be changed by duplicates or late Pending notifications.
	if ( in_array( get_post_status( $payment_id ), [ 'publish', 'refunded', 'revoked' ], true ) ) {
		return;
	}
	$payment_meta = edd_get_payment_meta( $payment_id );
	if ( ! edd_get_payment_user_email( $payment_id ) && is_email( $data['payer_email'] ?? '' ) ) {

		// This runs when a Buy Now purchase was made. It bypasses checkout so no personal info is collected until PayPal

		// No email associated with purchase, so store from PayPal
		edd_update_payment_meta( $payment_id, '_edd_payment_user_email', ( $data['payer_email'] ?? '' ) );

		// Setup and store the customers's details
		$address = array();
		$address['line1']   = ! empty( $data['address_street']       ) ? sanitize_text_field( $data['address_street'] )       : false;
		$address['city']    = ! empty( $data['address_city']         ) ? sanitize_text_field( $data['address_city'] )         : false;
		$address['state']   = ! empty( $data['address_state']        ) ? sanitize_text_field( $data['address_state'] )        : false;
		$address['country'] = ! empty( $data['address_country_code'] ) ? sanitize_text_field( $data['address_country_code'] ) : false;
		$address['zip']     = ! empty( $data['address_zip']          ) ? sanitize_text_field( $data['address_zip'] )          : false;

		$user_info = array(
			'id'         => '-1',
			'email'      => sanitize_text_field( ( $data['payer_email'] ?? '' ) ),
			'first_name' => sanitize_text_field( ( $data['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( ( $data['last_name'] ?? '' ) ),
			'discount'   => '',
			'address'    => $address
		);

		$payment_meta['user_info'] = $user_info;
		edd_update_payment_meta( $payment_id, '_edd_payment_meta', $payment_meta );
	}

	if ( $status === 'completed' ) {
		$transaction_id = edd_get_payment_transaction_id( $payment_id );
		if ( $transaction_id !== $data['txn_id'] && ! edd_set_payment_transaction_id( $payment_id, $data['txn_id'] ) ) {
			return edd_paypal_log_ipn_error( new WP_Error( 'transaction_write_failed', '', [ 'status' => 503 ] ) );
		}
		edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Transaction ID: %s', 'easy-digital-downloads' ), $data['txn_id'] ) );
		if ( ! edd_update_payment_status( $payment_id, 'publish' ) ) {
			return edd_paypal_log_ipn_error( new WP_Error( 'payment_update_failed', '', [ 'status' => 503 ] ) );
		}
	} elseif ( isset( $data['pending_reason'] ) ) {
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
add_action( 'edd_paypal_web_accept', 'edd_process_paypal_web_accept_and_cart', 10, 2 );

/**
 * Process PayPal IPN Refunds
 *
 * @since 1.3.4
 * @param array   $data IPN Data
 * @return void
 */
function edd_process_paypal_refund( $data, $payment_id = 0 ) {
	if ( ! in_array( strtolower( $data['payment_status'] ?? '' ), [ 'refunded', 'reversed' ], true ) ) {
		return;
	}
	$valid = edd_paypal_validate_ipn_payment( $data, $payment_id );
	if ( is_wp_error( $valid ) ) {
		return edd_paypal_log_ipn_error( $valid );
	}
	if ( get_post_status( $payment_id ) === 'refunded' ) {
		return;
	}
	$currency = edd_get_payment_currency_code( $payment_id );
	$decimals = edd_currency_decimal_filter( 2, $currency );
	$refund_amount = -edd_paypal_amount_in_minor_units( $data['mc_gross'], $decimals );
	$payment_amount = edd_paypal_get_payment_minor_units( $payment_id, $decimals );
	if ( $refund_amount < $payment_amount ) {
		edd_insert_payment_note( $payment_id, sprintf( __( 'Partial PayPal refund processed: %s', 'easy-digital-downloads' ), $data['txn_id'] ) );
		return;
	}
	edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Payment #%s Refunded for reason: %s', 'easy-digital-downloads' ), $data['parent_txn_id'], sanitize_text_field( $data['reason_code'] ?? '' ) ) );
	edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Refund Transaction ID: %s', 'easy-digital-downloads' ), $data['txn_id'] ) );
	if ( ! edd_update_payment_status( $payment_id, 'refunded' ) ) {
		return edd_paypal_log_ipn_error( new WP_Error( 'refund_update_failed', '', [ 'status' => 503 ] ) );
	}
}

function edd_paypal_validate_ipn_payment( array $data, int $payment_id ): bool|WP_Error {
	if ( ! $payment_id || get_post_type( $payment_id ) !== 'edd_payment' || edd_get_payment_gateway( $payment_id ) !== 'paypal' ) {
		return new WP_Error( 'invalid_paypal_payment' );
	}
	foreach ( $data as $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'invalid_payment_fields' );
		}
	}
	foreach ( [ 'payment_status', 'txn_id', 'mc_gross', 'mc_currency' ] as $field ) {
		if ( ! isset( $data[ $field ] ) || $data[ $field ] === '' ) {
			return new WP_Error( 'missing_payment_fields' );
		}
	}
	if ( ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/', $data['txn_id'] ) ) {
		return new WP_Error( 'invalid_transaction_id' );
	}
	$mode = edd_paypal_get_ipn_mode( $data, $payment_id );
	if ( is_wp_error( $mode ) ) {
		return $mode;
	}
	$currency = edd_get_payment_currency_code( $payment_id );
	if ( ! $currency || strcasecmp( $currency, $data['mc_currency'] ) !== 0 ) {
		return new WP_Error( 'payment_currency_mismatch' );
	}
	$expected_recipient = trim( (string) edd_get_option( 'paypal_email', '' ) );
	$business = trim( $data['business'] ?? '' );
	$receiver = trim( $data['receiver_email'] ?? '' );
	$is_refund = in_array( strtolower( $data['payment_status'] ), [ 'refunded', 'reversed' ], true );
	// business can be the configured alias, while receiver_email is the account's primary email.
	$recipient_required = ! $is_refund || $business !== '' || $receiver !== '';
	$recipient_matches = $expected_recipient !== ''
		&& ( strcasecmp( $business, $expected_recipient ) === 0 || strcasecmp( $receiver, $expected_recipient ) === 0 );
	if ( $recipient_required && ! $recipient_matches ) {
		return new WP_Error( 'payment_recipient_mismatch' );
	}
	$decimals = edd_currency_decimal_filter( 2, $currency );
	$amount = edd_paypal_amount_in_minor_units( $data['mc_gross'], $decimals );
	$expected_amount = edd_paypal_get_payment_minor_units( $payment_id, $decimals );
	if ( is_wp_error( $amount ) || is_wp_error( $expected_amount ) ) {
		return new WP_Error( 'invalid_payment_amount' );
	}
	if ( $is_refund ) {
		return edd_paypal_validate_ipn_refund( $data, $payment_id, $amount, $expected_amount );
	}
	return edd_paypal_validate_ipn_purchase( $data, $payment_id, $amount, $expected_amount );
}

function edd_paypal_validate_ipn_purchase( array $data, int $payment_id, int $amount, int $expected_amount ): bool|WP_Error {
	if ( $amount < 0 || $amount !== $expected_amount ) {
		return new WP_Error( 'payment_amount_mismatch' );
	}
	$purchase_key = (string) edd_get_payment_key( $payment_id );
	$received_key = $data['invoice'] ?? ( $data['item_number'] ?? '' );
	if ( $purchase_key === '' || ! hash_equals( $purchase_key, $received_key ) ) {
		return new WP_Error( 'payment_key_mismatch' );
	}
	$transaction_id = (string) edd_get_payment_transaction_id( $payment_id );
	$transaction_payment = edd_get_purchase_id_by_transaction_id( $data['txn_id'] );
	if ( ( $transaction_payment && (int) $transaction_payment !== (int) $payment_id )
		|| ( $transaction_id !== '' && ! hash_equals( $transaction_id, $data['txn_id'] ) ) ) {
		return new WP_Error( 'transaction_payment_mismatch' );
	}
	return true;
}

function edd_paypal_validate_ipn_refund( array $data, int $payment_id, int $amount, int $expected_amount ): bool|WP_Error {
	$transaction_id = (string) edd_get_payment_transaction_id( $payment_id );
	$parent = $data['parent_txn_id'] ?? '';
	if ( $transaction_id === '' || $parent === '' || ! hash_equals( $transaction_id, $parent )
		|| $amount >= 0 || -$amount > $expected_amount ) {
		return new WP_Error( 'refund_payment_mismatch' );
	}
	return true;
}

function edd_paypal_get_payment_minor_units( int $payment_id, $decimals ): int|WP_Error {
	$amount = edd_get_payment_meta( $payment_id, '_edd_payment_total' );
	if ( $amount === '' || $amount === false || $amount === null ) {
		$legacy = edd_get_payment_meta( $payment_id );
		$amount = $legacy['amount'] ?? '';
	}
	// Older serialized metadata can contain a float; use its stored decimal representation without new arithmetic.
	if ( is_float( $amount ) && is_finite( $amount ) ) {
		$amount = (string) $amount;
	}
	return edd_paypal_amount_in_minor_units( $amount, $decimals );
}

function edd_paypal_amount_in_minor_units( $amount, $decimals ): int|WP_Error {
	if ( ! is_int( $decimals ) || $decimals < 0 || $decimals > 6
		|| ( ! is_string( $amount ) && ! is_int( $amount ) )
		|| ! preg_match( '/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/', (string) $amount, $parts ) ) {
		return new WP_Error( 'invalid_decimal_amount' );
	}
	$fraction = $parts[3] ?? '';
	if ( strlen( $fraction ) > $decimals && trim( substr( $fraction, $decimals ), '0' ) !== '' ) {
		return new WP_Error( 'invalid_amount_precision' );
	}
	$units = ltrim( $parts[2] . str_pad( substr( $fraction, 0, $decimals ), $decimals, '0' ), '0' );
	$maximum = (string) PHP_INT_MAX;
	if ( strlen( $units ) > strlen( $maximum ) || ( strlen( $units ) === strlen( $maximum ) && strcmp( $units, $maximum ) > 0 ) ) {
		return new WP_Error( 'amount_out_of_range' );
	}
	return $parts[1] === '-' ? -(int) $units : (int) $units;
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
