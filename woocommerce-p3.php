<?php
/*
Plugin Name: WooCommerce Cardstream
Plugin URI: http://woothemes.com/woocommerce/
Description: Provides the Cardstream Payment Gateway for WooCommerce
Version: 1.3
Author: Cardstream
Author URI: http://www.cardstream.com/
License: GPL2
*/

/*  Copyright 2019  Cardstream  (support@cardstream.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

/**
 * Initialise p3 Gateway
 **/
add_action('plugins_loaded', 'init_p3', 0);

function init_p3() {

	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	add_filter('plugin_action_links', 'p3_add_action_plugin', 10, 5);

	include('classes/p3.php');

	add_filter('woocommerce_payment_gateways', 'add_p3_paymentgateway' );

}

function p3_add_action_plugin($actions, $plugin_file)
{
	static $plugin;

	if (!isset($plugin))
	{
		$plugin = plugin_basename(__FILE__);
	}

	if ($plugin == $plugin_file)
	{
		$actions = array_merge(array('settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=Cardstream') . '">' . __('Settings', 'General') . '</a>'), $actions);
	}

	return $actions;
}

function add_p3_paymentgateway($methods) {
	$methods[] = 'WC_p3';
	return $methods;
}
