<?php
/**
 * Tools
 *
 * These are functions used for EDD tools.
 *
 * @package     EDD
 * @subpackage  Admin/Tools
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Save banned emails
 *
 * @since       2.0
 * @return      void
 */
function edd_tools_banned_emails_save() {

	global $edd_options;

	if( ! isset( $_POST['edd_banned_emails_nonce'] ) || ! is_string( $_POST['edd_banned_emails_nonce'] ) || ! wp_verify_nonce( $_POST['edd_banned_emails_nonce'], 'edd_banned_emails_nonce' ) ) {
		return;
	}

	if( ! current_user_can( 'manage_shop_settings' ) ) {
		return;
	}

	if( isset( $_POST['banned_emails'] ) && ! is_string( $_POST['banned_emails'] ) ) {
		return;
	}

	$emails = array();
	if( ! empty( $_POST['banned_emails'] ) ) {

		// Sanitize the input
		$emails = array_map( 'trim', explode( "\n", $_POST['banned_emails'] ) );
		$emails = array_unique( $emails );
		$emails = array_map( 'sanitize_text_field', $emails );

		foreach( $emails as $id => $email ) {
			if( ! is_email( $email ) && ( ! isset( $email[0] ) || '@' !== $email[0] || ! is_email( 'user' . $email ) ) ) {
				unset( $emails[$id] );
			}
		}
	}

	$edd_options['banned_emails'] = $emails;
	update_option( 'edd_settings', $edd_options );
}
add_action( 'edd_save_banned_emails', 'edd_tools_banned_emails_save' );
