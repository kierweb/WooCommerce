<?php
	/**
	 * Gateway class
	 */
	class WC_Cardstream extends WC_Payment_Gateway {

		const MMS_URL                = 'https://mms.cardstream.com';
		const DEFAULT_HOSTED_URL     = 'https://gateway.cardstream.com/hosted/';
		const DEFAULT_DIRECT_URL     = 'https://gateway.cardstream.com/direct/';
		const DEFAULT_MERCHANT_ID    = '100001';
		const DEFAULT_SECRET         = 'Circle4Take40Idea';

		private $gateway     = 'Cardstream';
		public  $gateway_url = '';

		public static $lang;

		public function __construct() {

			$id = str_replace(' ', '', strtolower($this->gateway));

			// Language translation module to use
			self::$lang = strtolower('woocommerce_' . $id);

			$this->has_fields          = false;
			$this->id                  = $id;
			$this->icon                = str_replace('/classes', '/', plugins_url('/', __FILE__)) . '/img/logo.png';
			$this->method_title        = __(ucwords($this->gateway), self::$lang);
			$this->method_description  = __(ucwords($this->gateway) . ' hosted works by sending the user to ' . ucwords($this->gateway) . ' to enter their payment infomation', self::$lang);

			$this->init_form_fields();

			$this->init_settings();

			// Get setting values
			$this->enabled             = isset($this->settings['enabled']) ? $this->settings['enabled'] : 'no';
			$this->title               = isset($this->settings['title']) ? $this->settings['title'] : 'Credit Card via ' . strtoupper($this->gateway);
			$this->description         = isset($this->settings['description']) ? $this->settings['description'] : 'Pay via Credit / Debit Card with ' . strtoupper($this->gateway) . ' secure card processing.';
			$this->gateway             = isset($this->settings['gateway']) ? $this->settings['gateway'] : $this->gateway;
			$this->type                = isset($this->settings['type']) ? $this->settings['type'] : 'hosted';

			// Custom forms
			$this->gateway_url = $this->settings['gatewayURL'];

			if (
				// Make sure we're given an valid URL
				!empty($this->gateway_url) &&
				preg_match('/(http[s]?:\/\/[a-z0-9\.]+(?:\/[a-z]+\/?){1,})/i', $this->gateway_url) != false
			) {
				// Prevent insecure requests
				$this->gateway_url = str_ireplace('http://', 'https://', $this->gateway_url);
				// Always append end slash
				if (preg_match('/(\.php|\/)$/', $this->gateway_url) == false) {
					$this->gateway_url .= '/';
				}
				// Prevent direct requests using hosted
				if (isset($this->settings['type']) && $this->settings['type'] == 'hosted' && preg_match('/(\/direct\/)$/i', $this->gateway_url) != false) {
					$this->gateway_url = self::DEFAULT_HOSTED_URL;
				}
			} else {
				if (isset($this->settings['type']) && $this->settings['type'] == 'direct') {
					$this->gateway_url = self::DEFAULT_DIRECT_URL;
				} else {
					$this->gateway_url = self::DEFAULT_HOSTED_URL;
				}
			}

			// Hooks
			/* 1.6.6 */
			add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));

			/* 2.0.0 */
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

			add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			add_action('woocommerce_api_wc_' . $this->id, array($this, 'process_response'));
			add_action('woocommerce_api_wc_' . $this->id . '_callback', array($this, 'process_response'));

		}

		/**
		 * Initialise Gateway Settings
		 */
		function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title'       => __('Enable/Disable', self::$lang),
					'label'       => __('Enable ' . strtoupper($this->gateway), self::$lang),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),

				'title' => array(
					'title'       => __('Title', self::$lang),
					'type'        => 'text',
					'description' => __('This controls the title which the user sees during checkout.', self::$lang),
					'default'     => __(strtoupper(ucwords($this->gateway)), self::$lang)
				),

				'type' => array(
					'title'       => __('Type of integration', self::$lang),
					'type'        => 'select',
					'options' => array(
						'hosted'  => 'Hosted',
						'iframe'  => 'Embedded (iframe)',
						'direct'  => 'Direct'
					),
					'description' => __('This controls method of integration.', self::$lang),
					'default'     => 'hosted'
				),

				'description' => array(
					'title'       => __('Description', self::$lang),
					'type'        => 'textarea',
					'description' => __('This controls the description which the user sees during checkout.', self::$lang),
					'default'     => 'Pay securely via Credit / Debit Card with ' . ucwords($this->gateway)
				),

				'merchantID' => array(
					'title'       => __('Merchant ID', self::$lang),
					'type'        => 'text',
					'description' => __('Please enter your ' . ucwords($this->gateway) . ' merchant ID', self::$lang),
					'default'     => self::DEFAULT_MERCHANT_ID
				),

				'signature' => array(
					'title'       => __('Signature Key', self::$lang),
					'type'        => 'text',
					'description' => __('Please enter the signature key for the merchant account. This can be changed in the <a href="' . self::MMS_URL . '" target="_blank">MMS</a>', self::$lang),
					'default'     => self::DEFAULT_SECRET
				),

				'formResponsive' => array(
					'title'       => __('Responsive form', self::$lang),
					'type'        => 'select',
					'options' => array(
						'Y'       => 'Yes',
						'N'       => 'No'
					),
					'description' => __('This controls whether the payment form is responsive.', self::$lang),
					'default'     => 'No'
				),

				'gatewayURL' => array(
					'title'       => __('Gateway URL', self::$lang),
					'type'        => 'text',
					'description' => __('Allows the use of custom forms. Leave blank to use default', self::$lang)
				),

				'countryCode' => array(
					'title'       => __('Country Code', self::$lang),
					'type'        => 'text',
					'description' => __('Please enter your 3 digit <a href="http://en.wikipedia.org/wiki/ISO_3166-1" target="_blank">ISO country code</a>', self::$lang),
					'default'     => '826'
				),

			);

		}

		public function capture_order($order_id) {
			global $woocommerce;

			$order     = new WC_Order($order_id);
			$countries = new WC_Countries();
			$amount    = intval(bcmul(round($order->get_total(), 2), 100, 0));

			$billing_address  = $order->get_billing_address_1();
			$billing2 = $order->get_billing_address_2();
			if (!empty($billing2)) {
				$billing_address .= "\n" . $billing2;
			}
			$billing_address .= "\n" . $order->get_billing_city();
			if (!empty($order->get_billing_state())) {
				$billing_address .= "\n" . $order->get_billing_state();
			}
			if (!empty($order->get_billing_country())) {
				$billing_address .= "\n" . $order->get_billing_country();
			}

			// Fields for hash
			$req = array(
				'merchantID'        => $this->settings['merchantID'],
				'amount'            => $amount,
				'countryCode'       => $this->settings['countryCode'],
				'currencyCode'      => $order->get_order_currency(),
				'transactionUnique' => $order->get_order_key() . '-' . time(),
				'orderRef'          => $order_id,
				'customerName'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'customerAddress'   => $billing_address,
				'customerPostCode'  => $order->get_billing_postcode(),
				'customerEmail'     => $order->get_billing_email(),
			);

			if (!empty($order->get_billing_phone())) {
				$req['customerPhone'] = $order->get_billing_phone();
			}

			return $req;
		}


		/**
		 * Generate the form buton
		 */
		public function generate_cardstream_form($order_id) {
			if ($this->type == 'hosted') {
				echo $this->generate_cardstream_hosted_form($order_id);
			} else if ($this->type == 'iframe') {
				echo $this->generate_cardstream_embedded_form($order_id);
			} else if ($this->type == 'direct') {
				echo $this->generate_cardstream_direct_form($order_id);
			} else {
				return null;
			}
		}

		/**
		 * Hosted form
		 */
		public function generate_cardstream_hosted_form($order_id) {
			global $woocommerce;

			$order     = new WC_Order($order_id);
			$redirect  = add_query_arg('wc-api', 'WC_Cardstream', home_url('/'));
			$callback  = add_query_arg('wc-api', 'WC_Cardstream_Callback', home_url('/'));

			$req = array_merge($this->capture_order($order_id), array(
				'redirectURL'       => $redirect,
				'callbackURL'       => $callback,
				'formResponsive'    => $this->settings['formResponsive']
			));

			if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
				$req['signature'] = $this->create_signature($req, $this->settings['signature']);
			}
			echo '<p>' . __('Thank you for your order, please click the button below to pay with ' . ucwords($this->gateway) . '.', self::$lang) . '</p>';
			$form = '<form action="' . $this->gateway_url . '" method="post" id="' . $this->gateway . '_payment_form">';

			foreach ($req as $key => $value) {
				$form .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
			}
			$form .= '<input type="submit" class="button alt" value="' . __('Pay securely via ' . ucwords($this->gateway), self::$lang) . '" />';
			$form .= '&nbsp;<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order', self::$lang) . '</a>';
			$form .= '</form>';

			return $form;

		}

		/**
		 * Embedded form
		 */
		public function generate_cardstream_embedded_form($order_id) {
			global $woocommerce;

			$redirect  = add_query_arg('wc-api', 'WC_Cardstream', home_url('/'));
			$callback  = add_query_arg('wc-api', 'WC_Cardstream_Callback', home_url('/'));

			$req = array_merge($this->capture_order($order_id), array(
				'redirectURL'       => $redirect,
				'callbackURL'       => $callback,
				'formResponsive'    => $this->settings['formResponsive']
			));

			if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
				$req['signature'] = $this->create_signature($req, $this->settings['signature']);
			}

			require(__DIR__ . '/../embedded.php');
			return '';

		}

		/**
		 * Direct form step 1
		 */
		public function generate_cardstream_direct_form($order_id, $errors = array()) {

			global $woocommerce;

			$order      = new WC_Order($order_id);
			$countries  = new WC_Countries();
			$amount     = $order->get_total() * 100;
			$redirect   = add_query_arg('wc-api', 'WC_Cardstream', home_url('/'));
			$callback   = add_query_arg('wc-api', 'WC_Cardstream_Callback', home_url('/'));

			// Fields for hash
			$fields = array(
				'cardNumber' => array(
					'name' => 'Card Number',
					'value' => @$_POST['cardNumber'],
					'required' => 'required'
				),
				'cardExpiryMonth' => array(
					'name' => 'Card Expiry Month',
					'value' => @$_POST['cardExpiryMonth'],
					'required' => 'required',
					'placeholder' => 'MM',
					'maxlength' => '2'
				),
				'cardExpiryYear' => array(
					'name' => 'Card Expiry Year',
					'value' => @$_POST['cardExpiryYear'],
					'required' => 'required',
					'placeholder' => 'YY',
					'maxlength' => '4'
				),
				'cardCVV' => array(
					'name' => 'CVV',
					'value' => @$_POST['cardCVV'],
					'required' => 'required'
				)
			);

			$form = '<form action="' . '//' . $_SERVER[HTTP_HOST] . $_SERVER[REQUEST_URI] . '&step=2" method="post" id="' . $this->gateway . '_payment_form">';


			foreach ($fields as $key => $value) {
				$form .= '<label class="card-label label-' . $key . '">' . $value['name'] . '</label>';

				if (array_search($key, $errors) !== false) {
					$value['style'] = 'border: 1px solid red;';
				}
				$value['name'] = $key;
				$form .= "<input type='text' class='card-input field-${key}'";

				// Go through attribute keys and values
				foreach ($value as $ak => $av) {
					$form .= " ${ak}='${av}'";
				}
				$form .= '/>';
			}
			$form .= '<br/><p><input type="submit" class="button alt" value="' . __('Pay securely via ' . ucwords($this->gateway), self::$lang) . '" /></p>';
			$form .= '&nbsp;<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order', self::$lang) . '</a>';
			$form .= '</form>';

			return $form;

		}

		/**
		 * Direct form step 2
		 */
		public function generate_cardstream_direct_form_step2($order_id, $request = array()) {

			global $woocommerce;

			$order     = new WC_Order($order_id);
			$countries = new WC_Countries();
			$amount    = $order->get_total() * 100;

			$billing_address  = $order->get_billing_address_1();
			$billing2 = $order->get_billing_address_2();
			if (!empty($billing2)) {
				$billing_address .= "\n" . $billing2;
			}
			$billing_address .= "\n" . $order->get_billing_city();
			if (!empty($order->get_billing_state())) {
				$billing_address .= "\n" . $order->get_billing_state();
			}
			if (!empty($order->get_billing_country())) {
				$billing_address .= "\n" . $order->get_billing_country();
			}

			$req = array_merge($this->capture_order($order_id), array(
				'action'            => 'SALE',
				'type'              => 1,
				'cardNumber'        => $_POST['cardNumber'],
				'cardExpiryMonth'   => $_POST['cardExpiryMonth'],
				'cardExpiryYear'    => $_POST['cardExpiryYear'],
				'cardCVV'           => $_POST['cardCVV'],
				'threeDSMD'         => (isset($_REQUEST['MD']) ? $_REQUEST['MD'] : null),
				'threeDSPaRes'      => (isset($_REQUEST['PaRes']) ? $_REQUEST['PaRes'] : null),
				'threeDSPaReq'      => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null)
			));

			if (isset($this->settings['signature']) && !empty($this->settings['signature'])) {
				$req['signature'] = $this->create_signature($req, $this->settings['signature']);
			}


			$ch = curl_init($this->gateway_url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($req));
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			//curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			parse_str(curl_exec($ch), $res);
			curl_close($ch);

			if ($res['responseCode'] == 65802) {

				// Send details to 3D Secure ACS and the return here to repeat request

				$pageUrl = (@$_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://';

				if ($_SERVER['SERVER_PORT'] != '80') {
					$pageUrl .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
				} else {
					$pageUrl .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
				}
				?>
				<p>Your transaction requires 3D Secure Authentication</p>
				<form action="<?=htmlentities($res['threeDSACSURL'])?>" method="post">
					<input type="hidden" name="MD" value="<?=htmlentities($res['threeDSMD'])?>">
					<input type="hidden" name="PaReq" value="<?=htmlentities($res['threeDSPaReq'])?>">
					<input type="hidden" name="TermUrl" value="<?=htmlentities($pageUrl)?>">
					<input type="submit" value="Continue">
				</form>
				<?php

			} else {

				if (empty($res)) {

					$message = __('Payment error: ', 'woothemes') . 'Communication error with server';

					if (method_exists($woocommerce, add_error)) {
						$woocommerce->add_error($message);
					} else {
						wc_add_notice($message, $notice_type = 'error');
					}

					$order->add_order_note(__(ucwords($this->gateway) . ' payment failed. Communication error with server or an empty response was received', self::$lang));
					wp_safe_redirect($order->get_cancel_order_url($order));
					exit;

				}
				return $this->process_response($res);

			}

			return $form;

		}

		/**
		 * Function to generate a signature
		 */
		function create_signature(array $data, $key) {

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
		function process_payment($order_id) {

			$order = new WC_Order($order_id);

			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url(true)
			);

		}


		/**
		 * receipt_page
		 */
		function receipt_page($order) {

			global $woocommerce;

			if (isset($_REQUEST['step']) && (int)$_REQUEST['step'] === 2) {

				$required = array('cardNumber', 'cardCVV', 'cardExpiryMonth', 'cardExpiryYear');
				$errors = array();

				// Check that the required fields are present:
				foreach ($required as $i => $field) {
					if (empty(@$_POST[$required[$i]])) {
						array_push($errors, $field);
					}
				}

				// Check year is a numeric and has either two or four digits
				if (
					array_search('cardExpiryYear', $errors) === false &&
					($year = $_POST['cardExpiryYear']) &&
					is_numeric($year) &&
					(strlen($year) == 2 || strlen($year) == 4)
				) {
					if (strlen($year) == 4) {
						$_POST['cardExpiryYear'] = substr($year, 2);
					}
				} else {
					array_push($errors, 'cardExpiryYear');
				}

				// Check month is a numeric and has two digits
				if (!(
					array_search('cardExpiryMonth', $errors) === false &&
					($month = $_POST['cardExpiryMonth']) &&
					is_numeric($month) && strlen($month) == 2
				)) {
					array_push($errors, 'cardExpiryMonth');
				}


				if (count($errors) > 0) {

					echo $this->generate_cardstream_direct_form($order, $errors);

				} else {

					echo $this->generate_cardstream_direct_form_step2($order, $_REQUEST);

				}

			} else {

				echo $this->generate_cardstream_form($order);

			}

		}

		/**
		 * Redirect to the URL provided depending on integration type
		 */
		private function redirect($url) {
			if ($this->type === 'iframe') {
				echo '<script>window.top.location = "' . $url . '";</script>';
			} else {
				wp_safe_redirect($url);
			}
			exit;
		}

		/**
		 * Check for response from payment gateway
		 */
		function process_response($data = null) {

			global $woocommerce;

			$response = $data ?: $_POST;

			if (empty($response) || !isset($response['orderRef']) || !is_numeric($response['orderRef'])) {
				$this->throw_empty_response();
			}

			$order = new WC_Order((int)$response['orderRef']);
			if (!$this->check_signature($response, $this->settings['signature'])) {
				$this->throw_signature_error($order);
			}

			if (isset($response['responseCode'])) {

				if ($order->status == 'completed') {
					wc_add_notice('Payment successful');
					$this->redirect($this->get_return_url($order));
				} else {

					$orderNotes  = "\r\nResponse Code : {$response['responseCode']}\r\n";
					$orderNotes .= "Message : {$VPMessage}\r\n";
					$orderNotes .= "Amount Received : " . number_format($response['amount'] / 100, 2, '.', ',') . "\r\n";
					$orderNotes .= "Unique Transaction Code : {$response['transactionUnique']}";

					if ($response['responseCode'] === '0') {

						$order->add_order_note(__(ucwords($this->gateway) . ' payment completed.' . $orderNotes, self::$lang));
						$order->payment_complete();
						wc_add_notice('Payment successful');
						$this->redirect($this->get_return_url($order));

					} else {

						$message = __('Payment error: ', 'woothemes') . $response['responseMessage'];

						if (method_exists($woocommerce, add_error)) {
							$woocommerce->add_error($message);
						} else {
							wc_add_notice($message, $notice_type = 'error');
						}

						$order->add_order_note(__(ucwords($this->gateway) . ' payment failed.' . $orderNotes, self::$lang));
						$this->redirect($order->get_cancel_order_url($order));

					}

				}

			} else {
				exit;
			}

		}

		/**
		 * Check the signature received in a response
		 */
		function check_signature(array $data, $key) {
			$current_sig = $data['signature'];
			unset($data['signature']);
			$generated_sig = $this->create_signature($data, $key);
			return ($current_sig === $generated_sig);

		}

		/**
		 * Redirect to the checkout when the server response is not legitimate
		 */
		function throw_signature_error($order){
			$message = "\r\n" . __('Payment error: ', 'woothemes') . "Signature Check Failed";
			if (method_exists($woocommerce, add_error)) {
				$woocommerce->add_error($message);
			} else {
				wc_add_notice($message, $notice_type = 'error');
			}
			$order->add_order_note(__(ucwords($this->gateway).' payment failed' . $message, self::$lang));
			$this->redirect($order->get_checkout_payment_url(true));
			exit;
		}

		function throw_empty_response() {
			$message = 'Payment unsuccessful - empty response (contact Administrator)';
			if (method_exists($woocommerce, add_error)) {
				$woocommerce->add_error($message);
			} else {
				wc_add_notice($message, $notice_type = 'error');
			}
			$this->redirect(get_site_url());
			exit;
		}

		/**
		 * Check for CARDSTREAM Callback Response
		 * @deprecated Duplicate function. Use process_response instead.
		 */
		function process_callback() {
			$this->process_response();
		}

	}
