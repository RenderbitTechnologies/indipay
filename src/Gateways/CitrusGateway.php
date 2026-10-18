<?php namespace Softon\Indipay\Gateways;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class CitrusGateway implements PaymentGatewayInterface {

    protected $parameters = array();
    protected $defaultParameters = array();
    protected $testMode = false;
    protected $secretKey = '';
    protected $hash = '';
    protected $vanityUrl = '';
    protected $strictCallbackFields = false;
    protected $liveEndPoint = 'https://www.citruspay.com/';
    protected $testEndPoint = 'https://sandbox.citruspay.com/';
    public $response = '';

    function __construct()
    {
        $this->vanityUrl = Config::get('indipay.citrus.vanityUrl');
        $this->secretKey = Config::get('indipay.citrus.secretKey');
        $this->strictCallbackFields = (bool) Config::get('indipay.citrus.strictCallbackFields', false);
        $this->testMode = Config::get('indipay.testMode');

        $this->parameters['merchantTxnId'] = $this->generateTransactionID();
        $this->parameters['currency'] = 'INR';
        $this->parameters['returnUrl'] = url(Config::get('indipay.citrus.returnUrl'));
        $this->parameters['notifyUrl'] = url(Config::get('indipay.citrus.notifyUrl'));

        $this->defaultParameters = $this->parameters;
    }

    public function getEndPoint()
    {
        return $this->testMode?$this->testEndPoint.$this->vanityUrl:$this->liveEndPoint.$this->vanityUrl;
    }

    public function request($parameters)
    {
        $this->parameters = array_merge($this->defaultParameters,$parameters);

        $this->checkParameters($this->parameters);

        $this->encrypt();

        return $this;

    }

    /**
     * @return mixed
     */
    public function send()
    {
        Log::info('Indipay Payment Request Initiated: ');
        return View::make('indipay::citrus')->with('hash',$this->hash)
                             ->with('parameters',$this->parameters)
                             ->with('endPoint',$this->getEndPoint());

    }


    /**
     * Check Response
     * @param $request
     * @return array
     */
    public function response($request)
    {
        $response = $request->all();

        $response_hash = $this->decrypt($response);

        if($response_hash === false){
            return 'Hash Mismatch Error';
        }

        $signature = isset($response['signature']) ? $response['signature'] : '';

        if(!is_string($signature) || !hash_equals($response_hash, $signature)){
            return 'Hash Mismatch Error';
        }

        return $response;
    }


    /**
     * @param $parameters
     * @throws IndipayParametersMissingException
     */
    public function checkParameters($parameters)
    {
        $validator = Validator::make($parameters, [
            'merchantTxnId' => 'required',
            'currency' => 'required',
            'returnUrl' => 'required|url',
            'orderAmount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            throw new IndipayParametersMissingException($validator->errors());
        }

    }

    /**
     * Citrus Encrypt Function
     *
     */
    protected function encrypt()
    {

        $hash_string = $this->vanityUrl.$this->parameters['orderAmount'].$this->parameters['merchantTxnId'].$this->parameters['currency'];

        $this->hash = hash_hmac('sha1', $hash_string, $this->secretKey);

    }

    /**
     * Citrus Decrypt Function
     *
     * @param $response
     * @return string|false false when strictCallbackFields is on and a signed field is missing
     */
    protected function decrypt($response)
    {
        $hash_vars = array('TxId', 'TxStatus', 'amount', 'pgTxnNo', 'issuerRefNo', 'authIdCode', 'firstName', 'lastName', 'pgRespCode', 'addressZip');

        $hash_string = '';
        foreach ($hash_vars as $hash_var) {
            if (!array_key_exists($hash_var, $response)) {
                // In strict mode every signed field must be present, even when its value is empty.
                if ($this->strictCallbackFields) {
                    return false;
                }
                continue;
            }
            $hash_string .= $response[$hash_var];
        }


        return hash_hmac('sha1', $hash_string, $this->secretKey);

    }



    public function generateTransactionID()
    {
        return substr(hash('sha256', mt_rand() . microtime()), 0, 20);
    }




}