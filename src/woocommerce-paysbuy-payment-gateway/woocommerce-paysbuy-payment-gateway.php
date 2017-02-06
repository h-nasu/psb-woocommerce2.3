<?php
/*
Plugin Name: WooCommerce PaysBuy Gateway
Plugin URI: http://www.paysbuy.com/
Description: Extends WooCommerce with a PaysBuy gateway.
Version: 2.3.4
Author: PaysBuy
Author URI: http://www.paysbuy.com/

	Copyright: © 2009-2011 WooThemes.
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once dirname(__FILE__).'/vendor/autoload.php'; // load dependencies

add_action('plugins_loaded', 'woocommerce_paysbuy_init', 0);
load_plugin_textdomain('wc-paysbuy', false, dirname(plugin_basename(__FILE__)).'/languages');

function woocommerce_paysbuy_init() {

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_paysbuy_gateway' );
	
	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	
	class WC_Gateway_Paysbuy extends WC_Payment_Gateway {
		
		var $notify_url;
		
		public function __construct() {
			
			global $woocommerce;
		
			$this->id						= 'paysbuy';
			$this->icon 				= WP_PLUGIN_URL."/".plugin_basename(dirname(__FILE__)).'/image/paysbuy.png';
			$this->has_fields 	= false;
			$this->liveurl 			= 'https://www.paysbuy.com/paynow.aspx';
			$this->method_title = __('PaysBuy', 'woocommerce');
			$this->notify_url		= WC()->api_request_url( 'WC_Gateway_Paysbuy' );
		
			// Load the form fields.
			$this->init_form_fields();
		
			// Load the settings.
			$this->init_settings();
		
			// Define user set variables
			$this->title				= $this->settings['title'];
			$this->description	= $this->settings['description'];
			$this->email				= $this->settings['email'];
			$this->psbid				= $this->settings['psbid'];
			$this->securecode		= $this->settings['securecode'];
		
			// Actions
			add_action('woocommerce_receipt_paysbuy', [&$this, 'receipt_page']);
			add_action('woocommerce_update_options_payment_gateways', [&$this, 'process_admin_options']);
			add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);

			// API hook
			add_action('woocommerce_api_wc_gateway_paysbuy', [$this, 'paysbuy_response']);
		
		}
		
		public function init_form_fields(){

			$this->form_fields = [
				'enabled' => [
					'title'				=> __('Enable/Disable', 'woocommerce'),
					'type'				=> 'checkbox',
					'label'				=> __('Enable PaysBuy', 'woocommerce'),
					'default'			=> 'yes'
				],
				'title' => [
					'title'				=> __('Title', 'woocommerce'),
					'type'				=> 'text',
					'description'	=> __('This controls the title which the user sees during checkout.', 'woocommerce'),
					'default'			=> __('PaysBuy', 'woocommerce') 
				],
				'description' => [
					'title'				=> __('Description', 'woocommerce'),
					'type'				=> 'textarea',
					'description'	=> __('This controls the description which the user sees during checkout.', 'woocommerce'),
					'default'			=> __("You can pay with PaysBuy; You must be PaysBuy account.", 'woocommerce')
				],
				'email' => [
					'title'				=> __('PaysBuy Email', 'woocommerce'),
					'type'				=> 'text',
					'description'	=> __('Please enter your PaysBuy email address; this is needed in order to take payment.', 'woocommerce'),
					'default'			=> ''
				],
				'psbid' => [
					'title'				=> __('PaysBuy ID', 'woocommerce'),
					'type'				=> 'text',
					'description'	=> __('Your PaysBuy merchant ID.', 'woocommerce'),
					'default'			=> '' 
				],
				'securecode' => [
					'title'				=> __('Secure Code', 'woocommerce'),
					'type'				=> 'text',
					'description'	=> __('Your PaysBuy secure code.', 'woocommerce'),
					'default'			=> ''
				]
			];	

		}
		
		public function admin_options() {

			echo '<h3>'._e('PaysBuy','woocommerce').'</h3>';
			echo '<p>'._e('Make it easier!', 'woocommerce').'</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html(); 
			echo '</table>';

		}

		function get_paynow_language($locale) {
			switch ($locale) {
				case 'th':
					$l = 'T';
					break;
				case 'ja':
					$l = 'J';
					break;
				default:
					$l = 'E';
					break;
			}
			return $l;
		}
		
		function get_paysbuy_args($order) {
		
			$order_id = $order->id;
		
			$item_names = [];
			foreach ($order->get_items() as $item) {
				if ($item['qty']) $item_names[] = $item['name'].' x '.$item['qty'];
			}
			
			// PaysBuy Args
			$paysbuy_args = [
				'inv'							=> $order_id,
				'itm'							=> sprintf(__('Order %s', 'woocommerce'), $order->get_order_number())." - ".implode(', ', $item_names),
				'amt'							=> $order->get_total(),
				'resp_front_url'	=> $this->get_return_url($order),
				'resp_back_url'		=> $this->notify_url,
				'curr_code'				=> $order->get_order_currency(),
				'opt_fix_redirect'=> '1',
				'method'					=> '1',
				'language'				=> $this->get_paynow_language(get_locale())
			];
		
			$paysbuy_args = apply_filters( 'woocommerce_paysbuy_args', $paysbuy_args );
			return $paysbuy_args;

		}
		
		function generate_paysbuy_pay_url($order_id) {

			$order = new WC_Order($order_id);
			$paysbuy_args = $this->get_paysbuy_args($order);

			\PaysbuyService::setup([
				'psbID' => $this->psbid,
				'username' => $this->email,
				'secureCode' => $this->securecode
			]);

			$paymentURL = \PaysbuyPaynow::authenticate($paysbuy_args);
			return $paymentURL;

		}
		
		function receipt_page($order_id) {
			wp_redirect($this->generate_paysbuy_pay_url($order_id));
		}
		
		function paysbuy_response() {

			global $woocommerce;

			if(isset($_POST['result']) && isset($_POST['apCode']) && isset($_POST['amt'])) {

				$order_id = trim(substr($_POST["result"], 2));
				$order = new WC_Order($order_id);
			
				$result = $_POST["result"];
				$result = substr($result, 0, 2);
				$apCode = $_POST["apCode"];
				$amt = $_POST["amt"];
				$fee = $_POST["fee"];
				$method = $_POST["method"];
				
				if ($result == '00') {
					$order->payment_complete();
					$woocommerce->cart->empty_cart();
				} else if ($result == '99') {
					$order->update_status('failed', __('Payment Failed', 'woothemes'));
					$woocommerce->cart->empty_cart();
				} else if ($result == '02') {
					$order->update_status('on-hold', __('Awaiting Counter Service payment', 'woothemes'));
					// Reduce stock levels
					$order->reduce_order_stock();
					$woocommerce->cart->empty_cart();
				}
			}
	
		}
		
		function process_payment($order_id) {
		
			$order = new WC_Order($order_id);
			$url = $order->get_checkout_payment_url(true);
			return array(
				'result'		=> 'success',
				'redirect'	=> $url
			);

		}

	} // end class WC_Paysbuy	

	function woocommerce_add_paysbuy_gateway($methods) {
		$methods[] = 'WC_Gateway_Paysbuy';
		return $methods;
	}

} // end woocommerce_paysbuy_init
