<?php

namespace Softon\Indipay\Tests;

use Softon\Indipay\Gateways\CCAvenueGateway;
use Softon\Indipay\Gateways\PaymentGatewayInterface;

class ServiceProviderGatewayConfigTest extends TestCase
{
    protected $defaultGateway = 'CCAvenue';

    public function test_it_binds_the_gateway_named_in_config()
    {
        $this->assertInstanceOf(CCAvenueGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }
}
