<?php namespace Softon\Indipay\Exceptions;

use Illuminate\Contracts\Support\MessageBag;

/**
 * Invalid Request Exception
 *
 * Thrown when a request is invalid or missing required fields.
 */
class IndipayParametersMissingException extends \Exception
{
    /**
     * The validation errors, when the exception was built from a MessageBag.
     *
     * @var MessageBag|null
     */
    protected $errors;

    /**
     * @param  string|MessageBag $message
     * @param  int               $code
     * @param  \Throwable|null   $previous
     */
    public function __construct($message = '', $code = 0, $previous = null)
    {
        if ($message instanceof MessageBag) {
            $this->errors = $message;
        }

        parent::__construct((string) $message, $code, $previous);
    }

    /**
     * Get the validation errors, if any.
     *
     * @return MessageBag|null
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Render the exception into an HTTP response.
     *
     * The payment could not be set up, so this is reported as 422 rather than
     * a successful response.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json(
                ['message' => 'Missing or invalid payment parameters.', 'errors' => $this->errors ? $this->errors->toArray() : $this->getMessage()],
                422
            );
        }

        return response($this->getMessage(), 422);
    }
}
