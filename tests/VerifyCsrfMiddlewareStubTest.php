<?php

namespace Softon\Indipay\Tests;

use Illuminate\Http\Request;
use ReflectionMethod;

class VerifyCsrfMiddlewareStubTest extends TestCase
{
    private function middleware()
    {
        if (! class_exists(\App\Http\Middleware\VerifyCsrfMiddleware::class, false)) {
            require __DIR__.'/../src/stubs/VerifyCsrfMiddleware.stub';
        }

        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        return new \App\Http\Middleware\VerifyCsrfMiddleware($this->app, $this->app['encrypter']);
    }

    private function excepted($middleware, $uri)
    {
        $method = new ReflectionMethod($middleware, 'inExceptArray');
        $method->setAccessible(true);

        return $method->invoke($middleware, Request::create($uri, 'POST'));
    }

    public function test_it_does_not_override_handle()
    {
        $method = new ReflectionMethod($this->middleware(), 'handle');

        $this->assertSame(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, $method->class);
    }

    public function test_it_excludes_configured_routes()
    {
        $this->app['config']->set('indipay.remove_csrf_check', ['indipay/response']);

        $this->assertTrue($this->excepted($this->middleware(), '/indipay/response'));
        $this->assertFalse($this->excepted($this->middleware(), '/other'));
    }

    public function test_it_keeps_the_apps_own_exclusions()
    {
        $this->app['config']->set('indipay.remove_csrf_check', ['indipay/response']);
        $middleware = $this->middleware();

        $this->assertFalse($this->excepted($middleware, '/webhook'));

        $property = new \ReflectionProperty($middleware, 'except');
        $property->setAccessible(true);
        $property->setValue($middleware, ['webhook']);

        $this->assertTrue($this->excepted($middleware, '/webhook'));
    }

    public function test_missing_config_key_does_not_error()
    {
        $this->app['config']->set('indipay.remove_csrf_check', null);

        $this->assertFalse($this->excepted($this->middleware(), '/indipay/response'));
    }
}
