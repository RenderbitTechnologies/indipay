<?php namespace Softon\Indipay\Gateways;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class CCAvenueGateway implements PaymentGatewayInterface {

    protected $parameters = array();
    protected $defaultParameters = array();
    protected $merchantData = '';
    protected $encRequest = '';
    protected $testMode = false;
    protected $workingKey = '';
    protected $accessCode = '';
    protected $liveEndPoint = 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction';
    protected $testEndPoint = 'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction';
    public $response = '';

    function __construct()
    {
        $this->workingKey = Config::get('indipay.ccavenue.workingKey');
        $this->accessCode = Config::get('indipay.ccavenue.accessCode');
        $this->testMode = Config::get('indipay.testMode');
        $this->parameters['merchant_id'] = Config::get('indipay.ccavenue.merchantId');
        $this->parameters['currency'] = Config::get('indipay.ccavenue.currency');
        $this->parameters['redirect_url'] = url(Config::get('indipay.ccavenue.redirectUrl'));
        $this->parameters['cancel_url'] = url(Config::get('indipay.ccavenue.cancelUrl'));
        $this->parameters['language'] = Config::get('indipay.ccavenue.language');

        $this->defaultParameters = $this->parameters;
    }

    public function getEndPoint()
    {
        return $this->testMode?$this->testEndPoint:$this->liveEndPoint;
    }

    public function request($parameters)
    {
        $this->parameters = array_merge($this->defaultParameters,$parameters);

        $this->checkParameters($this->parameters);

        $this->checkDelimiters($this->parameters);

        $this->merchantData = '';
        foreach($this->parameters as $key=>$value) {
            $this->merchantData .= $key.'='.$value.'&';
        }

        $this->encRequest = $this->encrypt($this->merchantData,$this->workingKey);

        return $this;

    }

    /**
     * @return mixed
     */
    public function send()
    {

        Log::info('Indipay Payment Request Initiated: ');
        return View::make('indipay::ccavenue')->with('encRequest',$this->encRequest)
                             ->with('accessCode',$this->accessCode)
                             ->with('endPoint',$this->getEndPoint());

    }


    /**
     * Check Response
     * @param $request
     * @return array|string 'Decryption Error' if encResp is missing, malformed or cannot be decrypted
     */
    public function response($request)
    {
        $encResponse = $request->encResp;

        if (!is_string($encResponse) || $encResponse === '' || strlen($encResponse) % 2 !== 0 || !ctype_xdigit($encResponse)) {
            return 'Decryption Error';
        }

        $rcvdString = $this->decrypt($encResponse,$this->workingKey);
        if ($rcvdString === false) {
            return 'Decryption Error';
        }
        $this->checkDuplicateKeys($rcvdString);
        parse_str($rcvdString, $decResponse);

        return $decResponse;
    }


    /**
     * The merchant data is a raw key=value&key=value string, so a '&' or '='
     * inside a key, or a '&' inside a value, would inject extra fields. A '='
     * inside a value is harmless (e.g. a redirect_url with a query string).
     * Reject rather than encode, as CCAvenue is not known to decode
     * urlencoded values.
     * @param $parameters
     * @throws IndipayParametersMissingException
     */
    protected function checkDelimiters($parameters)
    {
        foreach ($parameters as $key => $value) {
            if (strpbrk((string) $key, '&=') !== false || strpos((string) $value, '&') !== false) {
                throw new IndipayParametersMissingException("Parameter '$key' must not contain '&' (or '=' in its name).");
            }
        }
    }

    /**
     * parse_str() keeps the last duplicate, so a repeated key in a response
     * could override a field such as order_status. Treat it as tampering.
     * @param $rcvdString
     * @throws IndipayParametersMissingException
     */
    protected function checkDuplicateKeys($rcvdString)
    {
        $seen = array();
        foreach (explode('&', (string) $rcvdString) as $pair) {
            // Normalise the name the way parse_str() will (url-decoding, '.' -> '_').
            parse_str($pair, $parsed);
            $key = key($parsed);
            if ($key === null) {
                continue;
            }
            if (isset($seen[$key])) {
                throw new IndipayParametersMissingException("Duplicate field '$key' in gateway response.");
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param $parameters
     * @throws IndipayParametersMissingException
     */
    public function checkParameters($parameters)
    {
        $validator = Validator::make($parameters, [
            'merchant_id' => 'required',
            'currency' => 'required',
            'redirect_url' => 'required|url',
            'cancel_url' => 'required|url',
            'language' => 'required',
            'order_id' => 'required',
            'amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            throw new IndipayParametersMissingException($validator->errors());
        }

    }
    
     /**
     * CCAvenue encryption
     * @param $plainText string
     * @param $key string
     * @return string
     */
    public function encrypt($plainText, $key)
    {
        $key = $this->hextobin(md5($key));
        $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
        $openMode = openssl_encrypt($plainText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
        $encryptedText = bin2hex($openMode);
        return $encryptedText;
    }
    

    /**
     * CCAvenue decryption
     * @param $encryptedText string
     * @param $key
     * @return string
     */
    public function decrypt($encryptedText, $key)
    {
        $key = $this->hextobin(md5($key));
        $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
        $encryptedText = hex2bin($encryptedText);
        $decryptedText = openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
        return $decryptedText;
    }
  


    /**
     * @param $plainText
     * @param $blockSize
     * @return string
     */
    protected function pkcs5_pad($plainText, $blockSize)
    {
        $pad = $blockSize - (strlen($plainText) % $blockSize);
        return $plainText . str_repeat(chr($pad), $pad);
    }


    /**
     * @param $hexString
     * @return string
     */
    protected function hextobin($hexString)
    {
        $length = strlen($hexString);
        $binString="";
        $count=0;
        while($count<$length)
        {
            $subString =substr($hexString,$count,2);
            $packedString = pack("H*",$subString);
            if ($count==0)
            {
                $binString=$packedString;
            }

            else
            {
                $binString.=$packedString;
            }

            $count+=2;
        }
        return $binString;
    }




}
