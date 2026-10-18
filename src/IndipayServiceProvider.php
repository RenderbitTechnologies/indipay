<?php namespace Softon\Indipay;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class IndipayServiceProvider extends ServiceProvider {

	/**
	 * Gateway classes keyed by lower-cased gateway name.
	 *
	 * @var array
	 */
	protected $gateways = [
		'ccavenue' => 'Softon\Indipay\Gateways\CCAvenueGateway',
		'payumoney' => 'Softon\Indipay\Gateways\PayUMoneyGateway',
		'ebs' => 'Softon\Indipay\Gateways\EBSGateway',
		'citrus' => 'Softon\Indipay\Gateways\CitrusGateway',
		'instamojo' => 'Softon\Indipay\Gateways\InstaMojoGateway',
		'mocker' => 'Softon\Indipay\Gateways\MockerGateway',
		'zapakpay' => 'Softon\Indipay\Gateways\ZapakPayGateway',
		'paytm' => 'Softon\Indipay\Gateways\PaytmGateway',
	];

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
        $this->mergeConfigFrom(__DIR__.'/config/config.php', 'indipay');

        $this->app->bind('indipay', 'Softon\Indipay\Indipay');

        $gateways = $this->gateways;

        $this->app->bind('Softon\Indipay\Gateways\PaymentGatewayInterface', function ($app) use ($gateways) {
            $name = Config::get('indipay.gateway');
            $key = is_string($name) ? strtolower($name) : '';

            if (!isset($gateways[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown Indipay gateway [%s] in config/indipay.php. Supported gateways: %s.',
                    is_scalar($name) ? $name : gettype($name),
                    implode(', ', array_keys($gateways))
                ));
            }

            return $app->make($gateways[$key]);
        });
	}


    public function boot(){
        $this->publishes([
            __DIR__.'/config/config.php' => base_path('config/indipay.php'),
            __DIR__.'/stubs/VerifyCsrfMiddleware.stub' => base_path('app/Http/Middleware/VerifyCsrfMiddleware.php'),
        ]);

        $this->publishes([
            __DIR__.'/config/config.php' => base_path('config/indipay.php'),
        ], 'indipay-config');

		$this->loadViewsFrom(__DIR__.'/views', 'indipay');

    }

}
