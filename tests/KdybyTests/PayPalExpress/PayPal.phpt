<?php

/**
 * Test: Kdyby\PayPalExpress\PayPal.
 *
 * @testCase Kdyby\PayPalExpress\PayPalTest
 * @author Filip Procházka <filip@prochazka.su>
 * @package Kdyby\PayPalExpress
 */

namespace KdybyTests\PayPalExpress;

use Kdyby\Curl\CurlException;
use Kdyby\Curl\FailedRequestException;
use Kdyby\PayPalExpress\Cart;
use Kdyby\PayPalExpress\DI\PayPalExtension;
use Kdyby\PayPalExpress\PayPal;
use Nette;
use Nette\Configurator;
use Tester;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class PayPalTest extends Tester\TestCase
{

	/**
	 * @var PayPal
	 */
	protected $paypal;



	public function setUp()
	{
		$config = new Configurator();
		$config->setTempDirectory(TEMP_DIR);
		$config->addParameters(array('container' => array('class' => 'SystemContainer_' . md5(TEMP_DIR))));
		PayPalExtension::register($config);
		$config->addConfig(__DIR__ . '/paypal.config.neon');

		$container = $config->createContainer();
		$this->paypal = $container->getByType('Kdyby\PayPalExpress\PayPal');
	}



	public function testFunctional()
	{
		$cart = new Cart();
		$cart->currency = 'USD';
		$cart->addItem(10, "Something funny");

		$this->paypal->setReturnAddress('http://kdyby.org/?paypal-success', 'http://kdyby.org/?paypal-failure');

		$response = $this->paypal->doExpressCheckout($cart);
		Assert::match('EC-%a%', $response->getToken());
		Assert::equal('Success', $response->getStatus());
		Assert::match('%a%', $response->getCorrelationId());
		Assert::match('https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&useraction=commit&token=EC-%a%', (string) $response->getUrl());

		$details = $this->paypal->getCheckoutDetails($response->getToken());
		Assert::same('PaymentActionNotInitiated', $details->getData('CHECKOUTSTATUS'));
		Assert::same('USD', $details->getData('CURRENCYCODE'));
		Assert::same('10.00', $details->getData('ITEMAMT'));
		Assert::same('12.10', $details->getData('AMT'));
		Assert::same('2.10', $details->getData('TAXAMT'));

		$cart = $details->getCart();
		Assert::false($cart->isEmpty());
	}



	/**
	 * @param $name
	 * @param array $args
	 * @throws \Exception
	 */
	public function runTest($name, array $args = array())
	{
		try {
			parent::runTest($name, $args);

		} catch (\Exception $e) {
			$current = $e;

			do {
				if ($current instanceof FailedRequestException && ($info = $current->getInfo())) {
					echo "Curl debugging info:\n";
					Tester\Dumper::toLine($info);

				} elseif ($current instanceof CurlException && ($response = $current->getResponse())) {
					echo "Curl debugging info:\n";
					Tester\Dumper::toLine(array('info' => $response->getInfo(), 'header' => $response->getHeaders()));
				}

			} while ($current = $current->getPrevious());

			throw $e;
		}
	}

}

\run(new PayPalTest());
