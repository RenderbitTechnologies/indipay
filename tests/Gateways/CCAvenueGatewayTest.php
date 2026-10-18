<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\CCAvenueGateway;
use Softon\Indipay\Tests\TestCase;

class CCAvenueGatewayTest extends TestCase
{
    protected function validParameters()
    {
        return ['order_id' => 'ORD-1', 'amount' => '100.00'];
    }

    public function test_it_uses_the_test_endpoint_in_test_mode()
    {
        $this->assertSame(
            'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction',
            (new CCAvenueGateway())->getEndPoint()
        );
    }

    public function test_it_uses_the_live_endpoint_outside_test_mode()
    {
        Config::set('indipay.testMode', false);

        $this->assertSame(
            'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction',
            (new CCAvenueGateway())->getEndPoint()
        );
    }

    public function test_it_fills_default_parameters_from_config()
    {
        $parameters = $this->getProtected(new CCAvenueGateway(), 'parameters');

        $this->assertSame('CCA_MERCHANT', $parameters['merchant_id']);
        $this->assertSame('INR', $parameters['currency']);
        $this->assertSame('EN', $parameters['language']);
        $this->assertSame('http://localhost/indipay/response', $parameters['redirect_url']);
        $this->assertSame('http://localhost/indipay/response', $parameters['cancel_url']);
    }

    public function test_encrypt_and_decrypt_round_trip()
    {
        $gateway = new CCAvenueGateway();
        $plain = 'merchant_id=1&order_id=ORD-1&amount=100.00&';

        $encrypted = $gateway->encrypt($plain, 'secret');

        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $encrypted);
        $this->assertSame(0, strlen($encrypted) % 32, 'Ciphertext should be whole AES blocks in hex');
        $this->assertSame($plain, $gateway->decrypt($encrypted, 'secret'));
    }

    public function test_encrypt_matches_the_ccavenue_algorithm()
    {
        $plain = 'order_id=ORD-1&';
        $iv = implode('', array_map('chr', range(0, 15)));
        $expected = bin2hex(openssl_encrypt($plain, 'AES-128-CBC', md5('secret', true), OPENSSL_RAW_DATA, $iv));

        $this->assertSame($expected, (new CCAvenueGateway())->encrypt($plain, 'secret'));
    }

    public function test_decrypt_with_the_wrong_key_fails()
    {
        $gateway = new CCAvenueGateway();
        $encrypted = $gateway->encrypt('order_id=ORD-1&amount=100&', 'right-key');

        $this->assertNotSame('order_id=ORD-1&amount=100&', $gateway->decrypt($encrypted, 'wrong-key'));
    }

    public function test_request_encrypts_the_merchant_data_with_the_working_key()
    {
        $gateway = (new CCAvenueGateway())->request($this->validParameters());

        $merchantData = $this->getProtected($gateway, 'merchantData');
        parse_str($merchantData, $fields);

        $this->assertSame('ORD-1', $fields['order_id']);
        $this->assertSame('100.00', $fields['amount']);
        $this->assertSame('CCA_MERCHANT', $fields['merchant_id']);
        $this->assertSame($merchantData, $gateway->decrypt($this->getProtected($gateway, 'encRequest'), 'CCA_WORKING_KEY'));
    }

    public function test_request_parameters_override_config_defaults()
    {
        $gateway = (new CCAvenueGateway())->request($this->validParameters() + ['currency' => 'USD']);

        $this->assertSame('USD', $this->getProtected($gateway, 'parameters')['currency']);
    }

    public function test_request_returns_the_gateway()
    {
        $gateway = new CCAvenueGateway();

        $this->assertSame($gateway, $gateway->request($this->validParameters()));
    }

    public function test_request_requires_an_order_id()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('order id');

        (new CCAvenueGateway())->request(['amount' => '100']);
    }

    public function test_request_requires_a_numeric_amount()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('amount');

        (new CCAvenueGateway())->request(['order_id' => 'ORD-1', 'amount' => 'ten']);
    }

    public function test_request_requires_valid_urls()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('redirect url');

        (new CCAvenueGateway())->request($this->validParameters() + ['redirect_url' => 'not a url']);
    }

    public function test_request_rejects_ampersand_in_a_value()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('billing_name');

        (new CCAvenueGateway())->request($this->validParameters() + ['billing_name' => 'Bob&amount=1']);
    }

    public function test_request_rejects_ampersand_or_equals_in_a_name()
    {
        $this->expectException(IndipayParametersMissingException::class);

        (new CCAvenueGateway())->request($this->validParameters() + ['a=b' => 'c']);
    }

    public function test_request_allows_equals_in_a_value()
    {
        $gateway = (new CCAvenueGateway())->request($this->validParameters() + ['redirect_url' => 'https://merchant.test/callback?tenant=42']);

        $this->assertStringContainsString('redirect_url=https://merchant.test/callback?tenant=42&', $this->getProtected($gateway, 'merchantData'));
    }

    public function test_request_allows_spaces_and_punctuation_in_values()
    {
        $gateway = (new CCAvenueGateway())->request($this->validParameters() + ['billing_name' => "Bob O'Neil, Jr."]);

        $this->assertStringContainsString("billing_name=Bob O'Neil, Jr.&", $this->getProtected($gateway, 'merchantData'));
    }

    public function test_response_rejects_duplicate_keys()
    {
        $gateway = new CCAvenueGateway();
        $encrypted = $gateway->encrypt('order_id=1&order_status=Failure&billing_name=x&order_status=Success', 'CCA_WORKING_KEY');

        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('order_status');

        $gateway->response((object) ['encResp' => $encrypted]);
    }

    public function test_response_rejects_duplicate_keys_that_parse_str_normalises_together()
    {
        $gateway = new CCAvenueGateway();

        foreach (['order%5Fstatus', 'order.status'] as $alias) {
            $encrypted = $gateway->encrypt('order_status=Failure&'.$alias.'=Success', 'CCA_WORKING_KEY');

            try {
                $gateway->response((object) ['encResp' => $encrypted]);
                $this->fail("Duplicate via '$alias' was accepted.");
            } catch (IndipayParametersMissingException $e) {
                $this->assertStringContainsString('order_status', $e->getMessage());
            }
        }
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new CCAvenueGateway())->request($this->validParameters())->send();
        $data = $view->getData();

        $this->assertSame('indipay::ccavenue', $view->name());
        $this->assertSame('CCA_ACCESS', $data['accessCode']);
        $this->assertSame('https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction', $data['endPoint']);

        $html = $view->render();
        $this->assertStringContainsString('value="'.$data['encRequest'].'"', $html);
        $this->assertStringContainsString('name=access_code value="CCA_ACCESS"', $html);
    }

    public function test_response_decrypts_the_encrypted_response()
    {
        $gateway = new CCAvenueGateway();
        $encResp = $gateway->encrypt('order_id=ORD-1&order_status=Success&amount=100.00', 'CCA_WORKING_KEY');

        $response = $gateway->response($this->makeRequest(['encResp' => $encResp]));

        $this->assertSame([
            'order_id' => 'ORD-1',
            'order_status' => 'Success',
            'amount' => '100.00',
        ], $response);
    }

    public static function malformedEncRespProvider()
    {
        return [
            'missing' => [[]],
            'empty' => [['encResp' => '']],
            'array' => [['encResp' => ['aa']]],
            'not hex' => [['encResp' => 'zz']],
            'odd length' => [['encResp' => 'abc']],
            'not a whole block' => [['encResp' => 'aabbcc']],
        ];
    }

    /**
     * @dataProvider malformedEncRespProvider
     */
    public function test_response_returns_an_error_for_a_malformed_enc_resp($input)
    {
        $this->assertSame('Decryption Error', (new CCAvenueGateway())->response($this->makeRequest($input)));
    }

    public function test_response_returns_an_error_when_encrypted_with_another_key()
    {
        $gateway = new CCAvenueGateway();
        $encResp = $gateway->encrypt('order_id=ORD-1&order_status=Success', 'some-other-key');

        $this->assertSame('Decryption Error', $gateway->response($this->makeRequest(['encResp' => $encResp])));
    }
}
