<?php

namespace Softon\Indipay\Tests\Gateways;

use Softon\Indipay\Gateways\CCAvenueGateway;
use Softon\Indipay\Gateways\PaytmGateway;
use Softon\Indipay\Gateways\PayUMoneyGateway;
use Softon\Indipay\Tests\TestCase;

class GatewayStateResetTest extends TestCase
{
    public function test_ccavenue_second_request_encrypts_only_the_second_payment()
    {
        $gateway = new CCAvenueGateway();

        $gateway->request(['order_id' => '1', 'amount' => '10', 'merchant_param1' => 'first']);
        $gateway->request(['order_id' => '2', 'amount' => '20']);

        $plain = $gateway->decrypt($this->getProtected($gateway, 'encRequest'), $this->getProtected($gateway, 'workingKey'));
        $this->assertStringContainsString('order_id=2&', $plain);
        $this->assertStringNotContainsString('order_id=1', $plain);
        $this->assertSame(1, substr_count($plain, 'merchant_id='));
        $this->assertStringNotContainsString('merchant_param1', $plain);
    }

    public function test_request_does_not_carry_fields_over_from_the_previous_payment()
    {
        $gateway = new PayUMoneyGateway();
        $base = ['amount' => '10', 'productinfo' => 'P', 'firstname' => 'A', 'email' => 'a@example.com', 'phone' => '9999999999'];

        $gateway->request(['txnid' => 'T1', 'udf1' => 'previous'] + $base);
        $gateway->request(['txnid' => 'T2'] + $base);

        $parameters = $this->getProtected($gateway, 'parameters');
        $this->assertSame('T2', $parameters['txnid']);
        $this->assertArrayNotHasKey('udf1', $parameters);
    }

    public function test_paytm_second_request_does_not_sign_the_previous_checksum()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => '1', 'CUST_ID' => 'C', 'TXN_AMOUNT' => '10'];

        $gateway->request($params);
        $second = $this->getProtected($gateway->request($params), 'checksum');

        // Verifying against the plain fields fails if the first checksum was signed in.
        $parameters = $this->getProtected($gateway, 'parameters');
        unset($parameters['CHECKSUMHASH']);
        $this->assertSame('TRUE', $gateway->verifychecksum_e($parameters, $this->getProtected($gateway, 'MERCHANT_KEY'), $second));
    }
}
