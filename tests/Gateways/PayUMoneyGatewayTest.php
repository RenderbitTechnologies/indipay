<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\PayUMoneyGateway;
use Softon\Indipay\Tests\TestCase;

class PayUMoneyGatewayTest extends TestCase
{
    protected function validParameters()
    {
        return [
            'txnid' => 'TXN1',
            'amount' => '100.00',
            'productinfo' => 'Widget',
            'firstname' => 'Asha',
            'email' => 'asha@example.com',
            'phone' => '9999999999',
        ];
    }

    /**
     * Hash PayU sends back: sha512(salt|status||||||udf5|...|udf1|email|firstname|productinfo|amount|txnid|key).
     */
    protected function responseHash(array $fields)
    {
        $udfs = [];
        foreach (['udf5', 'udf4', 'udf3', 'udf2', 'udf1'] as $udf) {
            $udfs[] = isset($fields[$udf]) ? $fields[$udf] : '';
        }

        $string = implode('|', array_merge(
            ['PAYU_SALT', $fields['status'], '', '', '', '', ''],
            $udfs,
            [$fields['email'], $fields['firstname'], $fields['productinfo'], $fields['amount'], $fields['txnid'], $fields['key']]
        ));

        return hash('sha512', $string);
    }

    protected function responseFields()
    {
        return [
            'status' => 'success',
            'email' => 'asha@example.com',
            'firstname' => 'Asha',
            'productinfo' => 'Widget',
            'amount' => '100.00',
            'txnid' => 'TXN1',
            'key' => 'PAYU_KEY',
            'udf1' => 'note',
            'udf2' => '',
            'udf3' => '',
            'udf4' => '',
            'udf5' => '',
        ];
    }

    public function test_endpoint_follows_test_mode()
    {
        $this->assertSame('https://test.payu.in/_payment', (new PayUMoneyGateway())->getEndPoint());

        Config::set('indipay.testMode', false);

        $this->assertSame('https://secure.payu.in/_payment', (new PayUMoneyGateway())->getEndPoint());
    }

    public function test_it_fills_default_parameters()
    {
        $parameters = $this->getProtected(new PayUMoneyGateway(), 'parameters');

        $this->assertSame('PAYU_KEY', $parameters['key']);
        $this->assertSame('http://localhost/indipay/response', $parameters['surl']);
        $this->assertSame('http://localhost/indipay/response', $parameters['furl']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $parameters['txnid']);
    }

    public function test_request_builds_the_payment_hash()
    {
        $gateway = (new PayUMoneyGateway())->request($this->validParameters());

        $expected = hash('sha512', 'PAYU_KEY|TXN1|100.00|Widget|Asha|asha@example.com|||||||||||PAYU_SALT');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    public function test_request_hash_includes_user_defined_fields()
    {
        $gateway = (new PayUMoneyGateway())->request($this->validParameters() + ['udf1' => 'a', 'udf3' => 'c']);

        $expected = hash('sha512', 'PAYU_KEY|TXN1|100.00|Widget|Asha|asha@example.com|a||c||||||||PAYU_SALT');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    public function test_request_requires_customer_details()
    {
        $parameters = $this->validParameters();
        unset($parameters['firstname']);

        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('firstname');

        (new PayUMoneyGateway())->request($parameters);
    }

    public function test_request_requires_a_numeric_amount()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('amount');

        (new PayUMoneyGateway())->request(['amount' => 'lots'] + $this->validParameters());
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new PayUMoneyGateway())->request($this->validParameters())->send();

        $this->assertSame('indipay::payumoney', $view->name());

        $html = $view->render();
        $this->assertStringContainsString('action="https://test.payu.in/_payment"', $html);
        $this->assertStringContainsString('name="hash" value="'.$view->getData()['hash'].'"', $html);
        $this->assertStringContainsString('name="txnid" value="TXN1"', $html);
        $this->assertStringContainsString('name="udf1" value=""', $html);
    }

    public function test_response_with_a_valid_hash_is_returned()
    {
        $fields = $this->responseFields();
        $fields['hash'] = $this->responseHash($fields);

        $this->assertSame($fields, (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_a_tampered_amount_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['hash'] = $this->responseHash($fields);
        $fields['amount'] = '1.00';

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_a_tampered_status_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['status'] = 'failure';
        $fields['hash'] = $this->responseHash($fields);
        $fields['status'] = 'success';

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public static function missingCallbackFields()
    {
        return [
            'hash' => [['hash']],
            'status' => [['status']],
            'every field' => [['status', 'email', 'firstname', 'productinfo', 'amount', 'txnid', 'key', 'udf1', 'hash']],
        ];
    }

    #[DataProvider('missingCallbackFields')]
    public function test_response_with_missing_fields_is_rejected(array $missing)
    {
        $fields = $this->responseFields();
        $fields['hash'] = $this->responseHash($fields);
        $fields = array_diff_key($fields, array_flip($missing));

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_an_array_hash_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['hash'] = ['x'];

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_an_empty_signed_field_is_returned()
    {
        $fields = $this->responseFields();
        $fields['udf1'] = '';
        $fields['hash'] = $this->responseHash($fields);

        $this->assertSame($fields, (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    // By default a signed field that is empty may be left out of the callback
    // and the hash still matches (see issue #9).
    public function test_response_with_an_empty_signed_field_left_out_is_accepted_by_default()
    {
        $fields = $this->emptyFieldLeftOut();

        $response = (new PayUMoneyGateway())->response($this->makeRequest($fields));

        $this->assertSame($fields, $response);
        $this->assertArrayNotHasKey('productinfo', $response);
    }

    public function test_response_with_an_empty_signed_field_left_out_is_rejected_in_strict_mode()
    {
        Config::set('indipay.payumoney.strictCallbackFields', true);

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($this->emptyFieldLeftOut())));
    }

    public function test_strict_mode_accepts_a_response_with_an_empty_signed_field()
    {
        Config::set('indipay.payumoney.strictCallbackFields', true);

        $fields = $this->responseFields();
        $fields['productinfo'] = '';
        $fields['hash'] = $this->responseHash($fields);

        $this->assertSame($fields, (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public function test_strict_mode_rejects_a_response_with_a_signed_field_left_out()
    {
        Config::set('indipay.payumoney.strictCallbackFields', true);

        $fields = $this->responseFields();
        $fields['hash'] = $this->responseHash($fields);
        unset($fields['productinfo']);

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    public function test_strict_mode_accepts_a_response_with_unused_udf_fields_left_out()
    {
        Config::set('indipay.payumoney.strictCallbackFields', true);

        $signed = $this->responseFields();
        $fields = array_diff_key($signed, array_flip(['udf1', 'udf2', 'udf3', 'udf4', 'udf5']));
        $fields['hash'] = $this->responseHash(array_merge($fields, ['udf1' => '']));

        $this->assertSame($fields, (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }

    protected function emptyFieldLeftOut()
    {
        $signed = $this->responseFields();
        $signed['productinfo'] = '';
        $fields = $signed;
        $fields['hash'] = $this->responseHash($signed);
        unset($fields['productinfo']);

        return $fields;
    }

    // Leaving out a field that carried a value still fails.
    public function test_response_with_a_non_empty_signed_field_left_out_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['hash'] = $this->responseHash($fields);
        unset($fields['udf1']);

        $this->assertSame('Hash Mismatch Error', (new PayUMoneyGateway())->response($this->makeRequest($fields)));
    }
}
