<?php

namespace Softon\Indipay\Tests;

use Exception;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class IndipayParametersMissingExceptionTest extends TestCase
{
    public function test_it_is_an_exception()
    {
        $this->assertInstanceOf(Exception::class, new IndipayParametersMissingException('oops'));
    }

    public function test_render_returns_the_message()
    {
        $exception = new IndipayParametersMissingException('{"amount":["The amount field is required."]}');

        $this->assertSame(
            '{"amount":["The amount field is required."]}',
            $exception->render($this->makeRequest())
        );
    }
}
