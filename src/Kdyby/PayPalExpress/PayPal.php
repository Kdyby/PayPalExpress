<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\PayPalExpress;
use Kdyby\Curl;
use Nette;



/**
 * @author Martin Maly - http://www.php-suit.com
 * @author Filip Procházka <filip@prochazka.su>
 * @see  http://www.php-suit.com/paypal
 *
 * @method onRequest(array $data)
 * @method onError(\Kdyby\Curl\CurlException $e, array $info)
 * @method onSuccess(array $result)
 */
class PayPal extends Nette\Object
{

	const API_VERSION = 95.0;

	const PP_HOST = 'https://api-3t.paypal.com/nvp';
	const PP_GATE = 'https://www.paypal.com/cgi-bin/webscr?';
	const PP_HOST_SANDBOX = 'https://api-3t.sandbox.paypal.com/nvp';
	const PP_GATE_SANDBOX = 'https://www.sandbox.paypal.com/cgi-bin/webscr?';

	/**
	 * @var array of function(array $data)
	 */
	public $onRequest = array();

	/**
	 * @var array of function(Curl\CurlException $e, array $info)
	 */
	public $onError = array();

	/**
	 * @var array of function(array $result)
	 */
	public $onSuccess = array();

	/**
	 * @var string
	 */
	private $host = self::PP_HOST_SANDBOX;

	/**
	 * @var string
	 */
	private $gate = self::PP_GATE_SANDBOX;

	/**
	 * @var string
	 */
	private $account;

	/**
	 * @var string
	 */
	private $username;

	/**
	 * @var string
	 */
	private $password;

	/**
	 * @var string
	 */
	private $signature;

	/**
	 * @var bool
	 */
	private $needAddress = FALSE;
	
	/**
	 * @var bool
	 */
	private $confirmShipping = FALSE;
	
	/**
	 * @var string
	 */
	private $currency = 'CZK';

	/**
	 * @var string
	 */
	private $returnUrl;

	/**
	 * @var string
	 */
	private $cancelUrl;

	/**
	 * @var Nette\Http\Request
	 */
	private $httpRequest;

	/**
	 * @var Curl\CurlSender
	 */
	private $curlSender;



	/**
	 * Obtain credentials at https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_NVPAPIBasics
	 *
	 * @param array $credentials
	 * @param Nette\Http\Request $httpRequest
	 * @param Curl\CurlSender $curlSender
	 */
	public function __construct(array $credentials, Nette\Http\Request $httpRequest, Curl\CurlSender $curlSender = NULL)
	{
		$this->account = $credentials['account'];
		$this->username = $credentials['username'];
		$this->password = $credentials['password'];
		$this->signature = $credentials['signature'];
		$this->httpRequest = $httpRequest;
		$this->curlSender = $curlSender ?: new Curl\CurlSender();
	}



	/**
	 * 3-letter currency code (USD, GBP, CZK etc.)
	 *
	 * @param string $currency
	 */
	public function setCurrency($currency)
	{
		$this->currency = $currency;
	}



	/**
	 * If paypal response has contain the address of the customer
	 * @param bool $needAddress
	 */
	public function setNeedAddress($needAddress) 
	{
		$this->needAddress = (bool) $needAddress;
	}



	/**
	 * Address of customer must be verified
	 * @param bool $confirmShipping
	 */
	public function setConfirmShipping($confirmShipping) 
	{
		$this->confirmShipping = (bool) $confirmShipping;
	}



	/**
	 */
	public function disableSandbox()
	{
		$this->host = self::PP_HOST;
		$this->gate = self::PP_GATE;
	}



	/**
	 * @param string $returnUrl
	 * @param string $cancelUrl
	 */
	public function setReturnAddress($returnUrl, $cancelUrl = NULL)
	{
		$this->returnUrl = $returnUrl;
		$this->cancelUrl = $cancelUrl ?: $returnUrl;
	}



	/**
	 * Main payment function
	 *
	 * @param Cart $cart
	 * @throws CheckoutRequestFailedException
	 * @return RedirectResponse
	 */
	public function doExpressCheckout(Cart $cart)
	{
		$data = array(
			'METHOD' => 'SetExpressCheckout',
			'RETURNURL' => (string)$this->returnUrl,
			'CANCELURL' => (string)$this->cancelUrl,
			'REQCONFIRMSHIPPING' => $this->confirmShipping ? "1" : "0",
			'NOSHIPPING' => $cart->shipping || $this->needAddress ? "0" : "1",
			'ALLOWNOTE' => "1",
		) + $cart->serialize($this->account, $this->currency, '0');

		$return = $this->process($data);
		if ($return['ACK'] == 'Success') {
			return new RedirectResponse($return, $this->gate);
		}

		throw new CheckoutRequestFailedException($return, $data);
	}



	/**
	 * @param string $token
	 * @return Response
	 */
	public function getCheckoutDetails($token)
	{
		return new Response($this->process(array(
			'TOKEN' => $token,
			'METHOD' => 'GetExpressCheckoutDetails'
		)));
	}



	/**
	 * @throws PaymentFailedException
	 * @return Response
	 */
	public function doPayment()
	{
		$token = $this->httpRequest->getQuery('token');
		$details = $this->getCheckoutDetails($token);

		if ($details->isPaymentCompleted()) {
			return $details;
		}

		$data = array(
			'METHOD' => 'DoExpressCheckoutPayment',
			'PAYERID' => $details->getData('PAYERID'),
			'TOKEN' => $token,
		);
		foreach ($details->getCarts() as $cart) {
			$data += $cart->serialize($this->account, $this->currency, '0');
		}

		$return = $this->process($data) + array('details' => $details);

		if ($return['ACK'] == 'Success') {
			return new Response($return);
		}

		throw new PaymentFailedException($return, $data);
	}
	
	/**
	 * 
	 * @return string $gate
	 */
	public function getGate() {
		return $this->gate;
	}


	/**
	 * @param array $data
	 * @throws CommunicationFailedException
	 * @return array
	 */
	private function process($data)
	{
		$this->onRequest($data);

		$data = array(
			'USER' => $this->username,
			'PWD' => $this->password,
			'SIGNATURE' => $this->signature,
			'VERSION' => self::API_VERSION,
		) + $data;

		$request = new Curl\Request($this->host, $data);
		$request->setSender($this->curlSender);
		$request->options['sslversion'] = CURL_SSLVERSION_TLSv1;
		$request->options['verbose'] = TRUE;

		if (strpos($request->getUrl()->getHost(), '.sandbox.') !== FALSE) {
			$request->setCertificationVerify(FALSE);
			$request->options['ssl_verifyHost'] = FALSE;
		}

		try {
			$response = $request->post(http_build_query($data));
			$resultData = self::parseNvp($response->getResponse());
			$this->onSuccess($resultData, $response->getInfo());
			return $resultData;

		} catch (Curl\FailedRequestException $e) {
			$this->onError($e, $e->getInfo());
			throw new CommunicationFailedException($e->getMessage(), 0, $e);

		} catch (Curl\CurlException $e) {
			$this->onError($e, $e->getResponse() ? $e->getResponse()->info : array());
			throw new CommunicationFailedException($e->getMessage(), 0, $e);
		}
	}



	/**
	 * @param string $responseBody
	 * @return array
	 */
	public static function parseNvp($responseBody)
	{
		$a = explode("&", $responseBody);
		$out = array();
		foreach ($a as $v) {
			$k = strpos($v, '=');
			if ($k) {
				$key = trim(substr($v, 0, $k));
				$value = trim(substr($v, $k + 1));
				if (!$key) {
					continue;
				}
				$out[$key] = urldecode($value);
			} else {
				$out[] = $v;
			}
		}
		return $out;
	}

}
