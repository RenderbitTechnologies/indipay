<?php namespace Softon\Indipay\Exceptions;

/**
 * Gateway Exception
 *
 * Thrown when a payment gateway rejects a request or answers with something
 * that cannot be understood.
 */
class IndipayGatewayException extends \Exception
{
    /**
     * Render the exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    public function render($request)
    {
        return $this->getMessage();
    }
}
