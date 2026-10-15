<?php

namespace Softon\Indipay\Tests\Gateways;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\MockerGateway;
use Softon\Indipay\Tests\TestCase;

class MockerGatewayTest extends TestCase
{
    public function test_endpoint_includes_the_service()
    {
        $this->assertSame('https://mocker.in/payment/default', (new MockerGateway())->getEndPoint());

        Config::set('indipay.mocker.service', 'instamojo');
        Config::set('indipay.testMode', false);

        $this->assertSame('https://mocker.in/payment/instamojo', (new MockerGateway())->getEndPoint());
    }

    public function test_it_fills_the_redirect_url()
    {
        $this->assertSame(
            'http://localhost/indipay/response',
            $this->getProtected(new MockerGateway(), 'parameters')['redirect_url']
        );
    }

    public function test_request_merges_parameters()
    {
        $gateway = (new MockerGateway())->request(['transaction_no' => 'TXN1', 'amount' => '100']);

        $this->assertSame([
            'redirect_url' => 'http://localhost/indipay/response',
            'transaction_no' => 'TXN1',
            'amount' => '100',
        ], $this->getProtected($gateway, 'parameters'));
    }

    public static function invalidParameters()
    {
        return [
            'default: missing transaction no' => ['default', ['amount' => '100'], 'transaction no'],
            'default: non numeric amount' => ['default', ['transaction_no' => 'T1', 'amount' => 'abc'], 'amount'],
            'instamojo: amount below minimum' => ['instamojo', ['amount' => '5'], 'amount'],
            'ccavenue: amount above maximum' => ['ccavenue', ['amount' => '300000'], 'amount'],
        ];
    }

    #[DataProvider('invalidParameters')]
    public function test_request_validates_by_service($service, array $parameters, $field)
    {
        Config::set('indipay.mocker.service', $service);

        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage($field);

        (new MockerGateway())->request($parameters);
    }

    public function test_instamojo_and_ccavenue_services_do_not_need_a_transaction_no()
    {
        foreach (['instamojo', 'ccavenue'] as $service) {
            Config::set('indipay.mocker.service', $service);

            $gateway = (new MockerGateway())->request(['amount' => '100']);

            $this->assertSame('100', $this->getProtected($gateway, 'parameters')['amount']);
        }
    }

    public function test_send_renders_every_parameter_as_a_hidden_field()
    {
        $view = (new MockerGateway())->request(['transaction_no' => 'TXN1', 'amount' => '100'])->send();

        $this->assertSame('indipay::mocker', $view->name());
        $this->assertSame('https://mocker.in/payment/default', $view->getData()['end_point']);

        $html = $view->render();
        $this->assertStringContainsString('action="https://mocker.in/payment/default"', $html);
        $this->assertStringContainsString('name="transaction_no" value="TXN1"', $html);
        $this->assertStringContainsString('name="amount" value="100"', $html);
        $this->assertStringContainsString('name="redirect_url" value="http://localhost/indipay/response"', $html);
    }

    public function test_response_marks_successful_transactions()
    {
        $response = (new MockerGateway())->response($this->makeRequest(['transaction_status' => 'success', 'transaction_no' => 'TXN1']));

        $this->assertSame('success', $response['status']);
        $this->assertSame('TXN1', $response['transaction_no']);
    }

    public function test_response_marks_other_transactions_as_failed()
    {
        $response = (new MockerGateway())->response($this->makeRequest(['transaction_status' => 'failed']));

        $this->assertSame('failure', $response['status']);
    }

    public static function incompleteVerifyParameters()
    {
        return [
            'nothing' => [[]],
            'no amount' => [['transaction_no' => 'TXN1']],
            'no transaction no' => [['amount' => '100']],
        ];
    }

    #[DataProvider('incompleteVerifyParameters')]
    public function test_verify_requires_transaction_no_and_amount(array $parameters)
    {
        $this->expectException(IndipayParametersMissingException::class);

        (new MockerGateway())->verify($parameters);
    }
}
