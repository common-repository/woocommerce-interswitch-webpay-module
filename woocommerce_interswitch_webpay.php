<?php

/**
 * Plugin Name: Woocommerce Interswitch WebPay Module
 * Plugin URI:  http://www.tortoise-it.co.uk
 * Description: A payment gateway plugin for Woocommerce for the Interswitch WebPay system
 * Author:      Sean Barton (Tortoise IT)
 * Author URI:  http://www.tortoise-it.co.uk
 * Version:     1.5
 */

function sb_webpay_init() {
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}
	
	class WC_WebPay extends WC_Payment_Gateway {	
			
		public function __construct() { 
			global $woocommerce;
			
			$this->id		= 'interswitch';
			//$this->icon 		= apply_filters('woocommerce_interswitch_icon', 'http://www.interswitchng.com/images/logo.gif');
			$this->has_fields 	= false;
			$this->liveurl 		= 'https://webpay.interswitchng.com/paydirect/webpay/pay.aspx';
			$this->testurl 		= 'https://testwebpay.interswitchng.com/test_paydirect/webpay/pay.aspx';
			$this->method_title     = __( 'Interswitch - WebPay', 'woocommerce' );
			
			// Load the form fields.
			$this->init_form_fields();
			
			// Load the settings.
			$this->init_settings();
			
			// Define user set variables
			$this->title 		= $this->settings['title'];
			$this->description 	= $this->settings['description'];
			$this->product_id	= $this->settings['product_id'];		
			$this->pay_item_id	= $this->settings['pay_item_id'];		
			$this->mac_key		= $this->settings['mac_key'];		
			$this->testmode		= $this->settings['testmode'];		
			$this->debug		= $this->settings['debug'];	
			$this->debug_email	= $this->settings['debug_email'];	
			$this->thanks_message	= $this->settings['thanks_message'];	
			$this->error_message	= $this->settings['error_message'];	
			$this->feedback_message	= '';
			$this->response_codes	= $this->get_response_codes();
			
			// Actions
			add_action( 'init', array(&$this, 'check_ipn_response') );
			add_action('valid-interswitch-ipn-request', array(&$this, 'successful_request') );
			add_action('woocommerce_receipt_interswitch', array(&$this, 'receipt_page'));
			//add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
			
			add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'thankyou_page'));
			
			
			// Actions
			if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
				// Pre 2.0
				add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
			} else {
				// 2.0
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}			
			
			//Filters
			add_filter('woocommerce_currencies', array($this, 'add_ngn_currency'));
			add_filter('woocommerce_currency_symbol', array($this, 'add_ngn_currency_symbol'), 10, 2);
			
			// Logs
			if ($this->debug=='yes') $this->log = $woocommerce->logger();
			
			if ( !$this->is_valid_for_use() ) $this->enabled = false;
			
		}
	    
		function add_ngn_currency($currencies) {
		     $currencies['NGN'] = __( 'Nigerian Naira (NGN)', 'woocommerce' );
		     return $currencies;
		}
		
		function add_ngn_currency_symbol($currency_symbol, $currency) {
			switch( $currency ) {
				case 'NGN':
					$currency_symbol = '₦';
					break;
			}
			
			return $currency_symbol;
		}    
	    
		function is_valid_for_use() {
			$return = true;
			
			if (!in_array(get_option('woocommerce_currency'), array('NGN'))) {
			    $return = false;
			}
		
			return $return;
		}
	    
		function admin_options() {
	
			echo '<h3>' . __('Interswitch WebPay', 'woocommerce') . '</h3>';
			echo '<p>' . __('Interswitch WebPay works by sending the user to Interswitch to enter their payment information.', 'woocommerce') . '</p>';
			echo '<table class="form-table">';
				
			if ( $this->is_valid_for_use() ) {
				$this->generate_settings_html();
			} else {
				echo '<div class="inline error"><p><strong>' . __( 'Gateway Disabled', 'woocommerce' ) . '</strong>: ' . __( 'Interswitch does not support your store currency.', 'woocommerce' ) . '</p></div>';
			}
				
			echo '</table>';
				
		}
	    
	       function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
								'title' => __( 'Enable/Disable', 'woocommerce' ), 
								'type' => 'checkbox', 
								'label' => __( 'Enable Interswitch WebPay', 'woocommerce' ), 
								'default' => 'yes'
							), 
				'title' => array(
								'title' => __( 'Title', 'woocommerce' ), 
								'type' => 'text', 
								'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ), 
								'default' => __( 'Interswitch WebPay', 'woocommerce' )
							),
				'description' => array(
								'title' => __( 'Description', 'woocommerce' ), 
								'type' => 'textarea', 
								'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ), 
								'default' => __("Pay via Interswitch WebPay;", 'woocommerce')
							),
				'thanks_message' => array(
								'title' => __( 'Thanks message', 'woocommerce' ), 
								'type' => 'textarea', 
								'description' => __( 'The message to show on a successful payment', 'woocommerce' ), 
								'default' => __("Thank you. Your order has been received", 'woocommerce')
							),
				'error_message' => array(
								'title' => __( 'Failure message', 'woocommerce' ), 
								'type' => 'textarea', 
								'description' => __( 'The message to show when a payment has failed', 'woocommerce' ), 
								'default' => __("Sorry. There was a problem with your order", 'woocommerce')
							),
				'product_id' => array(
								'title' => __( 'Product ID', 'woocommerce' ), 
								'type' => 'text', 
								'description' => __( 'Product Identifier for PAYDirect. Provided to you by Interswitch', 'woocommerce' ), 
								'default' => ''
							),
				'pay_item_id' => array(
								'title' => __( 'Pay Item ID', 'woocommerce' ), 
								'type' => 'text', 
								'description' => __( 'PAYDirect Payment Item ID', 'woocommerce' ), 
								'default' => ''
							),
				'mac_key' => array(
								'title' => __( 'Mac Key', 'woocommerce' ), 
								'type' => 'text', 
								'description' => __( 'Mac Key is a hash which would have been given to you when you opened your account with Interswitch', 'woocommerce' ), 
								'default' => ''
							),
				'testmode' => array(
								'title' => __( 'Interswitch Test Mode', 'woocommerce' ), 
								'type' => 'checkbox', 
								'label' => __( 'Enable Interswitch Test Mode', 'woocommerce' ), 
								'default' => 'yes'
							),
				'debug' => array(
								'title' => __( 'Debug', 'woocommerce' ), 
								'type' => 'checkbox', 
								'label' => __( 'Enable logging (<code>woocommerce/logs/interswitch.txt</code>)', 'woocommerce' ), 
								'default' => 'no'
							),
				'debug_email' => array(
								'title' => __( 'IPN Debug Email', 'woocommerce' ), 
								'type' => 'text', 
								'label' => __( 'Email address to send IPN info to. Used for debugging. Blank for no email', 'woocommerce' ), 
								'default' => ''
							)
			);
	    
		}
	    
		function payment_fields() {
		    if ($this->description) {
			echo wpautop(wptexturize($this->description));
		    }
		}
		
		function get_interswitch_args( $order ) {
			global $woocommerce;
			
			$order_id = $order->id . '_' . $this->product_id;
			$redirect_url = $this->get_return_url($order);
			$order_total = round(((number_format($order->get_order_total() + $order->get_order_discount(), 2, '.', ''))*100),0);
			$hash_string = $order_id . $this->product_id . $this->pay_item_id . $order_total . $redirect_url . $this->mac_key;
			$hash = hash('sha512', $hash_string);
			
			if ($this->debug=='yes') {
				$this->log->add( 'interswitch', 'Generating payment form for order #' . $order_id . '.');
			}
			
			$interswitch_args = array(
				'product_id' 			=> $this->product_id,
				'amount' 			=> $order_total,
				'site_name' 			=> site_url(),
				'currency' 			=> 566,
				'pay_item_id'			=> $this->pay_item_id,
				'hash'				=> $hash,
				'txn_ref'			=> $order_id,
				'site_redirect_url'		=> $redirect_url,
				'cust_name'			=> trim($order->billing_first_name . ' ' . $order->billing_last_name),
			);
			
			if (isset($order->user_id)) {
				$interswitch_args['cust_id'] = $order->user_id;
			}
			
			$interswitch_args = apply_filters('woocommerce_interswitch_args', $interswitch_args);
			
			return $interswitch_args;
		}
	
		function generate_interswitch_form( $order_id ) {
			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			$interswitch_args = $this->get_interswitch_args( $order );
			$interswitch_args_array = array();
			
			$interswitch_adr = $this->liveurl;
			if ( $this->testmode == 'yes' ) {
				$interswitch_adr = $this->testurl;
			}
			
			foreach ($interswitch_args as $key => $value) {
				$interswitch_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
			}
			
			$woocommerce->add_inline_js('
				jQuery("body").block({ 
					message: "<img src=\"'.esc_url( $woocommerce->plugin_url() ).'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to Interswitch to make payment.', 'woocommerce').'", 
					overlayCSS: { 
						background: "#fff", 
						opacity: 0.6 
					},
					css: { 
						padding:        20, 
						textAlign:      "center", 
						color:          "#555", 
						border:         "3px solid #aaa", 
						backgroundColor:"#fff", 
						cursor:         "wait",
						lineHeight:	"32px"
					} 
				});
				jQuery("#submit_interswitch_payment_form").click();
			');
			
			$form = '<form action="'.esc_url( $interswitch_adr ).'" method="post" id="interswitch_payment_form">
					' . implode('', $interswitch_args_array) . '
					<input type="submit" class="button-alt" id="submit_interswitch_payment_form" value="'.__('Pay via Interswitch', 'woocommerce').'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancel order &amp; restore cart', 'woocommerce').'</a>
				</form>';
		
			return $form;
		}
		
		function generate_interswitch_try_again_form( $order_id ) {
			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			$interswitch_args = $this->get_interswitch_args( $order );
			$interswitch_args_array = array();
			
			$interswitch_adr = $this->liveurl;
			if ( $this->testmode == 'yes' ) {
				$interswitch_adr = $this->testurl;
			}
			
			foreach ($interswitch_args as $key => $value) {
				$interswitch_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
			}
			
			$form = '<form action="'.esc_url( $interswitch_adr ).'" method="post" id="interswitch_payment_form">
					' . implode('', $interswitch_args_array) . '
					<input type="submit" class="button-alt" id="submit_interswitch_payment_form" value="'.__('Try Again', 'woocommerce').'" />
				</form>';
		
			return $form;
		}
		
		function successful_request( $posted ) {
			global $woocommerce;
			
			$order_ref = $posted['txnref'];
			$order = new WC_Order( (int) $order_ref );
			
			//fool the thanks page into working?
			$_GET['key'] = $order->order_key;
			$_GET['order'] - $order->id;
			
			if ($this->get_transaction_status($order_ref)) {
				// Check order not already completed
				if ($order->status == 'completed') :
					 if ($this->debug=='yes') {
						$this->log->add( 'interswitch', 'Aborting, Order #' . $order_ref . ' is already complete.' );
					 }
					 return false;
				endif;
			
				// Payment completed
				$order->add_order_note( __('IPN payment completed', 'woocommerce') );
				$order->payment_complete();
				$woocommerce->cart->empty_cart();
				
				if ($this->debug=='yes') $this->log->add( 'interswitch', 'Payment complete.' );
				
				foreach ($_GET as $k=>$v) {
					update_post_meta((int)$order_ref, $k, $v);
				}
				
				update_post_meta( (int) $order_ref, 'Payment Method', 'Interswitch');
				
				$this->feedback_message = $this->thanks_message;
			} else {
				$error_code = '';
				if (@$_GET['resp']) {
					$error_code = $this->response_codes[$_GET['resp']];
				}
				
				$try_again = $this->generate_interswitch_try_again_form($this->parse_txn_ref_order_id($order_ref));
				
				$order->add_order_note(__('Payment Failed - ' . $error_code, 'woocommerce'));
				$order->update_status('failed');
				
				$woocommerce->add_error('Transaction Failed: ' . $error_code . ' ' . $try_again); // Transaction Failed Notice on Checkout
				
				$this->feedback_message = $this->failed_message . $error_code . ' ' . $try_again;
				
				if ($this->debug=='yes') $this->log->add( 'interswitch', 'Payment error: ' . $error_code . ' raw data: ' . serialize($_GET));
			}
		}
		
		function thankyou_page() {
			echo wpautop($this->feedback_message);
		}
		
		public function get_response_codes() {
			return array(
				'00'=>'Approved by Financial Institution'
				,'01'=>'Refer to Financial Institution'
				,'02'=>'Refer to Financial Institution, Special Condition'
				,'03'=>'Invalid Merchant'
				,'04'=>'Pick-up card'
				,'05'=>'Do Not Honor'
				,'06'=>'Error'
				,'07'=>'Pick-Up Card, Special Condition'
				,'08'=>'Honor with Identification'
				,'09'=>'Request in Progress'
				,'10'=>'Approved by Financial Institution, Partial'
				,'11'=>'Approved by Financial Institution, VIP'
				,'12'=>'Invalid Transaction'
				,'13'=>'Invalid Amount'
				,'14'=>'Invalid Card Number'
				,'15'=>'No Such Financial Institution'
				,'16'=>'Approved by Financial Institution, Update Track 3'
				,'17'=>'Customer Cancellation'
				,'18'=>'Customer Dispute'
				,'19'=>'Re-enter Transaction'
				,'20'=>'Invalid Response from Financial Institution'
				,'21'=>'No Action Taken by Financial Institution'
				,'22'=>'Suspected Malfunction'
				,'23'=>'Unacceptable Transaction Fee'
				,'24'=>'File Update not Supported'
				,'25'=>'Unable to Locate Record'
				,'26'=>'Duplicate Record'
				,'27'=>'File Update Field Edit Error'
				,'28'=>'File Update File Locked'
				,'29'=>'File Update Failed'
				,'30'=>'Format Error'
				,'31'=>'Bank Not Supported'
				,'32'=>'Completed Partially by Financial Institution'
				,'33'=>'Expired Card, Pick-Up'
				,'34'=>'Suspected Fraud, Pick-Up'
				,'35'=>'Contact Acquirer, Pick-Up'
				,'36'=>'Restricted Card, Pick-Up'
				,'37'=>'Call Acquirer Security, Pick-Up'
				,'38'=>'PIN Tries Exceeded, Pick-Up'
				,'39'=>'No Credit Account'
				,'40'=>'Function not Supported'
				,'41'=>'Lost Card, Pick-Up'
				,'42'=>'No Universal Account'
				,'43'=>'Stolen Card, Pick-Up'
				,'44'=>'No Investment Account'
				,'51'=>'Insufficient Funds'
				,'52'=>'No Check Account'
				,'53'=>'No Savings Account'
				,'54'=>'Expired Card'
				,'55'=>'Incorrect PIN'
				,'56'=>'No Card Record'
				,'57'=>'Transaction not permitted to Cardholder'
				,'58'=>'Transaction not permitted on Terminal'
				,'59'=>'Suspected Fraud'
				,'60'=>'Contact Acquirer'
				,'61'=>'Exceeds Withdrawal Limit'
				,'62'=>'Restricted Card'
				,'63'=>'Security Violation'
				,'64'=>'Original Amount Incorrect'
				,'65'=>'Exceeds withdrawal frequency'
				,'66'=>'Call Acquirer Security'
				,'67'=>'Hard Capture'
				,'68'=>'Response Received Too Late'
				,'75'=>'PIN tries exceeded'
				,'76'=>'Reserved for Future Postilion Use'
				,'77'=>'Intervene, Bank Approval Required'
				,'78'=>'Intervene, Bank Approval Required for Partial Amount'
				,'90'=>'Cut-off in Progress'
				,'91'=>'Issuer or Switch Inoperative'
				,'92'=>'Routing Error'
				,'93'=>'Violation of law'
				,'94'=>'Duplicate Transaction'
				,'95'=>'Reconcile Error'
				,'96'=>'System Malfunction'
				,'98'=>'Exceeds Cash Limit'
				,'A0'=>'Unexpected error'
				,'A4'=>'Transaction not permitted to card holder, via channels'
				,'Z0'=>'Transaction Status Unconfirmed'
				,'Z1'=>'Transaction Error'
				,'Z2'=>'Bank account error'
				,'Z3'=>'Bank collections account error'
				,'Z4'=>'Interface Integration Error'
				,'Z5'=>'Duplicate Reference Error'
				,'Z6'=>'Incomplete Transaction'
				,'Z7'=>'Transaction Split Pre-processing Error'
				,'Z8'=>'Invalid Card Number, via channels'
				,'Z9'=>'Transaction not permitted to card holder, via channels'
			);
		}	
		
		function interswitch_thanks() {
			echo $this->response_codes(urldecode($_GET['desc']));
			
			if ($GET['desc'] != '00') {
				//$this->generate_interswitch_form($_POST['txnref']);
			}
		}
		
		function parse_txn_ref_order_id($txnref) {
			$txnref = htmlentities($txnref);
			$txn_details = explode('_', $txnref);
			$product_id = $txn_details[1];
			$order_id = $txn_details[0];
			
			return $order_id;
		}	
		
		function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );
			
			return array(
				'result' => 'success',
				'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			);
		}
		
		function receipt_page( $order ) {
			echo '<p>'.__('Thank you for your order, please click the button below to pay with Interswitch.', 'woocommerce').'</p>';
			
			echo $this->generate_interswitch_form( $order );
		}
		
		function check_ipn_response() {
			if (isset($_POST['retRef'])) {
				@ob_clean();
					
				$_POST = stripslashes_deep($_POST);
				
				header('HTTP/1.1 200 OK');
				do_action("valid-interswitch-ipn-request", $_POST);
			}
		}
		
		function get_transaction_status($txnref) {
			$return = false;
			$txnref = htmlentities($txnref);
			$txn_details = explode('a', $txnref);
			$product_id = $txn_details[1];
			//$hash_string = $product_id . $txnref . $this->mac_key;
			//$hash = hash('sha512', $hash_string);
			
			$endpoint = "https://webpay.interswitchng.com/paydirect/services/TransactionQueryURL.aspx";
			if ($this->testmode) {
				$endpoint = "https://testwebpay.interswitchng.com/test_paydirect/services/TransactionQueryURL.aspx";
			}
			
			$endpoint .= '?transRef=' . $txnref . '&prodID=' . $product_id . '&echo=1&redirectURL=http://www.google.co.uk';
			
			//echo $endpoint;
			
			set_time_limit(0);
			
			$ch = curl_init($endpoint);
			
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION ,1);
			curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,120);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER ,false);
			curl_setopt($ch, CURLOPT_TIMEOUT, 120);
			
			$output = curl_exec($ch);
			$last_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			
			curl_close($ch);
			
			if ($last_url) {
				if ($url = parse_url($last_url)) {
					if ($pairs = explode('&', $url['query'])) {
						foreach ($pairs as $i=>$pair) {
							$kv = explode('=', $pair);
							
							$_GET[$kv[0]] = $kv[1];
							$_REQUEST[$kv[0]] = $kv[1];
							
							if ($kv[0] == 'resp') {
								if ($kv[1] == '00') {
									$return = true;
								}
							}
						}
					}
				}
			}
			
			if ($this->debug_email) {
				wp_mail($this->debug_email, 'Interswitch IPN Debug Feedback', print_r($_GET, true) . "\n" . $endpoint . "\n" . $last_url);
			}
			
			return $return;
		}	
	
 	}
	
	/**
	 * Add the gateway to WooCommerce
	 **/
	function add_webpay_gateway( $methods ) {
		$methods[] = 'WC_WebPay'; return $methods;
	}
	
	add_filter('woocommerce_payment_gateways', 'add_webpay_gateway' );
}



add_filter('plugins_loaded', 'sb_webpay_init' );

?>