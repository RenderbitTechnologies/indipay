<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\InstaMojoGateway;
use Softon\Indipay\Tests\TestCase;

/**
 * request() and response() call the Instamojo API through a Guzzle client the
 * gateway builds itself, so these tests cover everything up to that call.
 */
class InstaMojoGatewayTest extends TestCase
{
    public function test_endpoint_follows_test_mode()
    {
        $this->assertSame('https://test.instamojo.com/api/1.1/', (new InstaMojoGateway())->getEndPoint());
        $this->assertSame(
            'https://test.instamojo.com/api/1.1/payment-requests/',
            (new InstaMojoGateway())->getEndPoint('payment-requests/')
        );

        Config::set('indipay.testMode', false);

        $this->assertSame('https://www.instamojo.com/api/1.1/', (new InstaMojoGateway())->getEndPoint());
    }

    public function test_it_reads_credentials_and_redirect_url_from_config()
    {
        $gateway = new InstaMojoGateway();

        $this->assertSame('IM_API_KEY', $this->getProtected($gateway, 'api_key'));
        $this->assertSame('IM_AUTH_TOKEN', $this->getProtected($gateway, 'auth_token'));
        $this->assertSame('http://localhost/indipay/response', $this->getProtected($gateway, 'parameters')['redirect_url']);
    }

    public static function invalidParameters()
    {
        return [
            'missing purpose' => [['amount' => '100'], 'purpose'],
            'purpose too long' => [['purpose' => str_repeat('a', 31), 'amount' => '100'], 'purpose'],
            'missing amount' => [['purpose' => 'Order'], 'amount'],
            'amount below minimum' => [['purpose' => 'Order', 'amount' => '8'], 'amount'],
            'amount above maximum' => [['purpose' => 'Order', 'amount' => '200001'], 'amount'],
            'bad email' => [['purpose' => 'Order', 'amount' => '100', 'email' => 'nope'], 'email'],
            'short phone' => [['purpose' => 'Order', 'amount' => '100', 'phone' => '12345'], 'phone'],
            'long buyer name' => [['purpose' => 'Order', 'amount' => '100', 'buyer_name' => str_repeat('a', 101)], 'buyer name'],
        ];
    }

    #[DataProvider('invalidParameters')]
    public function test_request_validates_parameters_before_calling_the_api(array $parameters, $field)
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage($field);

        (new InstaMojoGateway())->request($parameters);
    }

    public function test_check_parameters_accepts_a_valid_order()
    {
        (new InstaMojoGateway())->checkParameters([
            'purpose' => 'Order 1',
            'amount' => '100',
            'buyer_name' => 'Asha Rao',
            'email' => 'asha@example.com',
            'phone' => '9999999999',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_send_redirects_to_the_payment_request_url()
    {
        $gateway = new InstaMojoGateway();
        $gateway->response = json_decode('{"success":true,"payment_request":{"longurl":"https://test.instamojo.com/@x/abc"}}');

        $view = $gateway->send();

        $this->assertSame('indipay::instamojo', $view->name());
        $this->assertSame('https://test.instamojo.com/@x/abc', $view->getData()['longurl']);
        $this->assertStringContainsString('action="https://test.instamojo.com/@x/abc"', $view->render());
    }
}
