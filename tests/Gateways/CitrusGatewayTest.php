<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\CitrusGateway;
use Softon\Indipay\Tests\TestCase;

class CitrusGatewayTest extends TestCase
{
    protected function responseFields()
    {
        return [
            'TxId' => 'TX1',
            'TxStatus' => 'SUCCESS',
            'amount' => '100.00',
            'pgTxnNo' => 'PG1',
            'issuerRefNo' => 'ISS1',
            'authIdCode' => 'AUTH1',
            'firstName' => 'Asha',
            'lastName' => 'Rao',
            'pgRespCode' => '0',
            'addressZip' => '400001',
        ];
    }

    protected function sign(array $fields)
    {
        return hash_hmac('sha1', implode('', $fields), 'CITRUS_SECRET');
    }

    public function test_endpoint_includes_the_vanity_url()
    {
        $this->assertSame('https://sandbox.citruspay.com/testvanity', (new CitrusGateway())->getEndPoint());

        Config::set('indipay.testMode', false);

        $this->assertSame('https://www.citruspay.com/testvanity', (new CitrusGateway())->getEndPoint());
    }

    public function test_it_fills_default_parameters()
    {
        $parameters = $this->getProtected(new CitrusGateway(), 'parameters');

        $this->assertSame('INR', $parameters['currency']);
        $this->assertSame('http://localhost/indipay/response', $parameters['returnUrl']);
        $this->assertSame('http://localhost/indipay/response', $parameters['notifyUrl']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $parameters['merchantTxnId']);
    }

    public function test_generate_transaction_id_is_unique()
    {
        $gateway = new CitrusGateway();

        $this->assertNotSame($gateway->generateTransactionID(), $gateway->generateTransactionID());
    }

    public function test_request_signs_the_order()
    {
        $gateway = (new CitrusGateway())->request(['orderAmount' => '100.00', 'merchantTxnId' => 'TXN1']);

        $this->assertSame(
            hash_hmac('sha1', 'testvanity100.00TXN1INR', 'CITRUS_SECRET'),
            $this->getProtected($gateway, 'hash')
        );
    }

    public function test_request_requires_a_numeric_order_amount()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('order amount');

        (new CitrusGateway())->request(['orderAmount' => 'abc']);
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new CitrusGateway())->request(['orderAmount' => '100.00', 'merchantTxnId' => 'TXN1'])->send();

        $this->assertSame('indipay::citrus', $view->name());
        $this->assertSame('https://sandbox.citruspay.com/testvanity', $view->getData()['endPoint']);

        $html = $view->render();
        $this->assertStringContainsString('name="secSignature" value="'.hash_hmac('sha1', 'testvanity100.00TXN1INR', 'CITRUS_SECRET').'"', $html);
        $this->assertStringContainsString('name="merchantTxnId" value="TXN1"', $html);
    }

    public function test_response_with_a_valid_signature_is_returned()
    {
        $fields = $this->responseFields();
        $fields['signature'] = $this->sign($this->responseFields());

        $this->assertSame($fields, (new CitrusGateway())->response($this->makeRequest($fields)));
    }

    public function test_response_with_a_tampered_field_is_rejected()
    {
        $fields = $this->responseFields();
        $fields['signature'] = $this->sign($this->responseFields());
        $fields['amount'] = '1.00';

        $this->assertSame('Hash Mismatch Error', (new CitrusGateway())->response($this->makeRequest($fields)));
    }

    public static function missingCallbackFields()
    {
        return [
            'signature' => [['signature']],
            'TxId' => [['TxId']],
            'addressZip' => [['addressZip']],
            'every field' => [['TxId', 'TxStatus', 'amount', 'pgTxnNo', 'issuerRefNo', 'authIdCode', 'firstName', 'lastName', 'pgRespCode', 'addressZip', 'signature']],
        ];
    }

    #[DataProvider('missingCallbackFields')]
    public function test_response_with_missing_fields_is_rejected(array $missing)
    {
        $fields = $this->responseFields();
        $fields['signature'] = $this->sign($this->responseFields());
        $fields = array_diff_key($fields, array_flip($missing));

        $this->assertSame('Hash Mismatch Error', (new CitrusGateway())->response($this->makeRequest($fields)));
    }
}
