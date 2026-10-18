<?php namespace Softon\Indipay\Gateways;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class EBSGateway implements PaymentGatewayInterface {

    protected $parameters = array();
    protected $testMode = false;
    protected $secretKey = '';
    protected $hash = '';
    protected $hashAlgorithm = 'md5';
    protected $endPoint = 'https://secure.ebs.in/pg/ma/payment/request';
    public $response = '';

    /**
     * Hash algorithms a payment page can be set to on EBS.
     */
    const HASH_ALGORITHMS = ['md5', 'sha1', 'sha512'];

    function __construct()
    {
        $this->secretKey = Config::get('indipay.ebs.secretKey');
        $this->hashAlgorithm = $this->resolveHashAlgorithm(Config::get('indipay.ebs.hashAlgorithm') ?? 'md5');
        $this->testMode = Config::get('indipay.testMode');

        $this->parameters['channel'] = 0;       // Standard
        $this->parameters['account_id'] = Config::get('indipay.ebs.account_id');
        $this->parameters['reference_no'] = $this->generateTransactionID();
        $this->parameters['currency'] = 'INR';
        $this->parameters['mode'] = 'LIVE';
        if($this->testMode){
            $this->parameters['mode'] = 'TEST';
        }
        $this->parameters['return_url'] = url(Config::get('indipay.ebs.return_url'));


    }

    public function getEndPoint()
    {
        return $this->endPoint;
    }

    public function request($parameters)
    {
        $this->parameters = array_merge($this->parameters,$parameters);

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
        return View::make('indipay::ebs')->with('hash',$this->hash)
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

        // Hash only the posted fields: query parameters on the return URL are not part of EBS's hash.
        $posted = $request->post();

        $response_hash = $this->decrypt($posted);

        $hash = isset($posted['SecureHash']) ? $posted['SecureHash'] : '';

        if($response_hash === false || !is_string($hash) || !hash_equals($response_hash, strtoupper($hash))){
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
            'channel' => 'required',
            'account_id' => 'required',
            'reference_no' => 'required',
            'mode' => 'required',
            'currency' => 'required',
            'description' => 'required',
            'return_url' => 'required|url',
            'name' => 'required',
            'address' => 'required',
            'city' => 'required',
            'country' => 'required',
            'postal_code' => 'required',
            'phone' => 'required',
            'email' => 'required|email',
            'amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            throw new IndipayParametersMissingException($validator->errors());
        }

    }

    /**
     * EBS Encrypt Function
     *
     */
    protected function encrypt()
    {
        $this->hash = '';
        $hash_string = $this->secretKey."|".urlencode($this->parameters['account_id'])."|".urlencode($this->parameters['amount'])."|".urlencode($this->parameters['reference_no'])."|".$this->parameters['return_url']."|".urlencode($this->parameters['mode']);
        $this->hash = hash($this->hashAlgorithm, $hash_string);
    }

    /**
     * EBS Response Hash Function
     *
     * Builds the expected SecureHash: the upper-case hash of the secret key followed by
     * every non-empty response field except SecureHash, in field name order, joined by '|'.
     * The hash uses the algorithm configured in indipay.ebs.hashAlgorithm.
     *
     * @param array $response
     * @return string|false false when a field value is not a string
     */
    protected function decrypt($response)
    {
        unset($response['SecureHash']);
        ksort($response);

        $hash_string = $this->secretKey;
        foreach ($response as $value) {
            if (!is_scalar($value)) {
                return false;
            }
            if ((string) $value !== '') {
                $hash_string .= '|'.$value;
            }
        }

        return strtoupper(hash($this->hashAlgorithm, $hash_string));
    }



    /**
     * @param mixed $algorithm
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function resolveHashAlgorithm($algorithm)
    {
        $normalized = is_string($algorithm) ? strtolower(trim($algorithm)) : null;

        if (!in_array($normalized, self::HASH_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported indipay.ebs.hashAlgorithm value. Use one of: '.implode(', ', self::HASH_ALGORITHMS).'.'
            );
        }

        return $normalized;
    }

    public function generateTransactionID()
    {
        return substr(hash('sha256', mt_rand() . microtime()), 0, 20);
    }




}