<?php namespace Softon\Indipay\Gateways;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Exception\RequestException;
use Softon\Indipay\Exceptions\IndipayGatewayException;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class InstaMojoGateway implements PaymentGatewayInterface {

    protected $parameters = array();
    protected $defaultParameters = array();
    protected $testMode = false;
    protected $api_key = '';
    protected $auth_token = '';
    protected $liveEndPoint = 'https://www.instamojo.com/api/1.1/';
    protected $testEndPoint = 'https://test.instamojo.com/api/1.1/';
    public $response = '';
    protected $client;

    /**
     * @param \GuzzleHttp\ClientInterface|null $client HTTP client for the InstaMojo API
     */
    function __construct($client = null)
    {
        $this->client = $client;
        $this->testMode = Config::get('indipay.testMode');
        $this->api_key = Config::get('indipay.instamojo.api_key');
        $this->auth_token = Config::get('indipay.instamojo.auth_token');

        $this->parameters['redirect_url'] = url(Config::get('indipay.instamojo.redirectUrl'));

        $this->defaultParameters = $this->parameters;
    }

    public function getEndPoint($param='')
    {
        $endpoint = $this->testMode?$this->testEndPoint:$this->liveEndPoint;
        return $endpoint.$param;
    }

    public function request($parameters)
    {
        $this->parameters = array_merge($this->defaultParameters,$parameters);

        $this->checkParameters($this->parameters);

        $this->response = '';

        $client = $this->client ?: new \GuzzleHttp\Client();
        try {
            $body = $client->post($this->getEndPoint('payment-requests/'),
                                        [
                                            'headers'=> array(
                                                'X-Api-Key' => $this->api_key,
                                                'X-Auth-Token' => $this->auth_token,
                                            ),
                                            'form_params' => $this->parameters,
                                        ])->getBody()->getContents();
        } catch (RequestException $e) {
            $message = $e->hasResponse() ? $this->errorMessage(json_decode($e->getResponse()->getBody()->getContents())) : null;
            throw new IndipayGatewayException(
                'InstaMojo payment request failed: '.($message ?: $e->getMessage()), 0, $e
            );
        } catch (TransferException $e) {
            throw new IndipayGatewayException('InstaMojo payment request failed: '.$e->getMessage(), 0, $e);
        }

        $response = json_decode($body);

        if(!is_object($response)){
            throw new IndipayGatewayException('InstaMojo returned an invalid response.');
        }

        if(empty($response->success) || !isset($response->payment_request->longurl)){
            throw new IndipayGatewayException(
                'InstaMojo payment request failed: '.($this->errorMessage($response) ?: 'unexpected response.')
            );
        }

        $this->response = $response;

        return $this;

    }

    /**
     * @return mixed
     */
    public function send()
    {

        Log::info('Indipay Payment Request Initiated: ');
        //dd($this->response->payment_request->longurl);
        return View::make('indipay::instamojo')->with('longurl',$this->response->payment_request->longurl);

    }


    /**
     * Check Response
     * @param $request
     * @return array
     */
    public function response($request)
    {
        $payment_request_id = $request->input('payment_request_id');
        $payment_id = $request->input('payment_id');

        // Both IDs go into the API URL path, so anything that is not a plain
        // alphanumeric ID (dot segments, '?', '#', '/') is rejected.
        if (!$this->isValidId($payment_request_id) || !$this->isValidId($payment_id)) {
            return false;
        }

        $client = $this->client ?: new \GuzzleHttp\Client();
        try {
            $body = $client->get($this->getEndPoint('payment-requests/'.rawurlencode($payment_request_id).'/'.rawurlencode($payment_id).'/'),
                [
                    'headers'=> array(
                        'X-Api-Key' => $this->api_key,
                        'X-Auth-Token' => $this->auth_token,
                    ),
                ])->getBody()->getContents();
        } catch (TransferException $e) {
            Log::error('Indipay InstaMojo response check failed: '.$e->getMessage());
            return false;
        }

        $response = json_decode($body);

        if(is_object($response) && !empty($response->success)){
            return $response;
        }

        return false;

    }

    /**
     * Flatten the error InstaMojo sends back into a single message.
     * @param mixed $response decoded reply
     * @return string
     */
    protected function errorMessage($response)
    {
        if(!is_object($response)){
            return '';
        }
        $message = isset($response->message) ? $response->message : '';
        if(is_object($message) || is_array($message)){
            $parts = [];
            foreach ((array) $message as $field => $errors) {
                $parts[] = $field.': '.implode(', ', (array) $errors);
            }
            return implode('; ', $parts);
        }
        return (string) $message;
    }


    /**
     * @param mixed $id
     * @return bool
     */
    protected function isValidId($id)
    {
        return is_string($id) && preg_match('/\A[A-Za-z0-9]+\z/', $id) === 1;
    }

    /**
     * @param $parameters
     * @throws IndipayParametersMissingException
     */
    public function checkParameters($parameters)
    {
        $validator = Validator::make($parameters, [
            'purpose' => 'required|max:30',
            'amount' => 'required|numeric|between:9,200000',
            'buyer_name' => 'max:100',
            'email' => 'email|max:75',
            'phone' => 'digits:10',
        ]);

        if ($validator->fails()) {
            throw new IndipayParametersMissingException($validator->errors());
        }

    }




}
