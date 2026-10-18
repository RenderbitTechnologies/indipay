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

    public function test_endpoints_follow_test_mode()
    {
        $this->assertSame('https://zaakstaging.zaakpay.com/api/paymentTransact/V13', (new ZapakPayGateway())->getEndPoint());

        Config::set('indipay.testMode', false);

        $this->assertSame('https://api.zaakpay.com/api/paymentTransact/V13', (new ZapakPayGateway())->getEndPoint());
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

        $this->assertTrue($gateway->verifyChecksum($checksum, 'amount=1&', 'secret'));
        $this->assertFalse($gateway->verifyChecksum($checksum, 'amount=2&', 'secret'));
    }

    public function test_verify_checksum_rejects_non_string_checksums()
    {
        $gateway = new ZapakPayGateway();

        $this->assertFalse($gateway->verifyChecksum(null, 'amount=1&', 'secret'));
        $this->assertFalse($gateway->verifyChecksum(0, 'amount=1&', 'secret'));
        $this->assertFalse($gateway->verifyChecksum(['x'], 'amount=1&', 'secret'));
        $this->assertFalse($gateway->verifyChecksum(true, 'amount=1&', 'secret'));
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

    public function test_get_all_params_signs_the_v13_options_in_alphabetical_position()
    {
        $string = (new ZapakPayGateway())->getAllParams([
            'paymentOptionTypes' => 'card',
            'orderId' => 'ORD1',
            'isPasskeySupported' => 'true',
            'isAutoRedirect' => 'true',
            'currency' => 'INR',
            'couRefId' => 'R1',
            'couAiId' => 'A1',
            'buyerState' => 'KA',
        ]);

        $this->assertSame(
            'buyerState=KA&couAiId=A1&couRefId=R1&currency=INR&isAutoRedirect=true&isPasskeySupported=true&orderId=ORD1&paymentOptionTypes=card&',
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

    public function test_get_all_params_includes_a_zero_value()
    {
        $this->assertSame('showMobile=0&', (new ZapakPayGateway())->getAllParams(['showMobile' => '0']));
    }

    public function test_posted_fields_are_the_signed_fields()
    {
        $gateway = (new ZapakPayGateway())->request($this->validParameters() + [
            'buyerAddress' => '12, MG Road',
            'buyerFirstName' => 'A$sha!',
            'showMobile' => '0',
        ]);

        $view = $gateway->send();
        $data = $view->getData();

        $this->assertSame('12 MG Road', $data['params']['buyerAddress']);
        $this->assertSame('Asha', $data['params']['buyerFirstName']);

        // Re-sign exactly what the view posts and compare with the posted checksum.
        $signed = $gateway->getAllParams($data['params']);
        $this->assertStringContainsString('buyerAddress=12 MG Road&', $signed);
        $this->assertStringContainsString('showMobile=0&', $signed);
        $this->assertSame(hash_hmac('sha256', $signed, 'ZP_SECRET'), $data['checksum']);

        $html = $view->render();
        $this->assertStringContainsString('name="buyerAddress" value="12 MG Road"', $html);
        $this->assertStringContainsString('name="showMobile" value="0"', $html);
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
        $this->assertStringContainsString('action="https://zaakstaging.zaakpay.com/api/paymentTransact/V13"', $html);
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

    public function test_response_sequence_includes_the_v13_fields()
    {
        $string = (new ZapakPayGateway())->getAllResponseParams([
            'pgTransTime' => '2026-10-18 10:00:00',
            'pgTransId' => 'PG1',
            'product1Description' => 'P1',
            'productDescription' => 'P0',
            'responseDescription' => 'ok',
            'orderId' => 'ORD1',
        ]);

        $this->assertSame(
            'orderId=ORD1&responseDescription=ok&productDescription=P0&product1Description=P1&pgTransId=PG1&pgTransTime=2026-10-18 10:00:00&',
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
