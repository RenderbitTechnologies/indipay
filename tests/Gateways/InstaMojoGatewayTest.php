<?php

namespace Softon\Indipay\Tests\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayGatewayException;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\InstaMojoGateway;
use Softon\Indipay\Tests\TestCase;

/**
 * The Instamojo API is faked with a Guzzle MockHandler injected into the gateway.
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

    private function clientAnswering(Response $response)
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
    }

    private function paymentRequest()
    {
        return \Illuminate\Http\Request::create('/indipay/response', 'GET', [
            'payment_request_id' => 'abc123',
            'payment_id' => 'def456',
        ]);
    }

    private $validOrder = ['purpose' => 'Order 1', 'amount' => '100'];

    public function test_request_stores_a_successful_reply_for_send()
    {
        $body = '{"success":true,"payment_request":{"longurl":"https://test.instamojo.com/@x/abc"}}';
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(201, [], $body)));

        $view = $gateway->request($this->validOrder)->send();

        $this->assertSame('https://test.instamojo.com/@x/abc', $view->getData()['longurl']);
    }

    public function test_request_throws_with_instamojos_message_when_success_is_false()
    {
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(200, [], '{"success":false,"message":"Invalid amount"}')));

        $this->expectException(IndipayGatewayException::class);
        $this->expectExceptionMessage('Invalid amount');

        $gateway->request($this->validOrder);
    }

    public function test_request_flattens_field_errors_from_a_4xx_reply()
    {
        $body = '{"success":false,"message":{"amount":["Ensure this value is greater than 9."]}}';
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(400, [], $body)));

        $this->expectException(IndipayGatewayException::class);
        $this->expectExceptionMessage('amount: Ensure this value is greater than 9.');

        $gateway->request($this->validOrder);
    }

    public function test_request_wraps_a_5xx_without_a_json_body()
    {
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(502, [], 'Bad Gateway')));

        $this->expectException(IndipayGatewayException::class);
        $this->expectExceptionMessage('502');

        $gateway->request($this->validOrder);
    }

    public function test_request_throws_when_the_body_is_not_json()
    {
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(200, [], '<html>oops</html>')));

        $this->expectException(IndipayGatewayException::class);
        $this->expectExceptionMessage('invalid response');

        $gateway->request($this->validOrder);
    }

    public function test_request_throws_when_a_successful_reply_has_no_payment_url()
    {
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(200, [], '{"success":true}')));

        $this->expectException(IndipayGatewayException::class);

        $gateway->request($this->validOrder);
    }

    public function test_response_returns_the_payment_when_successful()
    {
        $gateway = new InstaMojoGateway($this->clientAnswering(new Response(200, [], '{"success":true,"payment_request":{"id":"r1"}}')));

        $this->assertSame('r1', $gateway->response($this->paymentRequest())->payment_request->id);
    }

    public static function failedResponses()
    {
        return [
            'success false' => [new Response(200, [], '{"success":false}')],
            'not json' => [new Response(200, [], 'nope')],
            'json scalar' => [new Response(200, [], '"ok"')],
            'http error' => [new Response(404, [], '{"success":false}')],
        ];
    }

    #[DataProvider('failedResponses')]
    public function test_response_returns_false_when_the_payment_cannot_be_confirmed(Response $reply)
    {
        $gateway = new InstaMojoGateway($this->clientAnswering($reply));

        $this->assertFalse($gateway->response($this->paymentRequest()));
    }

    public static function unsafeIds()
    {
        return [
            'dot segments' => ['../../x', 'abc123'],
            'query string' => ['abc?x=1', 'abc123'],
            'fragment' => ['abc#', 'abc123'],
            'slash' => ['abc/def', 'abc123'],
            'payment id with dot segments' => ['abc123', '../x'],
            'empty request id' => ['', 'abc123'],
            'missing payment id' => ['abc123', null],
            'array input' => [['abc'], 'abc123'],
            'trailing newline' => ["abc\n", 'abc123'],
        ];
    }

    #[DataProvider('unsafeIds')]
    public function test_response_rejects_unsafe_ids_without_calling_the_api($paymentRequestId, $paymentId)
    {
        $request = \Illuminate\Http\Request::create('/indipay/response', 'GET', array_filter([
            'payment_request_id' => $paymentRequestId,
            'payment_id' => $paymentId,
        ], function ($value) {
            return $value !== null;
        }));

        $this->assertFalse((new InstaMojoGateway())->response($request));
    }

    public function test_response_reads_ids_from_the_given_request_not_the_facade()
    {
        $this->app['request']->query->set('payment_request_id', 'abc123');
        $this->app['request']->query->set('payment_id', 'def456');

        $request = \Illuminate\Http\Request::create('/indipay/response', 'GET', ['payment_request_id' => '../../x']);

        $this->assertFalse((new InstaMojoGateway())->response($request));
    }
}
