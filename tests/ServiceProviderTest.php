<?php

namespace Softon\Indipay\Tests;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Illuminate\Support\Facades\View;
use Softon\Indipay\Facades\Indipay as IndipayFacade;
use Softon\Indipay\Gateways\CCAvenueGateway;
use Softon\Indipay\Gateways\MockerGateway;
use Softon\Indipay\Gateways\PaytmGateway;
use Softon\Indipay\Gateways\PaymentGatewayInterface;
use Softon\Indipay\Indipay;
use Softon\Indipay\IndipayServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_it_binds_indipay_to_the_container()
    {
        $this->assertInstanceOf(Indipay::class, $this->app->make('indipay'));
    }

    public function test_indipay_binding_is_not_a_singleton()
    {
        $this->assertNotSame($this->app->make('indipay'), $this->app->make('indipay'));
    }

    public function test_it_binds_the_configured_gateway_to_the_interface()
    {
        $this->assertInstanceOf(MockerGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    public function test_indipay_receives_the_configured_gateway()
    {
        $indipay = $this->app->make('indipay');

        $this->assertInstanceOf(MockerGateway::class, $this->getProtected($indipay, 'gateway'));
    }

    public function test_facade_resolves_to_indipay()
    {
        $this->assertInstanceOf(Indipay::class, IndipayFacade::getFacadeRoot());
    }

    public function test_facade_alias_is_registered()
    {
        $this->assertInstanceOf(Indipay::class, \Indipay::getFacadeRoot());
    }

    public function test_it_registers_the_view_namespace()
    {
        foreach (['ccavenue', 'citrus', 'ebs', 'instamojo', 'mocker', 'paytm', 'payumoney', 'zapakpay'] as $view) {
            $this->assertTrue(View::exists('indipay::'.$view), "View indipay::{$view} is missing");
        }
    }

    public function test_it_publishes_config_and_middleware()
    {
        $paths = ServiceProvider::pathsToPublish(IndipayServiceProvider::class);
        $sources = array_map('realpath', array_keys($paths));

        $this->assertContains(realpath(__DIR__.'/../src/config/config.php'), $sources);
        $this->assertContains(realpath(__DIR__.'/../src/stubs/VerifyCsrfMiddleware.stub'), $sources);
        $this->assertContains(base_path('config/indipay.php'), $paths);
        $this->assertContains(base_path('app/Http/Middleware/VerifyCsrfMiddleware.php'), $paths);
    }

    public function test_provider_is_not_deferred()
    {
        $provider = new IndipayServiceProvider($this->app);

        $this->assertFalse($provider->isDeferred());
        $this->assertSame([], $provider->provides());
    }

    public function test_config_can_be_published_on_its_own_with_a_tag()
    {
        $paths = ServiceProvider::pathsToPublish(IndipayServiceProvider::class, 'indipay-config');

        $this->assertSame([base_path('config/indipay.php')], array_values($paths));
        $this->assertCount(1, $paths);
    }

    public function test_package_defaults_are_merged_when_config_is_not_published()
    {
        $this->app['config']->set('indipay', []);
        (new IndipayServiceProvider($this->app))->register();

        $this->assertSame('Mocker', $this->app['config']->get('indipay.gateway'));
        $this->assertInstanceOf(MockerGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    public function test_gateway_name_is_case_insensitive()
    {
        $this->app['config']->set('indipay.gateway', 'paytm');

        $this->assertInstanceOf(PaytmGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    public function test_gateway_is_read_when_the_binding_is_resolved()
    {
        $this->assertInstanceOf(MockerGateway::class, $this->app->make(PaymentGatewayInterface::class));

        $this->app['config']->set('indipay.gateway', 'CCAvenue');

        $this->assertInstanceOf(CCAvenueGateway::class, $this->app->make(PaymentGatewayInterface::class));
    }

    public function test_unknown_gateway_throws_a_helpful_exception()
    {
        $this->app['config']->set('indipay.gateway', 'nope');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Indipay gateway [nope]');

        $this->app->make(PaymentGatewayInterface::class);
    }
}
