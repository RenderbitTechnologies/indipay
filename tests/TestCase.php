<?php

namespace Softon\Indipay\Tests;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;
use Softon\Indipay\IndipayServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Default gateway name bound by the service provider.
     *
     * @var string
     */
    protected $defaultGateway = 'Mocker';

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel logs deprecations instead of raising them; raise them so
        // package code that triggers one fails the suite.
        $this->withoutDeprecationHandling();
    }

    protected function getPackageProviders($app)
    {
        return [IndipayServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Indipay' => \Softon\Indipay\Facades\Indipay::class];
    }

    /**
     * The provider reads config in register(), which Testbench runs before
     * defineEnvironment(), so config is set while the app config loads.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('app.url', 'http://localhost');

        $config = require __DIR__.'/../src/config/config.php';

        $config['gateway'] = $this->defaultGateway;
        $config['testMode'] = true;

        $config['ccavenue'] = array_merge($config['ccavenue'], [
            'merchantId' => 'CCA_MERCHANT',
            'accessCode' => 'CCA_ACCESS',
            'workingKey' => 'CCA_WORKING_KEY',
        ]);

        $config['payumoney'] = array_merge($config['payumoney'], [
            'merchantKey' => 'PAYU_KEY',
            'salt' => 'PAYU_SALT',
        ]);

        $config['ebs'] = array_merge($config['ebs'], [
            'account_id' => '5880',
            'secretKey' => 'EBS_SECRET',
        ]);

        $config['citrus'] = array_merge($config['citrus'], [
            'vanityUrl' => 'testvanity',
            'secretKey' => 'CITRUS_SECRET',
        ]);

        $config['instamojo'] = array_merge($config['instamojo'], [
            'api_key' => 'IM_API_KEY',
            'auth_token' => 'IM_AUTH_TOKEN',
        ]);

        $config['zapakpay'] = array_merge($config['zapakpay'], [
            'merchantIdentifier' => 'ZP_MERCHANT',
            'secret' => 'ZP_SECRET',
        ]);

        $config['paytm'] = array_merge($config['paytm'], [
            'MERCHANT_KEY' => 'abcdefgh12345678',
            'MID' => 'PAYTM_MID',
        ]);

        $app['config']->set('indipay', $config);
    }

    /**
     * Read a protected or private property from an object.
     */
    protected function getProtected($object, $property)
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    /**
     * Build a POST request carrying the given fields.
     */
    protected function makeRequest(array $data = [])
    {
        return Request::create('/indipay/response', 'POST', $data);
    }
}
