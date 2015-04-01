<?php
/**
 * @package PongoPay
 */
/*
Plugin Name: WooCommerce PongoPay
Plugin URI: http://www.pongosoft.com/
Description: PongoPay
Version: 1.0.0
Author: PongoSoft
Author URI: http://www.pongosoft.com
License: GPLv2 or later
Text Domain: woocommerce-pongopay
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

function init_pongopay_gateway_class() {
	class WC_PongoPay_Gateway extends WC_Payment_Gateway {
		public $merchantid;

		public function __construct() {
			$this->id = 'pongopay';
			$this->title = 'Pongo Pay';
			$this->method_title = 'Pongo Pay';
			$this->method_description = 'Pongo Pay fonctionne grace a son API';
			$this->has_fields = true;

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_'.$this->id, array( $this, 'thankyou' ) );
		}

		public function init_form_fields() {

			$this->title = $this->get_option( 'title' );
			$this->merchantid = $this->get_option( 'merchantid' );

			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woocommerce' ),
					'type' => 'checkbox',
					'label' => __( 'Enable PongoPay Payment', 'woocommerce' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'Use PongoPay.', 'woocommerce' ),
					'default' => __( 'Pongo Pay', 'woocommerce' ),
					'desc_tip'      => true,
				),
				'merchantid' => array(
					'title' => __( 'Merchant ID', 'woocommerce' ),
					'type' => 'text',
					'description' => __( 'Merchand ID.', 'woocommerce' ),
					'desc_tip'      => true,
				),
			);
		}

		function api_transaction($order) {
			global $woocommerce;

			$api_url = 'https://www.paygenius.co.za/api/web-service/transact/manual-payment';

			$params = array();

			$params['gatewayType'] = "0";

			$params['merchantId'] = $this->merchantid;
			$merchantid = $this->merchantid;

			$params['amount'] = $woocommerce->cart->total * 100;
			$params['cardNumber'] = str_replace(" ", "", $_POST['pongopay-card-number']);
			$params['cardCvv'] = $_POST['pongopay-card-cvc'];
			
			$birthday = explode(' / ', $_POST['pongopay-birthday']);
			$birthdayTimestamp = mktime(0, 0, 0, $birthday[1], $birthday[0], $birthday[2]);

			$params['birthday'] = $birthdayTimestamp;

			$params['returnURL'] = $this->get_return_url( $order );

			//explode expiry date
			$expiryArr = explode(" / ", $_POST['pongopay-card-expiry']);
			
			$params['cardExpiryMonth'] = $expiryArr[0];
			$params['cardExpiryYear'] = $expiryArr[1];

			$params['currency'] = $order->get_order_currency();

			$url = $api_url;
			$url .= "?merchantId=".$params['merchantId'];
			$url .= "&amount=".$params['amount'];
			$url .= "&gatewayType=".$params['gatewayType'];
			$url .= "&cardNumber=".$params['cardNumber'];
			$url .= "&cardCvv=".$params['cardCvv'];
			$url .= "&cardExpiryMonth=".$params['cardExpiryMonth'];
			$url .= "&cardExpiryYear=".$params['cardExpiryYear'];
			$url .= "&currency=".$params['currency'];
			$url .= "&returnURL=".urlencode($params['returnURL']);
			$url .= "&name=".$order->billing_first_name;
			$url .= "&surname=".$order->billing_last_name;
			$url .= "&email=".$order->billing_email;
			$url .= "&countryOfResidence=".$order->billing_country;
			$url .= "&nationality=".$order->billing_country;
			$url .= "&birthday=".$params['birthday'];

			//$url .= "&force3dsecure=FORCE";

			error_log($url);

			$response = file_get_contents($url);

			if(substr ($response, 0, 4) == 'null') :
				$response = substr ($response, 4);
		    endif;

			if(!$response) return false;

			$result = json_decode($response, true);
			$result['merchant_id'] = $params['merchant_id'];

			error_log(print_r($result, true));

			return $result;
		}

		function validate_fields() {
			global $woocommerce;

			$cardNumber = $_POST['pongopay-card-number'];
			$cardExpiry = $_POST['pongopay-card-expiry'];
			$cardCVC = $_POST['pongopay-card-cvc'];
			$birthday = $_POST['pongopay-birthday'];

			//Check fields not empty
			if($cardNumber == "" or
				$cardExpiry == "" or 
				$cardCVC == "" or 
				$birthday == "") {

				wc_add_notice( __('Vous devez replir tous les champs du formulaire correctement', 'woothemes'), 'error' );
				return;
			}

		}

		function payment_fields() {
			//return 'test';
	        return $this->credit_card_form(array(), array(
	        	'<p class="form-row form-row-first woocommerce-validated">
	        	  <label for="pongopay-birthday">Birth Day (JJ/MM/YYYY)<span class="required">*</span></label>
	        	  <input id="pongopay-birthday" class="input-text" type="text" autocomplete="off" placeholder="JJ / MM / YYYY" name="pongopay-birthday">
	        	</p>'
	        ));
		}

		function process_payment( $order_id ) {
			global $woocommerce;
			$order = new WC_Order( $order_id );

			//Check transaction with API
			$apiTransact = $this->api_transaction($order);

			if($apiTransact['success'] == 0) {
				wc_add_notice('success = 0', 'error' );

				foreach ($apiTransact['errors'] as $error) {
					wc_add_notice($error, 'error' );
				}

				return;
			}

			// Mark as on-hold (we're awaiting the cheque)
			$order->update_status('on-hold', __( 'Awaiting Pongo Pay payment', 'woocommerce' ));

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			$woocommerce->cart->empty_cart();

			//If transaction require 3DSecure
			if( $apiTransact['redirectUrl'] ) {
				return array(
					'result' => 'success',
					'redirect' => $apiTransact['redirectUrl']
				);
			}

			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url( $order )
			);

		}

		function thankyou( $order ) {
			$order = new WC_Order($order);
			//var_dump($order);
			//print $_REQUEST['gatewayReference'];
			
			if($_REQUEST['gatewayReference']) {
				$params = array();
				$params['gatewayReference'] = $_REQUEST['gatewayReference'];
				$params['merchantId'] = $this->merchantid;

				$url = "https://www.paygenius.co.za/api/web-service/transact/check-transaction-details";
				$url .= "?merchantId=".$params['merchantId'];
				$url .= "&gatewayReference=".$params['gatewayReference'];
				$url .= "&renderFormat";

				$response = file_get_contents($url);

				if(!$response) return false;

				$result = json_decode($response, true);
				
				if($result['success'] == "0") {
					$order->update_status('Cancelled');
					print "Transaction refusé";
				}
				else {
					$order->update_status('Completed');
					print "Transaction accepté";
				}
			}

		}
	}
}

add_action( 'plugins_loaded', 'init_pongopay_gateway_class' );

function add_pongopay_gateway_class( $methods ) {
	$methods[] = 'WC_PongoPay_Gateway';

	return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_pongopay_gateway_class' );
