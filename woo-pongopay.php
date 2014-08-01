<?php
/**
 * @package Akismet
 */
/*
Plugin Name: Woo PongoPay
Plugin URI: http://www.pongosoft.com/
Description: PongoPay Description
Version: 1.0.0
Author: PongoSoft
Author URI: http://www.pongosoft.com
License: GPLv2 or later
Text Domain: woo-pongopay
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

			$api_url = 'https://www.paygenius.co.za/api/web-service/transact';

			$params = array();

			$params['gatewayType'] = "0";
			$params['paymentType'] = "ManualPayment";

			$params['merchantId'] = $this->merchantid;
			$merchantid = $this->merchantid;

			$params['amount'] = $woocommerce->cart->total * 100;
			$params['cardNumber'] = str_replace(" ", "", $_POST['pongopay-card-number']);
			$params['cardCvv'] = $_POST['pongopay-card-cvc'];

			$params['returnURL'] = $this->get_return_url( $order );

			//explode expiry date
			$expiryArr = explode(" / ", $_POST['pongopay-card-expiry']);
			
			$params['cardExpiryMonth'] = $expiryArr[0];
			$params['cardExpiryYear'] = $expiryArr[1];

			$params['currency'] = $order->get_order_currency();

			$url = "https://www.paygenius.co.za/api/web-service/transact";
			$url .= "?merchantId=".$params['merchantId'];
			$url .= "&amount=".$params['amount'];
			$url .= "&paymentType=".$params['paymentType'];
			$url .= "&gatewayType=".$params['gatewayType'];
			$url .= "&cardNumber=".$params['cardNumber'];
			$url .= "&cardCvv=".$params['cardCvv'];
			$url .= "&cardExpiryMonth=".$params['cardExpiryMonth'];
			$url .= "&cardExpiryYear=".$params['cardExpiryYear'];
			$url .= "&currency=".$params['currency'];
			$url .= "&returnURL=".urlencode($params['returnURL']);
			/*$url .= "&force3dsecure=FORCE";*/

			$response = file_get_contents($url);

			if(!$response) return false;

			$result = json_decode($response, true);
			$result['merchant_id'] = $params['merchant_id'];

			return $result;
		}

		function validate_fields() {
			global $woocommerce;

			$cardNumber = $_POST['pongopay-card-number'];
			$cardExpiry = $_POST['pongopay-card-expiry'];
			$cardCVC = $_POST['pongopay-card-cvc'];

			//Check fields not empty
			if($cardNumber == "" or
				$cardExpiry == "" or 
				$cardCVC == "") {

				$woocommerce->add_error( __('Vous devez replir tous les champs du formulaire correctement', 'woothemes') );
				return;
			}

		}

		function payment_fields() {
	        return $this->credit_card_form();
		}

		function process_payment( $order_id ) {
			global $woocommerce;
			$order = new WC_Order( $order_id );

			//Check transaction with API
			$apiTransact = $this->api_transaction($order);

			if($apiTransact['success'] == 0) {
				$woocommerce->add_error( 'success = 0' );
				foreach ($apiTransact['errors'] as $error) {
					$woocommerce->add_error( $error );
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

				$url = "https://www.paygenius.co.za/api/web-service/transact";
				$url .= "?merchantId=".$params['merchantId'];
				$url .= "&paymentType=checkTransactionDetails";
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