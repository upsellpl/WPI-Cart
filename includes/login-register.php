<?php
/**
 * Login / Register Functions
 *
 * @package     EDD
 * @subpackage  Functions/Login
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Log User In
 *
 * @since 1.0
 * @param int $user_id User ID
 * @param string $user_login Username
 * @param string $user_pass Password
 * @return void
*/
function edd_log_user_in( $user_id, $user_login, $user_pass ) {
    return;
	if ( $user_id < 1 )
		return;

	wp_set_auth_cookie( $user_id );
	wp_set_current_user( $user_id, $user_login );
	do_action( 'wp_login', $user_login, get_userdata( $user_id ) );
	do_action( 'edd_log_user_in', $user_id, $user_login, $user_pass );
}


/**
 * Process Register Form
 *
 * @since 2.0
 * @param array $data Data sent from the register form
 * @return void
*/
function edd_process_register_form( $data ) {

	if( is_user_logged_in() ) {
		return;
	}

	if( empty( $_POST['edd_register_submit'] ) ) {
		return;
	}

	do_action( 'edd_pre_process_register_form' );

	if( empty( $data['edd_user_login'] ) ) {
		edd_set_error( 'empty_username', __( 'Invalid username', 'easy-digital-downloads' ) );
	}

	if( username_exists( $data['edd_user_login'] ) ) {
		edd_set_error( 'username_unavailable', __( 'Username already taken', 'easy-digital-downloads' ) );
	}

	if( ! validate_username( $data['edd_user_login'] ) ) {
		edd_set_error( 'username_invalid', __( 'Invalid username', 'easy-digital-downloads' ) );
	}

	if( email_exists( $data['edd_user_email'] ) ) {
		edd_set_error( 'email_unavailable', __( 'Email address already taken', 'easy-digital-downloads' ) );
	}

	if( empty( $data['edd_user_email'] ) || ! is_email( $data['edd_user_email'] ) ) {
		edd_set_error( 'email_invalid', __( 'Invalid email', 'easy-digital-downloads' ) );
	}

	if( ! empty( $data['edd_payment_email'] ) && $data['edd_payment_email'] != $data['edd_user_email'] && ! is_email( $data['edd_payment_email'] ) ) {
		edd_set_error( 'payment_email_invalid', __( 'Invalid payment email', 'easy-digital-downloads' ) );
	}

	if( empty( $_POST['edd_user_pass'] ) ) {
		edd_set_error( 'empty_password', __( 'Please enter a password', 'easy-digital-downloads' ) );
	}

	if( ( ! empty( $_POST['edd_user_pass'] ) && empty( $_POST['edd_user_pass2'] ) ) || ( $_POST['edd_user_pass'] !== $_POST['edd_user_pass2'] ) ) {
		edd_set_error( 'password_mismatch', __( 'Passwords do not match', 'easy-digital-downloads' ) );
	}

	do_action( 'edd_process_register_form' );

	// Check for errors and redirect if none present
	$errors = edd_get_errors();

	if (  empty( $errors ) ) {

		$redirect = apply_filters( 'edd_register_redirect', $data['edd_redirect'] );

		edd_register_and_login_new_user( array(
			'user_login'      => $data['edd_user_login'],
			'user_pass'       => $data['edd_user_pass'],
			'user_email'      => $data['edd_user_email'],
			'user_registered' => date( 'Y-m-d H:i:s' ),
			'role'            => get_option( 'default_role' )
		) );

		wp_redirect( $redirect );
		edd_die();
	}
}
//add_action( 'edd_user_register', 'edd_process_register_form' );
