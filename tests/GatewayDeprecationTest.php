<?php

namespace Softon\Indipay\Tests;

use ReflectionProperty;
use Softon\Indipay\Gateways\CitrusGateway;
use Softon\Indipay\Gateways\EBSGateway;
use Softon\Indipay\Gateways\MockerGateway;
use Softon\Indipay\Gateways\PaytmGateway;

class GatewayDeprecationTest extends TestCase
{
    public function test_gateways_declare_the_properties_they_assign()
    {
        $this->assertTrue((new ReflectionProperty(EBSGateway::class, 'secretKey'))->isProtected());
        $this->assertTrue((new ReflectionProperty(CitrusGateway::class, 'secretKey'))->isProtected());
        $this->assertTrue((new ReflectionProperty(MockerGateway::class, 'testMode'))->isProtected());
    }

    public function test_constructing_gateways_creates_no_dynamic_properties()
    {
        foreach ([new EBSGateway, new CitrusGateway, new MockerGateway] as $gateway) {
            $declared = array_map(
                function ($property) { return $property->getName(); },
                (new \ReflectionClass($gateway))->getProperties()
            );

            $this->assertSame([], array_values(array_diff(array_keys(get_object_vars($gateway)), $declared)));
        }
    }

    public function test_paytm_salt_generation_does_not_raise_deprecations()
    {
        $this->assertSame(8, strlen((new PaytmGateway)->generateSalt_e(8)));
    }
}
