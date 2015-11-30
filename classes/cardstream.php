<?php
	/**
	 * Gateway class
	 */
	class WC_Cardstream_Hosted extends WC_Payment_Gateway {

		private $gateway 		= 'cardstream';
		private $test_ac 		= 100001;
		private $secret			= 'Circle4Take40Idea';
		private $mms_url 		= 'https://mms.cardstream.com';
		private $gateway_hosted_url 	= 'https://gateway.cardstream.com/hosted/';
		private $gateway_direct_url 	= 'https://gateway.cardstream.com/direct/';
		public  $gateway_url 	= 'none';

		public function __construct() {

			$this->id     				= $this->gateway;
			$this->method_title   		= __(ucwords($this->gateway) , 'woocommerce_cardstream');
			$this->method_description 	= __(ucwords($this->gateway) . ' hosted works by sending the user to ' . ucwords($this->gateway) . ' to enter their payment infocardstream-hosted.phpmation', 'woocommerce_cardstream');
			$this->icon     			= str_replace('/classes', '/', plugins_url( '/', __FILE__ )) . '/img/logo.png';
			$this->has_fields    		= false;
			
			$this->init_form_fields();

			$this->init_settings();

			// Get setting values
			$this->enabled       		= isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
			$this->title        		= isset( $this->settings['title'] ) ? $this->settings['title'] : 'Credit Card via CARDSTREAM';
			$this->description       	= isset( $this->settings['description'] ) ? $this->settings['description'] : 'Pay via Credit / Debit Card with CARDSTREAM secure card processing.';
			$this->gateway 				= isset( $this->settings['gateway'] ) ? $this->settings['gateway'] : 'cardstream';
			$this->type 				= isset( $this->settings['type'] ) ? $this->settings['type'] : 'hosted';
			if (isset($this->settings['type'])) {
				$this->gateway_url = (($this->settings['type'] == 'hosted') ? $this->gateway_hosted_url : $this->gateway_direct_url);
			} else {
				$this->gateway_url = $gateway_direct_url;
			}

			// Hooks
			/* 1.6.6 */
			add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );

			/* 2.0.0 */
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			add_action('woocommerce_receipt_cardstream', array($this, 'receipt_page'));
			add_action('woocommerce_api_wc_cardstream_hosted', array($this, 'process_response'));
			add_action('woocommerce_api_wc_cardstream_callback', array($this, 'process_callback'));

		}

		/**
		 * Initialise Gateway Settings
		 */
		function init_form_fields() {

			$this->form_fields = array(
				'enabled'		=> array(
					'title'   		=> __( 'Enable/Disable', 'woocommerce_cardstream' ),
					'label'   		=> __( 'Enable CARDSTREAM', 'woocommerce_cardstream' ),
					'type'    		=> 'checkbox',
					'description'  	=> '',
					'default'   	=> 'no'
				),

				'title'			=> array(
					'title'   		=> __( 'Title', 'woocommerce_cardstream' ),
					'type'    		=> 'text',
					'description'  	=> __( 'This controls the title which the user sees during checkout.', 'woocommerce_cardstream' ),
					'default'   	=> __( strtoupper(ucwords($this->gateway)), 'woocommerce_cardstream' )
				),

				'type'			=> array(
					'title'   		=> __( 'Type of integration', 'woocommerce_cardstream' ),
					'type'    		=> 'select',
					'options'       => array(
						'hosted'    => 'Hosted',
						'direct'    => 'Direct'
					),
					'description'  	=> __( 'This controls method of integration.', 'woocommerce_cardstream' ),
					'default'   	=> 'hosted'
				),

				'description'	=> array(
					'title'   		=> __( 'Description', 'woocommerce_cardstream' ),
					'type'    		=> 'textarea',
					'description'  	=> __( 'This controls the description which the user sees during checkout.', 'woocommerce_cardstream' ),
					'default'   	=> 'Pay securely via Credit / Debit Card with ' . ucwords($this->gateway)
				),

				'merchantID'	=> array(
					'title'   		=> __( 'Merchant ID', 'woocommerce_cardstream' ),
					'type'    		=> 'text',
					'description'  	=> __( 'Please enter your ' . ucwords($this->gateway) . ' merchant ID', 'woocommerce_cardstream' ),
					'default'   	=> $this->test_ac
				),

				'signature'	=> array(
					'title'   		=> __( 'Signature Key', 'woocommerce_cardstream' ),
					'type'    		=> 'text',
					'description'  	=> __( 'Please enter the signature key for the merchant account. This can be changed in the <a href="'.$this->mms_url.'" target="_blank">MMS</a>', 'woocommerce_cardstream' ),
					'default'   	=> $this->secret
				),

				'countryCode'	=> array(
					'title'   		=> __( 'Country Code', 'woocommerce_cardstream' ),
					'type'    		=> 'text',
					'description'  	=> __( 'Please enter your 3 digit <a href="http://en.wikipedia.org/wiki/ISO_3166-1" target="_blank">ISO country code</a>', 'woocommerce_cardstream' ),
					'default'   	=> '826'
				),

			);

		}


		/**
		 * Generate the form buton
		 */

		public function generate_cardstream_form($order_id) {
			if ( $this->type == 'hosted' ) {
				echo $this->generate_cardstream_hosted_form($order_id);
			} else if ( $this->type == 'direct' ) {
				echo $this->generate_cardstream_direct_form($order_id);
			} else if ( $this->type == '3d_direct' ) {
				echo $this->generate_cardstream_3d_secure_direct_form($order_id);
			} else {
				return null;
			}
		}

		/**
		 * Hosted form
		 */
		public function generate_cardstream_hosted_form($order_id) {

			global $woocommerce;

			$order 		= new WC_Order( $order_id );
			$countries	= new WC_Countries();
			$amount 	= $order->get_total() * 100;
			$redirect 	= str_replace( 'https:', 'http:', add_query_arg('wc-api', 'WC_Cardstream_Hosted', home_url( '/' ) ) );
			$callback 	= str_replace( 'https:', 'http:', add_query_arg('wc-api', 'WC_Cardstream_Callback', home_url( '/' ) ) );

			$billing_address  = $order->billing_address_1 . "\n";
			if (isset($order->billing_address_2) && !empty($order->billing_address_2)) {
				$billing_address .= $order->billing_address_2 . "\n";
			}
			$billing_address .= $order->billing_city . "\n";
			$billing_address .= $order->billing_state;

			// Fields for hash
			$fields = array(
				"merchantID" 		=> $this->settings['merchantID'],
				"amount" 			=> $amount,
				"countryCode" 		=> $this->settings['countryCode'],
				"currencyCode" 		=> $order->get_order_currency(),
				"transactionUnique" => $order->order_key . '-' . time(),
				"orderRef" 			=> $order->id,
				"redirectURL" 		=> $redirect,
				"callbackURL" 		=> $callback,
				"customerName" 		=> "{$order->billing_first_name} {$order->billing_last_name}",
				"customerAddress"	=> $billing_address,
				"customerPostCode"	=> $order->billing_postcode,
				"customerEmail" 	=> $order->billing_email,
				"customerPhone" 	=> $order->billing_phone
			);

			if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
				$fields['signature'] = $this->createSignature($fields, $this->settings['signature']);
			}

			$form = '<form action="' . $this->gateway_url . '" method="post" id="' . $this->gateway . '_payment_form">';

			foreach ( $fields as $key => $value ) {
				$form .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
			}
			$form .= '<input type="submit" class="button alt" value="'.__('Pay securly via ' . ucwords( $this->gateway ), 'woocommerce_cardstream').'" />';
			$form .= '<a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order', 'woocommerce_cardstream').'</a>';
			$form .= '</form>';

			return $form;

		}

		/**
		 * Direct form step 1
		 */
		public function generate_cardstream_direct_form($order_id) {

			global $woocommerce;

			$order 		= new WC_Order( $order_id );
			$countries	= new WC_Countries();
			$amount 	= $order->get_total() * 100;
			$redirect 	= str_replace( 'https:', 'http:', add_query_arg('wc-api', 'WC_Cardstream_Hosted', home_url( '/' ) ) );
			$callback 	= str_replace( 'https:', 'http:', add_query_arg('wc-api', 'WC_Cardstream_Callback', home_url( '/' ) ) );

			$billing_address  = $order->billing_address_1 . "\n";
			if (isset($order->billing_address_2) && !empty($order->billing_address_2)) {
				$billing_address .= $order->billing_address_2 . "\n";
			}
			$billing_address .= $order->billing_city . "\n";
			$billing_address .= $order->billing_state;

			// Fields for hash
			$fields = array(
				"cardNumber" => array(
					"name" => "Card Number",
					"value" => "",
					"required" => "required"
				),
				"cardExpiryMonth" => array(
					"name" => "Card Expiry Month",
					"value" => date('m'),
					"required" => "required"
				),
				"cardExpiryYear" => array(
					"name" => "Card Expiry Year",
					"value" => date('y'),
					"required" => "required"
				),
				"cardCVV" => array(
					"name" => "CVV",
					"value" => "",
					"required" => "required"
				),
				"customerName" => array(
					"name" => "Name",
					"value" => "{$order->billing_first_name} {$order->billing_last_name}",
					"required" => "required"
				),
				"customerEmail" => array(
					"name" => "Email",
					"value" => $order->billing_email,
					"required" => "required"
				),
				"customerPhone" => array(
					"name" => "Phone",
					"value" => $order->billing_phone,
					"required" => ""
				),
				"customerAddress" => array(
					"name" => "Address",
					"value" => $billing_address,
					"required" => "required"
				),
				"customerPostCode" => array(
					"name" => "Post Code",
					"value" => $order->billing_postcode,
					"required" => "required"
				),
			);
			
			$form = '<form action="' . '//' . $_SERVER[HTTP_HOST].$_SERVER[REQUEST_URI] . '&step=2" method="post" id="' . $this->gateway . '_payment_form">';

			foreach ( $fields as $key => $value ) {
				$form .= '<label class="card-label label-'.$key.'">' . $value['name'] . '</label>';
				$form .= '<input type="text" class="card-input field-'. $key .'" name="' . $key . '" value="' . $value['value'] . '" ' . $value['required'] . '" />';
			}
			$form .= '<p><input type="submit" class="button alt" value="'.__('Pay securly via ' . ucwords( $this->gateway ), 'woocommerce_cardstream').'" /></p>';
			$form .= '<a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order', 'woocommerce_cardstream').'</a>';
			$form .= '</form>';

			return $form;

		}

		/**
		 * Direct form step 2
		 */
		public function generate_cardstream_direct_form_step2( $order_id, $request = array() ) {

			global $woocommerce;

			$order 		= new WC_Order( $order_id );
			$countries	= new WC_Countries();
			$amount 	= $order->get_total() * 100;

			// Fields for hash
			$req = array(
				"merchantID" => $this->settings['merchantID'],
				"action" => "SALE",
				"type" => 1,
				"transactionUnique" => $order->order_key . '-' . time(),
				"currencyCode" => $order->get_order_currency(),
				"amount" => $amount,
				"orderRef" => $order->id,
				"cardNumber" => $_REQUEST['cardNumber'],
				"cardExpiryMonth" => $_REQUEST['cardExpiryMonth'],
				"cardExpiryYear" => $_REQUEST['cardExpiryYear'],
				"cardCVV" => $_REQUEST['cardCVV'],
				"customerName" => $_REQUEST['customerName'],
				"customerEmail" => $_REQUEST['customerEmail'],
				"customerPhone" => $_REQUEST['customerPhone'],
				"customerAddress" => $_REQUEST['customerAddress'],
				"countryCode" => $this->settings['countryCode'],
				"customerPostCode" => $_REQUEST['customerPostCode'],
				"threeDSMD" => (isset($_REQUEST['MD']) ? $_REQUEST['MD'] : null),
				"threeDSPaRes" => (isset($_REQUEST['PaRes']) ? $_REQUEST['PaRes'] : null),
				"threeDSPaReq" => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null)
			);

			// Add Signature field to the end of the request.
			$req['signature'] = $this->createSignature($req, $this->settings['signature']);


			$ch = curl_init($this->gateway_url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($req));
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			parse_str(curl_exec($ch), $res);
			curl_close($ch);

			if ($res['responseCode'] == 65802) {

				// Send details to 3D Secure ACS and the return here to repeat request

				$pageUrl = (@$_SERVER["HTTPS"] == "on") ? "https://" : "http://";

				if ($_SERVER["SERVER_PORT"] != "80") {
					$pageUrl .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
				} else {
					$pageUrl .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
				}

				echo "<p>Your transaction requires 3D Secure Authentication</p>
		          <form action=\"" . htmlentities($res['threeDSACSURL']) . "\" method=\"post\">
		            <input type=\"hidden\" name=\"MD\" value=\"" . htmlentities($res['threeDSMD']) . "\">
		            <input type=\"hidden\" name=\"PaReq\" value=\"" . htmlentities($res['threeDSPaReq']) . "\">
		            <input type=\"hidden\" name=\"TermUrl\" value=\"" . htmlentities($pageUrl) . "\">
		            <input type=\"submit\" value=\"Continue\">
		         </form>";

			} elseif (isset($res['signature'])) {
				$orderNotes  =  "\r\nResponse Code : {$res['responseCode']}\r\n";
				$orderNotes .=  "Message : ". htmlentities($res['responseMessage']) . "\r\n";
				$orderNotes .=  "Amount Received : " . number_format($res['amount'] / 100, 2, '.', ',') . "\r\n";
				$orderNotes .=  "Unique Transaction Code : {$res['transactionUnique']}";

				$return_signature = $res['signature'];

				// Remove the signature as this isn't hashed in the return
				unset($res['signature']);

				// The returned hash will always be SHA512
				if ($return_signature == $this->createSignature($res, $this->settings['signature'])) {

					echo "<p>Signature Check OK!</p>" . PHP_EOL;

					if ($res['responseCode'] === "0") {

						$order->add_order_note( __(ucwords( $this->gateway ).' payment completed.' . $orderNotes, 'woocommerce_cardstream') );
						$order->payment_complete();
						echo "<p>Thank you for your payment</p>" . PHP_EOL;
						exit;
					} else {

						echo "<p>Failed to take payment: " . htmlentities($res['responseMessage']) . "</p>" . PHP_EOL;
					}
				} else {

					die("Sorry, the signature check failed");

				}
			} else {

				if ($res['responseCode'] === "0") {

					$order->add_order_note( __(ucwords( $this->gateway ).' payment completed.' . $orderNotes, 'woocommerce_cardstream') );
					$order->payment_complete();
					echo "<p>Thank you for your payment</p>";
					exit;
				} else {

					echo "<p>Failed to take payment: " . htmlentities($res['responseMessage']) . "</p>" . PHP_EOL;

				}
			}

			return $form;

		}

		/**
		 * 3D Secure Direct form
		 */
		public function generate_cardstream_3d_secure_direct_form( $order_id ) {

			global $woocommerce;

			$order 		= new WC_Order( $order_id );
			$countries	= new WC_Countries();
			$amount 	= $order->get_total() * 100;
			$redirect 	= str_replace( 'https:', 'http:', add_query_arg('wc-api', 'WC_Cardstream_Hosted', home_url( '/' ) ) );
			$callback 	= str_replace( 'https:', 'http:', add_query_arg('wc-api', 'WC_Cardstream_Callback', home_url( '/' ) ) );

			$billing_address  = $order->billing_address_1 . "\n";
			if (isset($order->billing_address_2) && !empty($order->billing_address_2)) {
				$billing_address .= $order->billing_address_2 . "\n";
			}
			$billing_address .= $order->billing_city . "\n";
			$billing_address .= $order->billing_state;

			// Fields for hash
			$fields = array(
				"merchantID" => $this->settings['merchantID'],
				"action" => "SALE",
				"type" => 1,
				"transactionUnique" => $order->order_key . '-' . time(),
				"currencyCode" => $order->get_order_currency(),
				"amount" => $amount,
				"orderRef" => $order->id,
				"cardNumber" => "",
				"cardExpiryMonth" => date('m'),
				"cardExpiryYear" => date('y'),
				"cardCVV" => '',
				"customerName" => "{$order->billing_first_name} {$order->billing_last_name}",
				"customerEmail" => $order->billing_email,
				"customerPhone" => $order->billing_phone,
				"customerAddress" => $billing_address,
				"countryCode" => $this->settings['countryCode'],
				"customerPostCode" => $order->billing_postcode,
				"threeDSMD" => (isset($_REQUEST['MD']) ? $_REQUEST['MD'] : null),
				"threeDSPaRes" => (isset($_REQUEST['PaRes']) ? $_REQUEST['PaRes'] : null),
				"threeDSPaReq" => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null)
			);

			if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
				$fields['signature'] = $this->createSignature($fields, $this->settings['signature']);
			}

			$form = '<form action="' . $this->gateway_url . '" method="post" id="' . $this->gateway . '_payment_form">';

			foreach ( $fields as $key => $value ) {
				$form .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
			}
			$form .= '<input type="submit" class="button alt" value="'.__('Pay securly via ' . ucwords( $this->gateway ), 'woocommerce_cardstream').'" />';
			$form .= '<a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order', 'woocommerce_cardstream').'</a>';
			$form .= '</form>';

			return $form;
		}

		/**
		 * Function to generate a signature
		 */

		function createSignature(array $data, $key) {

			if (!$key || !is_string($key) || $key === '' || !$data || !is_array($data)) {
					return null;
			}
			
			ksort($data);
			
			// Create the URL encoded signature string
			$ret = http_build_query($data, '', '&');
			
			// Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
			$ret = preg_replace('/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', $ret);
			
			// Hash the signature string and the key together
			return hash('SHA512', $ret . $key);
			
		}



		/**
		 * Process the payment and return the result
		 */
		function process_payment( $order_id ) {

			$order = new WC_Order($order_id);

			return array(
				'result'    => 'success',
				'redirect'	=> $order->get_checkout_payment_url( true )
			);

		}


		/**
		 * receipt_page
		 */
		function receipt_page( $order ) {
			if (isset($_REQUEST['step']) && (int)$_REQUEST['step'] === 2) {
				echo $this->generate_cardstream_direct_form_step2($order, $_REQUEST);
			} else {
				echo '<p>' . __('Thank you for your order, please click the button below to pay with ' . ucwords($this->gateway) . '.', 'woocommerce_cardstream') . '</p>';
				echo $this->generate_cardstream_form($order);
			}
		}
				
		

		/**
		 * Check for CARDSTREAM Response
		 */
		function process_response() {
		
			global $woocommerce;
			
			$response = $_POST;
			
			if (isset($response['responseCode'])) {
			
				$order	= new WC_Order((int) $response['orderRef']);
			
				$return_sig = $response['signature'];
				unset($response['signature']);
				
				if ($return_sig != $this->createSignature($response, $this->settings['signature'])) {
				
					$message = __('Payment error: Response Signature Mismatch', 'woothemes');
					
					if (method_exists($woocommerce, add_error)) {
						$woocommerce->add_error($message);
					} else {
						wc_add_notice($message, $notice_type = 'error');
					}
					
					$order->add_order_note( __(ucwords( $this->gateway ).' payment failed.', 'woocommerce_cardstream') );
					wp_safe_redirect( $order->get_cancel_order_url( $order ) );
					exit;					
				}

				if ($order->status == 'completed') {

				} else {

					$orderNotes  =  "\r\nResponse Code : {$response['responseCode']}\r\n";
					$orderNotes .=  "Message : {$VPMessage}\r\n";
					$orderNotes .=  "Amount Received : " . number_format($response['amount'] / 100, 2, '.', ',') . "\r\n";
					$orderNotes .=  "Unique Transaction Code : {$response['transactionUnique']}";

					if ($response['responseCode'] === '0') {
					
						$order->add_order_note( __(ucwords( $this->gateway ).' payment completed.' . $orderNotes, 'woocommerce_cardstream') );
						$order->payment_complete();

						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
						
					} else {
					
						$message = __('Payment error: ', 'woothemes') . $response['responseMessage'];
						
						if (method_exists($woocommerce, add_error)) {
							$woocommerce->add_error($message);
						} else {
							wc_add_notice($message, $notice_type = 'error');
						}
						
						$order->add_order_note( __(ucwords( $this->gateway ).' payment failed.' . $orderNotes, 'woocommerce_cardstream') );
						wp_safe_redirect( $order->get_cancel_order_url( $order ) );
						exit;

					}

				}

			} else { 
				exit;
			}

		}

	}

	/**
	 * Callback url class
	 */
	class WC_Cardstream_Callback extends WC_Payment_Gateway {		

		public function __construct() {

			$this->process_callback();

			// Hooks
			add_action('woocommerce_api_wc_cardstream_callback', array($this, 'process_callback'));

		}



		/**
		 * Function to generate a signature
		 */

		function createSignature(array $data, $key) {

			if (!$key || !is_string($key) || $key === '' || !$data || !is_array($data)) {
					return null;
			}
			
			ksort($data);
			
			// Create the URL encoded signature string
			$ret = http_build_query($data, '', '&');
			
			// Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
			$ret = preg_replace('/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', $ret);
			
			// Hash the signature string and the key together
			return hash('SHA512', $ret . $key);
			
		}

		/**
		 * Check for CARDSTREAM Callback Response
		 */
		function process_callback() {
			global $woocommerce;
			$response = $_POST;
			if (isset($response['responseCode'])) {
			
				$order	= new WC_Order((int) $response['orderRef']);
			
				$return_sig = $response['signature'];
				unset($response['signature']);
				
				if ($return_sig != $this->createSignature($response, $this->settings['signature'])) {
				
					$message = __('Payment error: Response Signature Mismatch', 'woothemes');
					
					if (method_exists($woocommerce, add_error)) {
						$woocommerce->add_error($message);
					} else {
						wc_add_notice($message, $notice_type = 'error');
					}
					
					$order->add_order_note( __(ucwords( $this->gateway ).' payment failed.', 'woocommerce_cardstream') );
					wp_safe_redirect( $order->get_cancel_order_url( $order ) );
					exit;					
				}

				if ($order->status == 'completed') {

				} else {

					$orderNotes  =  "\r\nResponse Code : {$response['responseCode']}\r\n";
					$orderNotes .=  "Message : {$VPMessage}\r\n";
					$orderNotes .=  "Amount Received : " . number_format($response['amount'] / 100, 2, '.', ',') . "\r\n";
					$orderNotes .=  "Unique Transaction Code : {$response['transactionUnique']}";

					if ($response['responseCode'] === '0') {
					
						$order->add_order_note( __(ucwords( $this->gateway ).' payment completed.' . $orderNotes, 'woocommerce_cardstream') );
						$order->payment_complete();
						exit;
						
					} else {
					
						$message = __('Payment error: ', 'woothemes') . $response['responseMessage'];
						
						if (method_exists($woocommerce, add_error)) {
							$woocommerce->add_error($message);
						} else {
							wc_add_notice($message, $notice_type = 'error');
						}
						
						$order->add_order_note( __(ucwords( $this->gateway ).' payment failed.' . $orderNotes, 'woocommerce_cardstream') );
						exit;

					}

				}

			} else { 
				exit;
			}

		}

	}