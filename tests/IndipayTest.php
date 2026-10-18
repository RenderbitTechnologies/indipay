<?php

namespace Softon\Indipay\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\UnsupportedOperationException;
use Softon\Indipay\Gateways\CCAvenueGateway;
use Softon\Indipay\Gateways\CitrusGateway;
use Softon\Indipay\Gateways\EBSGateway;
use Softon\Indipay\Gateways\InstaMojoGateway;
use Softon\Indipay\Gateways\MockerGateway;
use Softon\Indipay\Gateways\PaymentGatewayInterface;
use Softon\Indipay\Gateways\PaytmGateway;
use Softon\Indipay\Gateways\PayUMoneyGateway;
use Softon\Indipay\Gateways\ZapakPayGateway;
use Softon\Indipay\Indipay;

class IndipayTest extends TestCase
{
    public static function gatewayNames()
    {
        return [
            'ccavenue' => ['ccavenue', CCAvenueGateway::class],
            'payumoney' => ['payumoney', PayUMoneyGateway::class],
            'ebs' => ['ebs', EBSGateway::class],
            'citrus' => ['citrus', CitrusGateway::class],
            'instamojo' => ['instamojo', InstaMojoGateway::class],
            'mocker' => ['mocker', MockerGateway::class],
            'zapakpay' => ['zapakpay', ZapakPayGateway::class],
            'mixed case' => ['CCAvenue', CCAvenueGateway::class],
            'upper case' => ['PAYUMONEY', PayUMoneyGateway::class],
        ];
    }

    #[DataProvider('gatewayNames')]
    public function test_gateway_switches_the_active_gateway($name, $class)
    {
        $indipay = new Indipay(new MockerGateway());

        $result = $indipay->gateway($name);

        $this->assertSame($indipay, $result);
        $this->assertInstanceOf($class, $this->getProtected($indipay, 'gateway'));
    }

    public function test_unknown_gateway_name_keeps_the_current_gateway()
    {
        $gateway = new MockerGateway();
        $indipay = new Indipay($gateway);

        $indipay->gateway('does-not-exist');

        $this->assertSame($gateway, $this->getProtected($indipay, 'gateway'));
    }

    public function test_gateway_switches_to_paytm()
    {
        $indipay = new Indipay(new MockerGateway());

        $indipay->gateway('paytm');

        $this->assertInstanceOf(PaytmGateway::class, $this->getProtected($indipay, 'gateway'));
    }

    public function test_purchase_builds_the_request_and_sends_it()
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($this->once())->method('request')->with(['amount' => 10])->willReturnSelf();
        $gateway->expects($this->once())->method('send')->willReturn('redirect-view');

        $this->assertSame('redirect-view', (new Indipay($gateway))->purchase(['amount' => 10]));
    }

    public function test_prepare_returns_the_gateway_without_sending()
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($this->once())->method('request')->with(['amount' => 10])->willReturnSelf();
        $gateway->expects($this->never())->method('send');

        $this->assertSame($gateway, (new Indipay($gateway))->prepare(['amount' => 10]));
    }

    public function test_process_sends_a_prepared_order()
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($this->once())->method('send')->willReturn('redirect-view');

        $this->assertSame('redirect-view', (new Indipay($gateway))->process($gateway));
    }

    public function test_response_delegates_to_the_gateway()
    {
        $request = $this->makeRequest(['foo' => 'bar']);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($this->once())->method('response')->with($request)->willReturn(['status' => 'success']);

        $this->assertSame(['status' => 'success'], (new Indipay($gateway))->response($request));
    }

    public function test_verify_delegates_to_the_gateway()
    {
        $gateway = new class extends MockerGateway {
            public $verified;

            public function verify($parameters)
            {
                $this->verified = $parameters;

                return ['status' => 'success'];
            }
        };

        $this->assertSame(['status' => 'success'], (new Indipay($gateway))->verify(['ORDERID' => '1']));
        $this->assertSame(['ORDERID' => '1'], $gateway->verified);
    }

    public static function gatewaysWithoutVerify()
    {
        return [
            'ccavenue' => [CCAvenueGateway::class],
            'payumoney' => [PayUMoneyGateway::class],
            'ebs' => [EBSGateway::class],
            'citrus' => [CitrusGateway::class],
            'instamojo' => [InstaMojoGateway::class],
            'zapakpay' => [ZapakPayGateway::class],
        ];
    }

    #[DataProvider('gatewaysWithoutVerify')]
    public function test_verify_throws_for_a_gateway_without_verify_support($class)
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage($class);

        (new Indipay(new $class()))->verify(['ORDERID' => '1']);
    }

    public function test_verify_still_works_for_a_custom_gateway_that_has_verify_without_the_interface()
    {
        $gateway = new class implements PaymentGatewayInterface {
            public function request($parameters) { return $this; }
            public function send() {}
            public function response($request) {}
            public function verify($parameters) { return ['status' => 'success']; }
        };

        $this->assertSame(['status' => 'success'], (new Indipay($gateway))->verify([]));
    }

    public function test_facade_purchase_runs_through_the_configured_gateway()
    {
        $view = \Indipay::purchase(['transaction_no' => 'TXN1', 'amount' => '100']);

        $this->assertSame('indipay::mocker', $view->name());
        $this->assertSame('TXN1', $view->getData()['data']['transaction_no']);
    }
}
