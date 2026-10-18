<?php

namespace Softon\Indipay\Tests;

use Exception;
use Illuminate\Support\MessageBag;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class IndipayParametersMissingExceptionTest extends TestCase
{
    public function test_it_is_an_exception()
    {
        $this->assertInstanceOf(Exception::class, new IndipayParametersMissingException('oops'));
    }

    public function test_render_returns_a_422_response_with_the_message()
    {
        $exception = new IndipayParametersMissingException('{"amount":["The amount field is required."]}');

        $response = $exception->render($this->makeRequest());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('{"amount":["The amount field is required."]}', $response->getContent());
    }

    public function test_render_returns_json_422_when_json_is_expected()
    {
        $bag = new MessageBag(['amount' => ['The amount field is required.']]);
        $request = $this->makeRequest();
        $request->headers->set('Accept', 'application/json');

        $response = (new IndipayParametersMissingException($bag))->render($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['amount' => ['The amount field is required.']], $response->getData(true)['errors']);
    }

    public function test_errors_exposes_the_message_bag()
    {
        $bag = new MessageBag(['amount' => ['x']]);
        $exception = new IndipayParametersMissingException($bag);

        $this->assertSame($bag, $exception->errors());
        $this->assertSame('{"amount":["x"]}', $exception->getMessage());
        $this->assertNull((new IndipayParametersMissingException('plain'))->errors());
    }
}
