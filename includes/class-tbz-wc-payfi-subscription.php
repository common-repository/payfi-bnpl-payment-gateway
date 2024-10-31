<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tbz_WC_Payfi_Subscription extends Tbz_WC_Payfi_Gateway {

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {

			add_action( 'wcs_renewal_order_created', array( $this, 'delete_renewal_meta' ), 10 );
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

		}
	}

	/**
	 * Don't transfer Payfi fee/ID meta to renewal orders.
	 *
	 * @param WC_Order $renewal_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	 *
	 * @return WC_Order Renewal order
	 */
	public function delete_renewal_meta( $renewal_order ) {

		if ( $this->is_wc_lt( '3.0' ) ) {
			$order_id = $renewal_order->id;
		} else {
			$order_id = $renewal_order->get_id();
		}

		delete_post_meta( $order_id, '_payfi_fee' );
		delete_post_meta( $order_id, '_payfi_net' );
		delete_post_meta( $order_id, '_payfi_currency' );

		return $renewal_order;
	}

	/**
	 * Check if an order contains a subscription
	 */
	public function order_contains_subscription( $order_id ) {

		return function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) );

	}

	/**
	 * Process a trial subscription order with 0 total
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		// Check for trial subscription order with 0 total
		if ( $this->order_contains_subscription( $order ) && $order->get_total() == 0 ) {

			$order->payment_complete();

			$order->add_order_note( 'This subscription has a free trial, reason for the 0 amount' );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} else {

			return parent::process_payment( $order_id );

		}

	}

	/**
	 * Process a subscription renewal
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

		$response = $this->process_subscription_payment( $renewal_order, $amount_to_charge );

		if ( is_wp_error( $response ) ) {

			$renewal_order->update_status( 'failed', sprintf( 'Payfi Transaction Failed (%s)', $response->get_error_message() ) );

		}

	}

	/**
	 * Process a subscription renewal payment
	 */
	public function process_subscription_payment( $order = '', $amount = 0 ) {

		$order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;

		
	}

}
