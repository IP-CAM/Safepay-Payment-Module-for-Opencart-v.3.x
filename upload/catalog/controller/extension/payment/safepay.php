<?php
class ControllerExtensionPaymentSafepay extends Controller {
	protected $PRODUCTION_CHECKOUT_URL = "https://www.getsafepay.com/components?";
	protected $SANDBOX_CHECKOUT_URL = "https://sandbox.api.getsafepay.com/components?";
	
	public function index() {
		$this->load->language('extension/payment/safepay');
		$this->load->model('checkout/order');
		$data['payment_safepay_mode'] = $this->config->get('payment_safepay_mode');
		$data['currency'] = $this->session->data['currency'];
		$data['payment_safepay_sandbox_apikey'] = $this->config->get('payment_safepay_sandbox_apikey');
		$data['payment_safepay_apikey'] = $this->config->get('payment_safepay_apikey');
		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		$data['order_info'] = $order_info;
		$this->load->model('localisation/country');
		$country_info = $this->model_localisation_country->getCountry($order_info['payment_country_id']);
		//Fixing Country code bug on opencart 
		//https://forum.opencart.com/viewtopic.php?t=18537
		$country = "";
		if (isset($country_info['iso_code_2'])) {
			$country = $country_info['iso_code_2'];
		} elseif (isset($country_info['payment_iso_code_2'])) {
			$country = $country_info['payment_iso_code_2'];
		}
		$data['country'] = $country;
		$this->load->model('localisation/zone');
		$zone_info = $this->model_localisation_zone->getZone($order_info['payment_zone_id']);
		$data['province'] = $zone_info['code'];
		$data['total'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
		if ($this->request->server['HTTPS']) {
			$server = $this->config->get('config_ssl');
		} else {
			$server = $this->config->get('config_url');
		}
		
		$data['safepay_logo'] = $server . 'catalog/view/theme/default/image/safepay/safepay.png';
		
		$data['action'] = '';
		$result = $this->init($order_info);
		if(isset($result['data']) && $result['data'] != null){
			$data['action'] = $this->construct_url($order_info, $result['data']['token']);
		}
		return $this->load->view('extension/payment/safepay', $data);
	}

	protected function init($order_info) {
		$payment_safepay_mode = $this->config->get('payment_safepay_mode');
		if($payment_safepay_mode == 'sandbox') {
			$url = "https://sandbox.api.getsafepay.com/order/v1/init";
			$client = $this->config->get('payment_safepay_sandbox_apikey');
		} else {
			$url = "https://api.getsafepay.com/order/v1/init";
			$client = $this->config->get('payment_safepay_apikey');
		}
		
		$params = array(
			"client" => $client,
			"amount" =>  $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false),
			"currency" => $this->session->data['currency'],
			"environment" => $payment_safepay_mode				
		);

		$data_string = json_encode($params);
		
		$ch =  curl_init($url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)');
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$result = curl_exec($ch);

		if (curl_errno($ch)) { 
		   return false;
		}
		
		curl_close($ch);
		$result_array = json_decode($result,true);
		
		return $result_array;
	}
	
	protected function construct_url($order, $tracker="") {
		$payment_safepay_mode = $this->config->get('payment_safepay_mode');
		$baseURL = ($payment_safepay_mode == 'sandbox') ? $this->SANDBOX_CHECKOUT_URL : $this->PRODUCTION_CHECKOUT_URL;
		$params = array(
			"env" => $payment_safepay_mode,
			"beacon" => $tracker,
			"source" => 'opencart',
			"order_id" => $order['order_id'],
			"redirect_url" => $this->url->link('extension/payment/safepay/callback', '', true),
			"cancel_url" => $this->url->link('checkout/cart', '', true)
		);

		$baseURL = $baseURL.urldecode(http_build_query($params));

		return $baseURL;
	}	

	public function callback() {
		$this->load->language('extension/payment/safepay');
		$this->load->model('extension/payment/safepay');
		$data = $this->request->post;
		if((isset($data['tracker']) && !empty($data['tracker'])) && (isset($data['sig']) && !empty($data['sig']))) {
			$is_valid = $this->validate_signature($data['tracker'], $data['sig']);
			if($is_valid) {
				foreach ($data as $key => $value) {
					 $this->model_extension_payment_safepay->logInfo($data['order_id'], $key, $value);
				}
				$this->load->model('checkout/order');
				$this->model_checkout_order->addOrderHistory($data['order_id'], $this->config->get('payment_safepay_order_status_id'));
				$this->response->redirect($this->url->link('checkout/success'));
			} else {
				$this->session->data['error'] = $this->language->get('error_failed');
				$this->response->redirect($this->url->link('checkout/checkout', '', true));
			}
		} else {
			$this->session->data['error'] = $this->language->get('error_failed');
			$this->response->redirect($this->url->link('checkout/checkout', '', true));
		}
	}
	
	public function validate_signature($tracker, $signature) {
		$payment_safepay_mode = $this->config->get('payment_safepay_mode');
		if($payment_safepay_mode == 'sandbox') {
			$secret = $this->config->get('payment_safepay_sandbox_secretkey');
		} else {
			$secret = $this->config->get('payment_safepay_secretkey');
		}		
		
		$signature_2 = hash_hmac('sha256', $tracker, $secret);

		if ($signature_2 === $signature) {
			return true;
		}

		return false;
	}

}