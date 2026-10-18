<?php

namespace Softon\Indipay\Tests\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;
use Softon\Indipay\Gateways\PaytmGateway;
use Softon\Indipay\Tests\TestCase;

class PaytmGatewayTest extends TestCase
{
    const KEY = 'abcdefgh12345678';

    protected function validParameters()
    {
        return ['ORDER_ID' => 'ORD1', 'CUST_ID' => 'CUST1', 'TXN_AMOUNT' => '100.00'];
    }

    public function test_endpoints_follow_test_mode()
    {
        $gateway = new PaytmGateway();
        $this->assertSame('https://securegw-stage.paytm.in/order/process', $gateway->getEndPoint());
        $this->assertSame('https://securegw-stage.paytm.in/order/status', $gateway->getStatusEndPoint());

        Config::set('indipay.testMode', false);

        $gateway = new PaytmGateway();
        $this->assertSame('https://securegw.paytm.in/order/process', $gateway->getEndPoint());
        $this->assertSame('https://securegw.paytm.in/order/status', $gateway->getStatusEndPoint());
    }

    public function test_it_fills_default_parameters_from_config()
    {
        $this->assertSame([
            'MID' => 'PAYTM_MID',
            'CHANNEL_ID' => 'WEB',
            'CALLBACK_URL' => 'http://localhost/indipay/response',
            'WEBSITE' => 'WEBSTAGING',
            'INDUSTRY_TYPE_ID' => 'Retail',
        ], $this->getProtected(new PaytmGateway(), 'parameters'));
    }

    public function test_encrypt_and_decrypt_round_trip()
    {
        $gateway = new PaytmGateway();

        $encrypted = $gateway->encrypt_e('hello world', self::KEY);

        $this->assertNotSame('hello world', $encrypted);
        $this->assertSame('hello world', $gateway->decrypt_e($encrypted, self::KEY));
    }

    public function test_generate_salt_returns_the_requested_length()
    {
        $salt = (new PaytmGateway())->generateSalt_e(4);

        $this->assertSame(4, strlen($salt));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{4}$/', $salt);
    }

    public function test_check_string_blanks_the_null_literal()
    {
        $gateway = new PaytmGateway();

        $this->assertSame('', $gateway->checkString_e('null'));
        $this->assertSame('value', $gateway->checkString_e('value'));
    }

    public function test_array_to_string_skips_refund_and_pipe_values()
    {
        $string = (new PaytmGateway())->getArray2Str([
            'A' => 'one',
            'B' => 'REFUND',
            'C' => 'has|pipe',
            'D' => 'null',
            'E' => 'two',
        ]);

        $this->assertSame('one||two', $string);
    }

    public function test_array_to_string_for_verify_keeps_every_value()
    {
        $string = (new PaytmGateway())->getArray2StrForVerify(['A' => 'one', 'B' => 'REFUND', 'C' => 'null']);

        $this->assertSame('one|REFUND|', $string);
    }

    public function test_refund_array_to_string_only_skips_pipe_values()
    {
        $string = (new PaytmGateway())->getRefundArray2Str(['A' => 'one', 'B' => 'REFUND', 'C' => 'a|b']);

        $this->assertSame('one|REFUND', $string);
    }

    public function test_remove_checksum_param()
    {
        $gateway = new PaytmGateway();

        $this->assertSame(['A' => '1'], $gateway->removeCheckSumParam(['A' => '1', 'CHECKSUMHASH' => 'x']));
        $this->assertSame(['A' => '1'], $gateway->removeCheckSumParam(['A' => '1']));
    }

    public function test_checksum_from_array_verifies()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1', 'MID' => 'PAYTM_MID', 'TXN_AMOUNT' => '100.00'];

        $checksum = $gateway->getChecksumFromArray($params, self::KEY);

        $this->assertTrue($gateway->verifychecksum_e($params, self::KEY, $checksum));
        $this->assertTrue($gateway->verifychecksum_e($params + ['CHECKSUMHASH' => $checksum], self::KEY, $checksum));
    }

    public function test_checksum_is_salted()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1'];

        $this->assertNotSame(
            $gateway->getChecksumFromArray($params, self::KEY),
            $gateway->getChecksumFromArray($params, self::KEY)
        );
    }

    public function test_checksum_fails_for_tampered_data()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1', 'TXN_AMOUNT' => '100.00'];

        $checksum = $gateway->getChecksumFromArray($params, self::KEY);

        $this->assertFalse($gateway->verifychecksum_e(['TXN_AMOUNT' => '1.00'] + $params, self::KEY, $checksum));
    }

    public function test_checksum_fails_for_the_wrong_key()
    {
        $gateway = new PaytmGateway();
        $params = ['ORDER_ID' => 'ORD1'];

        $checksum = $gateway->getChecksumFromArray($params, self::KEY);

        $this->assertFalse($gateway->verifychecksum_e($params, 'zzzzzzzz87654321', $checksum));
    }

    public function test_checksum_from_string_verifies()
    {
        $gateway = new PaytmGateway();

        $checksum = $gateway->getChecksumFromString('a|b|c', self::KEY);

        $this->assertTrue($gateway->verifychecksum_eFromStr('a|b|c', self::KEY, $checksum));
        $this->assertFalse($gateway->verifychecksum_eFromStr('a|b|d', self::KEY, $checksum));
    }

    public function test_verify_rejects_garbage_checksums()
    {
        $gateway = new PaytmGateway();

        $this->assertFalse($gateway->verifychecksum_e(['A' => 'b'], self::KEY, ''));
        $this->assertFalse($gateway->verifychecksum_eFromStr('a|b|c', self::KEY, 'not-a-checksum'));
    }

    public function test_refund_checksum_verifies_against_the_same_string()
    {
        $gateway = new PaytmGateway();
        $params = ['B' => 'two', 'A' => 'one'];

        $checksum = $gateway->getRefundChecksumFromArray($params, self::KEY);

        $this->assertTrue($gateway->verifychecksum_eFromStr('one|two', self::KEY, $checksum));
    }

    public function test_request_adds_a_valid_checksum()
    {
        $gateway = (new PaytmGateway())->request($this->validParameters());
        $parameters = $this->getProtected($gateway, 'parameters');

        $this->assertSame($this->getProtected($gateway, 'checksum'), $parameters['CHECKSUMHASH']);
        $this->assertTrue($gateway->verifychecksum_e($parameters, self::KEY, $parameters['CHECKSUMHASH']));
    }

    public function test_request_requires_order_details()
    {
        $this->expectException(IndipayParametersMissingException::class);
        $this->expectExceptionMessage('c u s t  i d');

        (new PaytmGateway())->request(['ORDER_ID' => 'ORD1', 'TXN_AMOUNT' => '100.00']);
    }

    public function test_request_requires_a_numeric_amount()
    {
        $this->expectException(IndipayParametersMissingException::class);

        (new PaytmGateway())->request(['TXN_AMOUNT' => 'abc'] + $this->validParameters());
    }

    public function test_send_renders_the_redirect_form()
    {
        $view = (new PaytmGateway())->request($this->validParameters())->send();

        $this->assertSame('indipay::paytm', $view->name());

        $html = $view->render();
        $this->assertStringContainsString('action="https://securegw-stage.paytm.in/order/process"', $html);
        $this->assertStringContainsString('name="ORDER_ID" value="ORD1"', $html);
        $this->assertStringContainsString('name="CHECKSUMHASH" value="'.e($view->getData()['checksum']).'"', $html);
    }

    protected function signedResponse(array $fields)
    {
        $fields['CHECKSUMHASH'] = (new PaytmGateway())->getChecksumFromArray($fields, self::KEY);

        return $fields;
    }

    public function test_response_is_successful_with_a_valid_checksum_and_success_status()
    {
        $fields = $this->signedResponse(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_SUCCESS']);

        $response = (new PaytmGateway())->response($this->makeRequest($fields));

        $this->assertSame('success', $response['status']);
        $this->assertSame('ORD1', $response['ORDERID']);
    }

    public function test_response_fails_when_the_transaction_failed()
    {
        $fields = $this->signedResponse(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_FAILURE']);

        $this->assertSame('failure', (new PaytmGateway())->response($this->makeRequest($fields))['status']);
    }

    public function test_response_fails_when_the_checksum_does_not_match()
    {
        $fields = $this->signedResponse(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_SUCCESS']);
        $fields['TXNAMOUNT'] = '1.00';

        $this->assertSame('failure', (new PaytmGateway())->response($this->makeRequest($fields))['status']);
    }

    public function test_verify_without_an_order_id_returns_false()
    {
        $this->assertFalse((new PaytmGateway())->verify([]));
    }

    /**
     * Build a Guzzle client that answers with the given queue of responses.
     */
    protected function client(array $queue, array &$history = [])
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    protected function statusClient(array $body, array &$history = [])
    {
        return $this->client([new Response(200, [], json_encode($body))], $history);
    }

    protected function successReply(array $overrides = [])
    {
        return $overrides + ['MID' => 'PAYTM_MID', 'ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_SUCCESS'];
    }

    /**
     * Run $callback and return the deprecation messages it raised.
     */
    protected function deprecations(callable $callback, &$result = null)
    {
        $messages = [];
        set_error_handler(function ($errno, $errstr) use (&$messages) {
            $messages[] = $errstr;
            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    public function test_verify_posts_a_signed_json_data_body_to_the_status_endpoint()
    {
        $history = [];
        $client = $this->statusClient($this->successReply(), $history);

        (new PaytmGateway($client))->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://securegw-stage.paytm.in/order/status', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = (string) $request->getBody();
        $this->assertStringStartsWith('JsonData=', $body);

        $data = json_decode(urldecode(substr($body, strlen('JsonData='))), true);
        $this->assertSame('PAYTM_MID', $data['MID']);
        $this->assertSame('ORD1', $data['ORDERID']);
        $this->assertTrue((new PaytmGateway())->verifychecksum_e($data, self::KEY, $data['CHECKSUMHASH']));
    }

    public function test_status_calls_keep_tls_verification_on()
    {
        $options = [];
        $reply = function ($request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;
            return new Response(200, [], json_encode($this->successReply()));
        };
        $gateway = new PaytmGateway($this->client([$reply, $reply, $reply]));

        $gateway->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);
        $gateway->callAPI('https://securegw-stage.paytm.in/order/status', []);
        $gateway->callRefundAPI('https://securegw-stage.paytm.in/refund/apply', []);

        $this->assertCount(3, $options);
        foreach ($options as $requestOptions) {
            $this->assertNotFalse($requestOptions['verify'] ?? true);
        }
    }

    public function test_verify_marks_a_matching_amount_as_success()
    {
        $result = (new PaytmGateway($this->statusClient($this->successReply())))
            ->verify(['ORDERID' => 'ORD1', 'amount' => 100]);

        $this->assertSame($this->successReply() + ['status' => 'success'], $result);
    }

    public function test_verify_marks_a_matching_zero_amount_as_success()
    {
        $result = (new PaytmGateway($this->statusClient($this->successReply(['TXNAMOUNT' => '0.00']))))
            ->verify(['ORDERID' => 'ORD1', 'amount' => 0]);

        $this->assertSame('success', $result['status']);
    }

    public function test_verify_marks_a_different_amount_as_failure()
    {
        $result = (new PaytmGateway($this->statusClient($this->successReply(['TXNAMOUNT' => '1.00']))))
            ->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame($this->successReply(['TXNAMOUNT' => '1.00']) + ['status' => 'failure'], $result);
    }

    public function test_verify_fails_when_the_reply_amount_is_missing_or_not_numeric()
    {
        foreach ([null, 'abc', ''] as $amount) {
            $reply = $this->successReply();
            $reply['TXNAMOUNT'] = $amount;

            $result = (new PaytmGateway($this->statusClient($reply)))->verify(['ORDERID' => 'ORD1', 'amount' => 0]);

            $this->assertSame('failure', $result['status']);
        }
    }

    public function test_verify_fails_when_the_transaction_failed()
    {
        $result = (new PaytmGateway($this->statusClient($this->successReply(['STATUS' => 'TXN_FAILURE']))))
            ->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame('failure', $result['status']);
    }

    public function test_verify_fails_when_the_reply_is_for_another_order()
    {
        $result = (new PaytmGateway($this->statusClient($this->successReply(['ORDERID' => 'ORD2']))))
            ->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame('failure', $result['status']);
    }

    public function test_verify_fails_when_the_reply_has_no_order_id()
    {
        $reply = $this->successReply();
        unset($reply['ORDERID']);

        $result = (new PaytmGateway($this->statusClient($reply)))->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame('failure', $result['status']);
    }

    public function test_verify_fails_when_the_reply_is_for_another_merchant()
    {
        $result = (new PaytmGateway($this->statusClient($this->successReply(['MID' => 'OTHER_MID']))))
            ->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame('failure', $result['status']);
    }

    public function test_verify_accepts_a_reply_without_a_mid()
    {
        $reply = $this->successReply();
        unset($reply['MID']);

        $result = (new PaytmGateway($this->statusClient($reply)))->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame('success', $result['status']);
    }

    public static function failedReplies()
    {
        return [
            'connection error' => [new ConnectException('timed out', new Psr7Request('POST', 'https://securegw-stage.paytm.in/order/status'))],
            'server error' => [new Response(500, [], json_encode(['STATUS' => 'TXN_SUCCESS']))],
            'non 200 success' => [new Response(202, [], json_encode(['STATUS' => 'TXN_SUCCESS']))],
            'not json' => [new Response(200, [], '<html>gateway timeout</html>')],
            'json scalar' => [new Response(200, [], '"TXN_SUCCESS"')],
            'no status' => [new Response(200, [], json_encode(['ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00']))],
        ];
    }

    #[DataProvider('failedReplies')]
    public function test_verify_fails_without_throwing_when_the_status_call_fails($reply)
    {
        $result = (new PaytmGateway($this->client([$reply])))->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame(['status' => 'failure'], $result);
    }

    public function test_status_call_returns_null_when_a_client_does_not_throw_on_http_errors()
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(503, [], '{"STATUS":"TXN_SUCCESS"}')])),
            'http_errors' => false,
        ]);

        $this->assertNull((new PaytmGateway($client))->callNewAPI('https://securegw-stage.paytm.in/order/status', []));
    }

    public function test_verify_without_an_amount_is_deprecated_but_keeps_the_old_result()
    {
        $gateway = new PaytmGateway($this->statusClient($this->successReply(['TXNAMOUNT' => '1.00'])));

        $messages = $this->deprecations(function () use ($gateway) {
            return $gateway->verify(['ORDERID' => 'ORD1']);
        }, $result);

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('without an expected "amount" is deprecated', $messages[0]);
    }

    public function test_the_deprecation_is_silenced_for_handlers_that_throw_on_reported_errors()
    {
        $gateway = new PaytmGateway($this->statusClient($this->successReply()));

        // Laravel before 9 throws for any error that error_reporting() includes.
        set_error_handler(function ($errno, $errstr) {
            if (error_reporting() & $errno) {
                throw new \ErrorException($errstr, 0, $errno);
            }
            return true;
        });

        try {
            $result = $gateway->verify(['ORDERID' => 'ORD1']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('success', $result['status']);
    }

    public function test_status_calls_only_need_a_client_with_post()
    {
        // Guzzle 5's ClientInterface has post() but no request().
        $client = new class {
            public $calls = [];

            public function post($url, array $options = [])
            {
                $this->calls[] = [$url, $options];

                return new Response(200, [], json_encode(['MID' => 'PAYTM_MID', 'ORDERID' => 'ORD1', 'TXNAMOUNT' => '100.00', 'STATUS' => 'TXN_SUCCESS']));
            }
        };

        $result = (new PaytmGateway($client))->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('https://securegw-stage.paytm.in/order/status', $client->calls[0][0]);
        $this->assertSame(['Content-Type' => 'application/json'], $client->calls[0][1]['headers']);
        $this->assertStringStartsWith('JsonData=', $client->calls[0][1]['body']);
    }

    public function test_verify_with_an_amount_raises_no_deprecation()
    {
        $gateway = new PaytmGateway($this->statusClient($this->successReply()));

        $messages = $this->deprecations(function () use ($gateway) {
            return $gateway->verify(['ORDERID' => 'ORD1', 'amount' => '100.00']);
        });

        $this->assertSame([], $messages);
    }

    public function test_verify_without_an_amount_still_checks_the_order_id()
    {
        $gateway = new PaytmGateway($this->statusClient($this->successReply(['ORDERID' => 'ORD2'])));

        $this->deprecations(function () use ($gateway) {
            return $gateway->verify(['ORDERID' => 'ORD1']);
        }, $result);

        $this->assertSame('failure', $result['status']);
    }
}
