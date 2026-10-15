<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\PaytmGateway;
use Softon\Indipay\Tests\TestCase;

/**
 * verify() calls the Paytm status API over cURL, so only its guard clause is covered.
 */
class PaytmGatewayTest extends TestCase
{
    const KEY = 'abcdefgh12345678';

    protected function validParameters()
    {
        return ['ORDER_ID' => 'ORD1', 'CUST_ID' => 'CUST1', 'TXN_AMOUNT' => '100.00'];
    }

    public function test_endpoints_follow_test_mode()
    {
        $gateway = new PaytmGateway();
        $this->assertSame('https://securegw-stage.paytm.in/order/process', $gateway->getEndPoint());
        $this->assertSame('https://securegw-stage.paytm.in/order/status', $gateway->getStatusEndPoint());

        Config::set('indipay.testMode', false);

        $gateway = new PaytmGateway();
        $this->assertSame('https://securegw.paytm.in/order/process', $gateway->getEndPoint());
        $this->assertSame('https://securegw.paytm.in/order/status', $gateway->getStatusEndPoint());
    }

    public function test_it_fills_default_parameters_from_config()
    {
        $this->assertSame([
            'MID' => 'PAYTM_MID',
            'CHANNEL_ID' => 'WEB',
            'CALLBACK_URL' => 'http://localhost/indipay/response',
            'WEBSITE' => 'WEBSTAGING',
            'INDUSTRY_TYPE_ID' => 'Retail',
        ], $this->getProtected(new PaytmGateway(), 'parameters'));
    }

    public function test_encrypt_and_decrypt_round_trip()
    {
        $gateway = new PaytmGateway();

        $encrypted = $gateway->encrypt_e('hello world', self::KEY);

        $this->assertNotSame('hello world', $encrypted);
        $this->assertSame('hello world', $gateway->decrypt_e($encrypted, self::KEY));
    }

    public function test_generate_salt_returns_the_requested_length()
    {
        $salt = (new PaytmGateway())->generateSalt_e(4);

        $this->assertSame(4, strlen($salt));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{4}$/', $salt);
    }

    public function test_check_string_blanks_the_null_literal()
    {
        $gateway = new PaytmGateway();

        $this->assertSame('', $gateway->checkString_e('null'));
        $this->assertSame('value', $gateway->checkString_e('value'));
    }

    public function test_array_to_string_skips_refund_and_pipe_values()
    {
        $string = (new PaytmGateway())->getArray2Str([
            'A' => 'one',
            'B' => 'REFUND',
            'C' => 'has|pipe',
            'D' => 'null',
            'E' => 'two',
        ]);

        $this->assertSame('one||two', $string);
    }

    public function test_array_to_string_for_verify_keeps_every_value()
    {
        $string = (new PaytmGateway())->getArray2StrForVerify(['A' => 'one', 'B' => 'REFUND', 'C' => 'null']);

        $this->assertSame('one|REFUND|', $string);
    }

    public function test_refund_array_to_string_only_skips_pipe_values()
    {
        $string = (new PaytmGateway())->getRefundArray2Str(['A' => 'one', 'B' => 'REFUND', 'C' => 'a|b']);

        $this->assertSame('one|REFUND', $string);
    }

    public function test_remove_checksum_param()
    {
        $gateway = new PaytmGateway();

        $this->assertSame(['A' => '1'], $gateway->removeCheckSumParam(['A' => '1', 'CHECKSUMHASH' => 'x']));
        $this->assertSame(['A' => '1'], $gateway->removeCheckSumParam(['A' => '1']));
    }

    public function test_checksum_from_array_verifies()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1', 'MID' => 'PAYTM_MID', 'TXN_AMOUNT' => '100.00'];

        $checksum = $gateway->getChecksumFromArray($params, self::KEY);

        $this->assertSame('TRUE', $gateway->verifychecksum_e($params, self::KEY, $checksum));
        $this->assertSame('TRUE', $gateway->verifychecksum_e($params + ['CHECKSUMHASH' => $checksum], self::KEY, $checksum));
    }

    public function test_checksum_is_salted()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1'];

        $this->assertNotSame(
            $gateway->getChecksumFromArray($params, self::KEY),
            $gateway->getChecksumFromArray($params, self::KEY)
        );
    }

    public function test_checksum_fails_for_tampered_data()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1', 'TXN_AMOUNT' => '100.00'];

        $checksum = $gateway->getChecksumFromArray($params, self::KEY);

        $this->assertSame('FALSE', $gateway->verifychecksum_e(['TXN_AMOUNT' => '1.00'] + $params, self::KEY, $checksum));
    }

    public function test_checksum_fails_for_the_wrong_key()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1'];

        $checksum = $gateway->getChecksumFromArray($params, self::KEY);

        $this->assertSame('FALSE', $gateway->verifychecksum_e($params, 'zzzzzzzz87654321', $checksum));
    }

    public function test_checksum_from_string_verifies()
    {
        $gateway = new PaytmGateway();

        $checksum = $gateway->getChecksumFromString('a|b|c', self::KEY);

        $this->assertSame('TRUE', $gateway->verifychecksum_eFromStr('a|b|c', self::KEY, $checksum));
        $this->assertSame('FALSE', $gateway->verifychecksum_eFromStr('a|b|d', self::KEY, $checksum));
    }

    public function test_refund_checksum_verifies_against_the_same_string()
    {
        $gateway = new PaytmGateway();
        $params = ['B' => 'two', 'A' => 'one'];

        $checksum = $gateway->getRefundChecksumFromArray($params, self::KEY);

        $this->assertSame('TRUE', $gateway->verifychecksum_eFromStr('one|two', self::KEY, $checksum));
    }

    public function test_request_adds_a_valid_checksum()
    {
        $gateway = (new PaytmGateway())->request($this->validParameters());
        $parameters = $this->getProtected($gateway, 'parameters');

        $this->assertSame($this->getProtected($gateway, 'checksum'), $parameters['CHECKSUMHASH']);
        $this->assertSame('TRUE', $gateway->verifychecksum_e($parameters, self::KEY, $parameters['CHECKSUMHASH']));
    }

    public function test_request_requires_order_details()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('c u s t  i d');

        (new PaytmGateway())->request(['ORDER_ID' => 'ORD1', 'TXN_AMOUNT' => '100.00']);
    }

    public function test_request_requires_a_numeric_amount()
    {
        $this->expectException(IndipayParametersMissingException::class);

        (new PaytmGateway())->request(['TXN_AMOUNT' => 'abc'] + $this->validParameters());
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new PaytmGateway())->request($this->validParameters())->send();

        $this->assertSame('indipay::paytm', $view->name());

        $html = $view->render();
        $this->assertStringContainsString('action="https://securegw-stage.paytm.in/order/process"', $html);
        $this->assertStringContainsString('name="ORDER_ID" value="ORD1"', $html);
        $this->assertStringContainsString('name="CHECKSUMHASH" value="'.e($view->getData()['checksum']).'"', $html);
    }

    protected function signedResponse(array $fields)
    {
        $fields['CHECKSUMHASH'] = (new PaytmGateway())->getChecksumFromArray($fields, self::KEY);

        return $fields;
    }

    public function test_response_is_successful_with_a_valid_checksum_and_success_status()
    {
        $fields = $this->signedResponse(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_SUCCESS']);

        $response = (new PaytmGateway())->response($this->makeRequest($fields));

        $this->assertSame('success', $response['status']);
        $this->assertSame('ORD1', $response['ORDERID']);
    }

    public function test_response_fails_when_the_transaction_failed()
    {
        $fields = $this->signedResponse(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_FAILURE']);

        $this->assertSame('failure', (new PaytmGateway())->response($this->makeRequest($fields))['status']);
    }

    public function test_response_fails_when_the_checksum_does_not_match()
    {
        $fields = $this->signedResponse(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_SUCCESS']);
        $fields['TXNAMOUNT'] = '1.00';

        $this->assertSame('failure', (new PaytmGateway())->response($this->makeRequest($fields))['status']);
    }

    public function test_verify_without_an_order_id_returns_false()
    {
        $this->assertFalse((new PaytmGateway())->verify([]));
    }
}
