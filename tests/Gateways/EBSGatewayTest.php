<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\EBSGateway;
use Softon\Indipay\Tests\TestCase;

class EBSGatewayTest extends TestCase
{
    protected function validParameters()
    {
        return [
            'reference_no' => 'REF1',
            'amount' => '100.00',
            'description' => 'Test order',
            'name' => 'Asha Rao',
            'address' => '1 Main Road',
            'city' => 'Mumbai',
            'country' => 'IND',
            'postal_code' => '400001',
            'phone' => '9999999999',
            'email' => 'asha@example.com',
        ];
    }

    public function test_endpoint_is_the_same_in_both_modes()
    {
        $this->assertSame('https://secure.ebs.in/pg/ma/payment/request', (new EBSGateway())->getEndPoint());

        Config::set('indipay.testMode', false);

        $this->assertSame('https://secure.ebs.in/pg/ma/payment/request', (new EBSGateway())->getEndPoint());
    }

    public function test_mode_follows_test_mode_config()
    {
        $this->assertSame('TEST', $this->getProtected(new EBSGateway(), 'parameters')['mode']);

        Config::set('indipay.testMode', false);

        $this->assertSame('LIVE', $this->getProtected(new EBSGateway(), 'parameters')['mode']);
    }

    public function test_it_fills_default_parameters()
    {
        $parameters = $this->getProtected(new EBSGateway(), 'parameters');

        $this->assertSame(0, $parameters['channel']);
        $this->assertSame('5880', $parameters['account_id']);
        $this->assertSame('INR', $parameters['currency']);
        $this->assertSame('http://localhost/indipay/response', $parameters['return_url']);
        $this->assertPatternMatches('/^[0-9a-f]{20}$/', $parameters['reference_no']);
    }

    public function test_request_builds_the_secure_hash()
    {
        $gateway = (new EBSGateway())->request($this->validParameters());

        $expected = md5('EBS_SECRET|5880|100.00|REF1|http://localhost/indipay/response|TEST');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    public function test_request_url_encodes_hashed_values()
    {
        $gateway = (new EBSGateway())->request(['reference_no' => 'REF 1/2'] + $this->validParameters());

        $expected = md5('EBS_SECRET|5880|100.00|REF+1%2F2|http://localhost/indipay/response|TEST');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    public static function hashAlgorithms()
    {
        return [
            'md5' => ['md5'],
            'sha1' => ['sha1'],
            'sha512' => ['sha512'],
        ];
    }

    public function test_hash_algorithm_defaults_to_md5()
    {
        $this->assertSame('md5', Config::get('indipay.ebs.hashAlgorithm'));
    }

    public function test_request_uses_md5_when_the_algorithm_is_not_configured()
    {
        Config::offsetUnset('indipay.ebs.hashAlgorithm');

        $gateway = (new EBSGateway())->request($this->validParameters());

        $expected = md5('EBS_SECRET|5880|100.00|REF1|http://localhost/indipay/response|TEST');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    #[DataProvider('hashAlgorithms')]
    public function test_request_hashes_with_the_configured_algorithm($algorithm)
    {
        Config::set('indipay.ebs.hashAlgorithm', $algorithm);

        $gateway = (new EBSGateway())->request($this->validParameters());

        $expected = hash($algorithm, 'EBS_SECRET|5880|100.00|REF1|http://localhost/indipay/response|TEST');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    public function test_hash_algorithm_is_case_insensitive()
    {
        Config::set('indipay.ebs.hashAlgorithm', 'SHA512');

        $gateway = (new EBSGateway())->request($this->validParameters());

        $expected = hash('sha512', 'EBS_SECRET|5880|100.00|REF1|http://localhost/indipay/response|TEST');

        $this->assertSame($expected, $this->getProtected($gateway, 'hash'));
    }

    public static function unsupportedAlgorithms()
    {
        return [
            'unknown' => ['sha256'],
            'empty' => [''],
            'not a string' => [['md5']],
        ];
    }

    #[DataProvider('unsupportedAlgorithms')]
    public function test_unsupported_hash_algorithm_is_rejected_by_the_constructor($algorithm)
    {
        Config::set('indipay.ebs.hashAlgorithm', $algorithm);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indipay.ebs.hashAlgorithm');

        new EBSGateway();
    }

    public function test_request_requires_a_valid_email()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('email');

        (new EBSGateway())->request(['email' => 'not-an-email'] + $this->validParameters());
    }

    public function test_request_requires_customer_details()
    {
        $parameters = $this->validParameters();
        unset($parameters['city']);

        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('city');

        (new EBSGateway())->request($parameters);
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new EBSGateway())->request($this->validParameters())->send();

        $this->assertSame('indipay::ebs', $view->name());

        $html = $view->render();
        $this->assertStringContainsString('action="https://secure.ebs.in/pg/ma/payment/request"', $html);
        $this->assertStringContainsString('name="secure_hash" value="'.$view->getData()['hash'].'"', $html);
        $this->assertStringContainsString('name="state" value=""', $html);
    }

    /**
     * Hash EBS sends back: upper-case hash of secret|every non-empty field except SecureHash, sorted by name.
     */
    protected function responseHash(array $fields, $algorithm = 'md5')
    {
        unset($fields['SecureHash']);
        ksort($fields);

        $string = 'EBS_SECRET';
        foreach ($fields as $value) {
            if ($value !== '') {
                $string .= '|'.$value;
            }
        }

        return strtoupper(hash($algorithm, $string));
    }

    protected function responseFields()
    {
        return [
            'ResponseCode' => '0',
            'ResponseMessage' => 'Transaction Successful',
            'MerchantRefNo' => 'REF1',
            'Amount' => '100.00',
            'PaymentID' => '12345',
            'IsFlagged' => 'NO',
            'Description' => '',
        ];
    }

    public function test_response_with_a_valid_hash_is_returned()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields);

        $this->assertSame($fields, (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_ignores_query_parameters_on_the_return_url()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields);

        $request = \Illuminate\Http\Request::create('/indipay/response?order=123', 'POST', $fields);

        $this->assertSame($fields + ['order' => '123'], (new EBSGateway())->response($request));
    }

    public function test_response_with_a_lower_case_hash_is_returned()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = strtolower($this->responseHash($fields));

        $this->assertSame($fields, (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_a_tampered_field_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields);
        $fields['Amount'] = '1.00';

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_a_tampered_hash_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = str_repeat('0', 32);

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_signed_with_another_secret_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = strtoupper(md5('OTHER_SECRET|'.implode('|', array_filter($fields))));

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_without_a_hash_is_rejected()
    {
        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($this->responseFields())));
    }

    public function test_response_with_an_empty_body_is_rejected()
    {
        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest([])));
    }

    public function test_response_with_an_array_hash_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = ['x'];

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_an_array_field_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields);
        $fields['Extra'] = ['x'];

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    #[DataProvider('hashAlgorithms')]
    public function test_response_with_a_valid_hash_for_the_configured_algorithm_is_returned($algorithm)
    {
        Config::set('indipay.ebs.hashAlgorithm', $algorithm);

        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields, $algorithm);

        $this->assertSame($fields, (new EBSGateway())->response($this->makeRequest($fields)));
    }

    #[DataProvider('hashAlgorithms')]
    public function test_response_with_a_lower_case_hash_for_the_configured_algorithm_is_returned($algorithm)
    {
        Config::set('indipay.ebs.hashAlgorithm', $algorithm);

        $fields = $this->responseFields();
        $fields['SecureHash'] = strtolower($this->responseHash($fields, $algorithm));

        $this->assertSame($fields, (new EBSGateway())->response($this->makeRequest($fields)));
    }

    #[DataProvider('hashAlgorithms')]
    public function test_response_with_a_tampered_field_is_rejected_for_the_configured_algorithm($algorithm)
    {
        Config::set('indipay.ebs.hashAlgorithm', $algorithm);

        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields, $algorithm);
        $fields['Amount'] = '1.00';

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public static function mismatchedAlgorithms()
    {
        return [
            'md5 hash on a sha1 page' => ['sha1', 'md5'],
            'sha1 hash on a sha512 page' => ['sha512', 'sha1'],
            'sha512 hash on an md5 page' => ['md5', 'sha512'],
        ];
    }

    #[DataProvider('mismatchedAlgorithms')]
    public function test_response_hashed_with_another_algorithm_is_rejected($configured, $used)
    {
        Config::set('indipay.ebs.hashAlgorithm', $configured);

        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields, $used);

        $this->assertSame('Hash Mismatch Error', (new EBSGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_uses_md5_when_the_algorithm_is_not_configured()
    {
        Config::offsetUnset('indipay.ebs.hashAlgorithm');

        $fields = $this->responseFields();
        $fields['SecureHash'] = $this->responseHash($fields);

        $this->assertSame($fields, (new EBSGateway())->response($this->makeRequest($fields)));
    }
}
