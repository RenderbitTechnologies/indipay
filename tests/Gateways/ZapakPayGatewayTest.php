<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\ZapakPayGateway;
use Softon\Indipay\Tests\TestCase;

class ZapakPayGatewayTest extends TestCase
{
    protected function validParameters()
    {
        return [
            'orderId' => 'ORD1',
            'amount' => '10000',
            'currency' => 'INR',
            'buyerEmail' => 'asha@example.com',
        ];
    }

    public function test_endpoint_is_the_same_in_both_modes()
    {
        $this->assertSame('https://api.zaakpay.com/api/paymentTransact/V7', (new ZapakPayGateway())->getEndPoint());

        Config::set('indipay.testMode', false);

        $this->assertSame('https://api.zaakpay.com/api/paymentTransact/V7', (new ZapakPayGateway())->getEndPoint());
    }

    public function test_it_fills_default_parameters()
    {
        $this->assertSame([
            'merchantIdentifier' => 'ZP_MERCHANT',
            'returnUrl' => 'http://localhost/indipay/response',
        ], $this->getProtected(new ZapakPayGateway(), 'parameters'));
    }

    public function test_calculate_checksum_is_an_hmac_sha256()
    {
        $this->assertSame(
            hash_hmac('sha256', 'amount=1&', 'secret'),
            (new ZapakPayGateway())->calculateChecksum('secret', 'amount=1&')
        );
    }

    public function test_verify_checksum()
    {
        $gateway = new ZapakPayGateway();
        $checksum = hash_hmac('sha256', 'amount=1&', 'secret');

        $this->assertSame(1, $gateway->verifyChecksum($checksum, 'amount=1&', 'secret'));
        $this->assertSame(0, $gateway->verifyChecksum($checksum, 'amount=2&', 'secret'));
    }

    public function test_sanitized_param_strips_special_characters()
    {
        $this->assertSame(
            'Asha Rao 12 Main Rd.',
            (new ZapakPayGateway())->sanitizedParam('Asha, Rao #12 (Main) Rd.<>!$%^=+|\\:\'";~[]*&{}`')
        );
    }

    public function test_sanitized_url_keeps_url_characters()
    {
        $this->assertSame(
            'https://example.com/pay?a=1&b=2#top',
            (new ZapakPayGateway())->sanitizedURL('https://example.com/pay?a=1&b=2#top')
        );
        $this->assertSame('https://example.com/x', (new ZapakPayGateway())->sanitizedURL('https://example.com/(x)'));
    }

    public function test_get_all_params_uses_the_checksum_sequence()
    {
        $string = (new ZapakPayGateway())->getAllParams([
            'returnUrl' => 'http://localhost/indipay/response',
            'orderId' => 'ORD1',
            'amount' => '10000',
            'merchantIdentifier' => 'ZP_MERCHANT',
            'buyerEmail' => 'asha@example.com',
            'currency' => 'INR',
            'notInSequence' => 'ignored',
            'buyerFirstName' => '',
        ]);

        $this->assertSame(
            'amount=10000&buyerEmail=asha@example.com&currency=INR&merchantIdentifier=ZP_MERCHANT&orderId=ORD1&returnUrl=http://localhost/indipay/response&',
            $string
        );
    }

    public function test_get_all_params_sanitizes_values()
    {
        $this->assertSame(
            'buyerFirstName=Asha&',
            (new ZapakPayGateway())->getAllParams(['buyerFirstName' => 'A$sha!'])
        );
    }

    public function test_request_builds_the_checksum()
    {
        $gateway = (new ZapakPayGateway())->request($this->validParameters());

        $expected = hash_hmac(
            'sha256',
            'amount=10000&buyerEmail=asha@example.com&currency=INR&merchantIdentifier=ZP_MERCHANT&orderId=ORD1&returnUrl=http://localhost/indipay/response&',
            'ZP_SECRET'
        );

        $this->assertSame($expected, $this->getProtected($gateway, 'checksum'));
    }

    public function test_request_requires_a_valid_buyer_email()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('buyer email');

        (new ZapakPayGateway())->request(['buyerEmail' => 'nope'] + $this->validParameters());
    }

    public function test_request_requires_an_order_id()
    {
        $parameters = $this->validParameters();
        unset($parameters['orderId']);

        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('order id');

        (new ZapakPayGateway())->request($parameters);
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new ZapakPayGateway())->request($this->validParameters())->send();

        $this->assertSame('indipay::zapakpay', $view->name());

        $html = $view->render();
        $this->assertStringContainsString('action="https://api.zaakpay.com/api/paymentTransact/V7"', $html);
        $this->assertStringContainsString('name="orderId" value="ORD1"', $html);
        $this->assertStringContainsString('name="checksum" value="'.$view->getData()['checksum'].'"', $html);
    }

    public function test_get_all_response_params_uses_the_response_sequence()
    {
        $string = (new ZapakPayGateway())->getAllResponseParams([
            'responseDescription' => 'Transaction Completed Successfully',
            'orderId' => 'ORD1',
            'amount' => '10000',
            'responseCode' => '100',
            'checksum' => 'ignored',
        ]);

        $this->assertSame(
            'amount=10000&orderId=ORD1&responseCode=100&responseDescription=Transaction Completed Successfully&',
            $string
        );
    }

    public function test_response_with_a_valid_checksum_is_returned()
    {
        $fields = ['orderId' => 'ORD1', 'amount' => '10000', 'responseCode' => '100'];
        $fields['checksum'] = hash_hmac('sha256', 'amount=10000&orderId=ORD1&responseCode=100&', 'ZP_SECRET');

        $gateway = new ZapakPayGateway();

        $this->assertSame($fields, $gateway->response($this->makeRequest($fields)));
        $this->assertSame($fields, $gateway->response);
    }

    public function test_response_with_a_bad_checksum_is_rejected()
    {
        $fields = ['orderId' => 'ORD1', 'amount' => '10000', 'responseCode' => '100'];
        $fields['checksum'] = hash_hmac('sha256', 'amount=10000&orderId=ORD1&responseCode=100&', 'ZP_SECRET');
        $fields['amount'] = '1';

        $this->assertSame('Recieved Checksum Mismatch.', (new ZapakPayGateway())->response($this->makeRequest($fields)));
    }
}
