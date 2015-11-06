<?php
/**
 * WooCommerce Subscriptions User Functions
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Give a user the Subscription's default subscriber role
 *
 * @since 2.0
 */
function wcs_make_user_active( $user_id ) {
	wcs_update_users_role( $user_id, 'default_subscriber_role' );
}

/**
 * Give a user the Subscription's default subscriber's inactive role
 *
 * @since 2.0
 */
function wcs_make_user_inactive( $user_id ) {
	wcs_update_users_role( $user_id, 'default_inactive_role' );
}

/**
 * Give a user the Subscription's default subscriber's inactive role if they do not have an active subscription
 *
 * @since 2.0
 */
function wcs_maybe_make_user_inactive( $user_id ) {
	if ( ! wcs_user_has_subscription( $user_id, '', 'active' ) ) {
		wcs_update_users_role( $user_id, 'default_inactive_role' );
	}
}

/**
 * Update a user's role to a special subscription's role
 *
 * @param int The ID of a user
 * @param string The special name assigned to the role by Subscriptions, one of 'default_subscriber_role', 'default_inactive_role' or 'default_cancelled_role'
 * @return WP_User The user with the new role.
 * @since 2.0
 */
function wcs_update_users_role( $user_id, $role_new ) {

	$user = new WP_User( $user_id );

	// Never change an admin's role to avoid locking out admins testing the plugin
	if ( ! empty( $user->roles ) && in_array( 'administrator', $user->roles ) ) {
		return;
	}

	// Allow plugins to prevent Subscriptions from handling roles
	if ( ! apply_filters( 'woocommerce_subscriptions_update_users_role', true, $user, $role_new ) ) {
		return;
	}

	$default_subscriber_role = get_option( WC_Subscriptions_Admin::$option_prefix . '_subscriber_role' );
	$default_cancelled_role = get_option( WC_Subscriptions_Admin::$option_prefix . '_cancelled_role' );
	$role_old = '';

	if ( 'default_subscriber_role' == $role_new ) {
		$role_old = $default_cancelled_role;
		$role_new = $default_subscriber_role;
	} elseif ( in_array( $role_new, array( 'default_inactive_role', 'default_cancelled_role' ) ) ) {
		$role_old = $default_subscriber_role;
		$role_new = $default_cancelled_role;
	}

	if ( ! empty( $role_old ) ) {
		$user->remove_role( $role_old );
	}

	$user->add_role( $role_new );

	do_action( 'woocommerce_subscriptions_updated_users_role', $role_new, $user, $role_old );
	return $user;
}

/**
 * Check if a user has a subscription, optionally to a specific product and/or with a certain status.
 *
 * @param int (optional) The ID of a user in the store. If left empty, the current user's ID will be used.
 * @param int (optional) The ID of a product in the store. If left empty, the function will see if the user has any subscription.
 * @param string (optional) A valid subscription status. If left empty, the function will see if the user has a subscription of any status.
 * @since 2.0
 */
function wcs_user_has_subscription( $user_id = 0, $product_id = '', $status = 'any' ) {

	$subscriptions = wcs_get_users_subscriptions( $user_id );

	$has_subscription = false;

	if ( empty( $product_id ) ) { // Any subscription

		if ( ! empty( $status ) && 'any' != $status ) { // We need to check for a specific status
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->get_status() == $status ) {
					$has_subscription = true;
					break;
				}
			}
		} elseif ( ! empty( $subscriptions ) ) {
			$has_subscription = true;
		}
	} else {

		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->has_product( $product_id ) && ( empty( $status ) || 'any' == $status || $subscription->get_status() == $status ) ) {
				$has_subscription = true;
				break;
			}
		}
	}

	return apply_filters( 'wcs_user_has_subscription', $has_subscription, $user_id, $product_id, $status );
}

/**
 * Gets all the active and inactive subscriptions for a user, as specified by $user_id
 *
 * @param int $user_id (optional) The id of the user whose subscriptions you want. Defaults to the currently logged in user.
 * @since 2.0
 */
function wcs_get_users_subscriptions( $user_id = 0 ) {

	if ( 0 === $user_id || empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$post_ids = get_posts( array(
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'post_type'      => 'shop_subscription',
		'orderby'        => 'date',
		'order'          => 'desc',
		'meta_key'       => '_customer_user',
		'meta_value'     => $user_id,
		'meta_compare'   => '=',
		'fields'         => 'ids',
	) );

	$subscriptions = array();

	foreach ( $post_ids as $post_id ) {
		$subscriptions[ $post_id ] = wcs_get_subscription( $post_id );
	}

	return apply_filters( 'wcs_get_users_subscriptions', $subscriptions, $user_id );
}

/**
 * Return a link for subscribers to change the status of their subscription, as specified with $status parameter
 *
 * @param int $subscription_id A subscription's post ID
 * @param string $status A subscription's post ID
 * @since 1.0
 */
function wcs_get_users_change_status_link( $subscription_id, $status ) {

	$action_link = add_query_arg( array( 'subscription_id' => $subscription_id, 'change_subscription_to' => $status ) );
	$action_link = wp_nonce_url( $action_link, $subscription_id );

	return apply_filters( 'wcs_users_change_status_link', $action_link, $subscription_id, $status );
}

/**
 * Check if a given user (or the currently logged in user) has permission to put a subscription on hold.
 *
 * By default, a store manager can put all subscriptions on hold, while other users can only suspend their own subscriptions.
 *
 * @param int|WC_Subscription $subscription An instance of a WC_Snbscription object or ID representing a 'shop_subscription' post
 * @since 2.0
 */
function wcs_can_user_put_subscription_on_hold( $subscription, $user = '' ) {

	$user_can_suspend = false;

	if ( empty( $user ) ) {
		$user = wp_get_current_user();
	} elseif ( is_int( $user ) ) {
		$user = get_user_by( 'id', $user );
	}

	if ( user_can( $user, 'manage_woocommerce' ) ) { // Admin, so can always suspend a subscription

		$user_can_suspend = true;

	} else {  // Need to make sure user owns subscription & the suspension limit hasn't been reached

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		// Make sure current user owns subscription
		if ( $user->ID == $subscription->get_user_id() ) {

			// Make sure subscription suspension count hasn't been reached
			$suspension_count    = $subscription->suspension_count;
			$allowed_suspensions = get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', 0 );

			if ( 'unlimited' === $allowed_suspensions || $allowed_suspensions > $suspension_count ) { // 0 not > anything so prevents a customer ever being able to suspend
				$user_can_suspend = true;
			}
		}
	}

	return apply_filters( 'wcs_can_user_put_subscription_on_hold', $user_can_suspend, $subscription );
}

/**
 * Retrieve available actions that a user can perform on the subscription
 *
 * @since 2.0
 */
function wcs_get_all_user_actions_for_subscription( $subscription, $user_id ) {

	$actions = array();

	if ( user_can( $user_id, 'edit_shop_subscription_status', $subscription->id ) ) {

		$admin_with_suspension_disallowed = ( current_user_can( 'manage_woocommerce' ) && 0 === get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', 0 ) ) ? true : false;

		if ( $subscription->can_be_updated_to( 'on-hold' ) && wcs_can_user_put_subscription_on_hold( $subscription, $user_id ) && ! $admin_with_suspension_disallowed ) {
			$actions['suspend'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->id, 'on-hold' ),
				'name' => __( 'Suspend', 'woocommerce-subscriptions' ),
			);
		} elseif ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
			$actions['reactivate'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->id, 'active' ),
				'name' => __( 'Reactivate', 'woocommerce-subscriptions' ),
			);
		}

		if ( wcs_can_user_resubscribe_to( $subscription, $user_id ) ) {
			$actions['resubscribe'] = array(
				'url'  => wcs_get_users_resubscribe_link( $subscription ),
				'name' => __( 'Resubscribe', 'woocommerce-subscriptions' ),
			);
		}

		// Show button for subscriptions which can be cancelled and which may actually require cancellation (i.e. has a future payment)
		if ( $subscription->can_be_updated_to( 'cancelled' ) && $subscription->get_time( 'next_payment' ) > 0 ) {
			$actions['cancel'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->id, 'cancelled' ),
				'name' => __( 'Cancel', 'woocommerce-subscriptions' ),
			);
		}
	}

	return apply_filters( 'wcs_view_subscription_actions', $actions, $subscription );
}

/**
 * Checks if a user has a certain capability
 *
 * @access public
 * @param array $allcaps
 * @param array $caps
 * @param array $args
 * @return bool
 */
function wcs_user_has_capability( $allcaps, $caps, $args ) {
	if ( isset( $caps[0] ) ) {
		switch ( $caps[0] ) {
			case 'edit_shop_subscription_payment_method' :
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_payment_method'] = true;
				}
			break;
			case 'edit_shop_subscription_status' :
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_status'] = true;
				}
			break;
			case 'edit_shop_subscription_line_items' :
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_line_items'] = true;
				}
			break;
			case 'switch_shop_subscription' :
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['switch_shop_subscription'] = true;
				}
			break;
			case 'subscribe_again' :
				$user_id  = $args[1];
				$subscription = wcs_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['subscribe_again'] = true;
				}
			break;
		}
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'wcs_user_has_capability', 10, 3 );
