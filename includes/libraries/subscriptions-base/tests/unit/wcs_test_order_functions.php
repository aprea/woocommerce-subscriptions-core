<?php

/**
 * @since 2.4.3
 */
class WCS_Order_Functions_Unit_Tests extends WCS_Unit_Test_Case {
	public function test_order_contains_manual_subscription() {
		$manual_renewal_subscription = WCS_Helper_Subscription::create_subscription( array( 'status' => 'active' ) );
		$manual_renewal_subscription->set_requires_manual_renewal( true );
		$manual_renewal_subscription->save();
		$manual_renewal_order = WCS_Helper_Subscription::create_renewal_order( $manual_renewal_subscription );

		$this->assertTrue( $manual_renewal_subscription->is_manual() );
		$this->assertTrue( wcs_order_contains_manual_subscription( $manual_renewal_order ) );

		add_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_false' );
		$available_gateways             = WC()->payment_gateways->payment_gateways();
		$automatic_renewal_subscription = WCS_Helper_Subscription::create_subscription( array( 'status' => 'active' ) );
		$automatic_renewal_order        = WCS_Helper_Subscription::create_renewal_order( $automatic_renewal_subscription );
		$automatic_renewal_subscription->set_payment_method( $available_gateways['paypal'] );
		$automatic_renewal_subscription->set_requires_manual_renewal( false );
		$automatic_renewal_subscription->save();

		$this->assertFalse( $automatic_renewal_subscription->is_manual() );
		$this->assertFalse( wcs_order_contains_manual_subscription( $automatic_renewal_order ) );
	}

	public function test_copy_payment_method_to_order() {
		add_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_false' );

		$available_gateways = WC()->payment_gateways->payment_gateways();

		$payment_meta_value = array(
			'post_meta' => array(
				'meta_1' => array( 'value' => 'value_1' ),
				'meta_2' => array( 'value' => 1 ),
				'meta_3' => array( 'value' => true ),
			),
		);

		$subscription = WCS_Helper_Subscription::create_subscription( array( 'status' => 'active' ) );
		$order        = WCS_Helper_Subscription::create_renewal_order( $subscription );
		$subscription->set_payment_method( $available_gateways['paypal'] );
		$subscription->set_requires_manual_renewal( false );
		$subscription->save();

		// Set payment meta.
		add_filter( 'woocommerce_subscription_payment_meta', function ( $payment_meta ) use ( $subscription, $payment_meta_value ) {
			$payment_meta[ $subscription->get_payment_method() ] = $payment_meta_value;

			return $payment_meta;
		} );

		$this->assertNotEquals( $order->get_payment_method(), $subscription->get_payment_method() );
		$this->assertEquals( $payment_meta_value, $subscription->get_payment_method_meta() );

		wcs_copy_payment_method_to_order( $subscription, $order );
		$order->save();

		$this->assertEquals( $order->get_payment_method(), $subscription->get_payment_method() );
		$this->assertEquals( $payment_meta_value['post_meta']['meta_1']['value'], get_post_meta( $order->get_id(), 'meta_1', true ) );
		$this->assertEquals( $payment_meta_value['post_meta']['meta_2']['value'], get_post_meta( $order->get_id(), 'meta_2', true ) );
		$this->assertEquals( $payment_meta_value['post_meta']['meta_3']['value'], get_post_meta( $order->get_id(), 'meta_2', true ) );
	}

	public function test_find_matching_line_item() {
		$order        = WC_Helper_Order::create_order();
		$subscription = WCS_Helper_Subscription::create_subscription();
		$item         = false;

		foreach ( $order->get_items() as $item ) {
			// Validate falsy cases.
			$this->assertFalse( wcs_find_matching_line_item( $subscription, $item ) );

			// Add item to subscription.
			$subscription->add_item( $item );
			$subscription->save();

			break; // Lets just add one item.
		}

		foreach ( $subscription->get_items() as $subscription_item ) {
			$this->assertEquals( wcs_find_matching_line_item( $order, $subscription_item ), wcs_find_matching_line_item( $subscription, $subscription_item ) );
			$this->assertEquals( wcs_find_matching_line_item( $order, $subscription_item, 'match_product_ids' ), wcs_find_matching_line_item( $subscription, $subscription_item, 'match_product_ids' ) );
		}
	}
}