<?php namespace Softon\Indipay\Gateways;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Softon\Indipay\Exceptions\IndipayParametersMissingException;

class PaytmGateway implements PaymentGatewayInterface, VerifiesPayments {

    protected $parameters = array();
    protected $defaultParameters = array();
    protected $merchantData = '';
    protected $checksum = '';
    protected $testMode = false;
    protected $MERCHANT_KEY = '';
    protected $liveEndPoint = 'https://securegw.paytm.in/order/process';
    protected $testEndPoint = 'https://securegw-stage.paytm.in/order/process';
    protected $statusLiveEndPoint = 'https://securegw.paytm.in/order/status';
    protected $statusTestEndPoint = 'https://securegw-stage.paytm.in/order/status';
    public $response = '';
    protected $client;

    /**
     * @param \GuzzleHttp\ClientInterface|null $client HTTP client for the status API
     */
    function __construct($client = null)
    {
        $this->client = $client;
        $this->MERCHANT_KEY = Config::get('indipay.paytm.MERCHANT_KEY');
        $this->testMode = Config::get('indipay.testMode');
        $this->parameters['MID'] = Config::get('indipay.paytm.MID');
        $this->parameters['CHANNEL_ID'] = Config::get('indipay.paytm.CHANNEL_ID');
        $this->parameters['CALLBACK_URL'] = url(Config::get('indipay.paytm.REDIRECT_URL'));
        $this->parameters['WEBSITE'] = Config::get('indipay.paytm.WEBSITE');
        $this->parameters['INDUSTRY_TYPE_ID'] = Config::get('indipay.paytm.INDUSTRY_TYPE_ID');

        $this->defaultParameters = $this->parameters;
    }

    public function getEndPoint()
    {
        return $this->testMode?$this->testEndPoint:$this->liveEndPoint;
    }

    public function getStatusEndPoint()
    {
        return $this->testMode?$this->statusTestEndPoint:$this->statusLiveEndPoint;
    }

    public function request($parameters)
    {
        $this->parameters = array_merge($this->defaultParameters,$parameters);
        
        $this->checksum = $this->getChecksumFromArray($this->parameters,$this->MERCHANT_KEY);
        
        $this->parameters['CHECKSUMHASH'] = $this->checksum;
        
        $this->checkParameters($this->parameters);

        return $this;

    }

    /**
     * @return mixed
     */
    public function send()
    {

        Log::info('Indipay Payment Request Initiated: ');
        return View::make('indipay::paytm')->with('params',$this->parameters)
                             ->with('checksum',$this->checksum)
                             ->with('endPoint',$this->getEndPoint());

    }


    /**
     * Check Response
     * @param $request
     * @return array
     */
    public function response($request)
    {
        $params = $request->all();
        $checksum = isset($request->CHECKSUMHASH) ? $request->CHECKSUMHASH : "";

        $isValidChecksum = $this->verifychecksum_e($params,$this->MERCHANT_KEY,$checksum);

        if($isValidChecksum == "TRUE" && $request->STATUS == "TXN_SUCCESS"){
            $params['status'] = "success";
            return $params;
        }
        $params['status'] = "failure";
        return $params;
    }

    /**
     * Ask Paytm for an order's status.
     * @param $parameters ORDERID, and the expected amount
     * @return array|false
     */
    public function verify($parameters){
        if(!isset($parameters['ORDERID'])){
            return false;
        }
        if(!isset($parameters['amount'])){
            // Silenced so Laravel versions before 9 do not turn it into an ErrorException.
            @trigger_error('Calling PaytmGateway::verify() without an expected "amount" is deprecated; the amount will be required in the next major version.', E_USER_DEPRECATED);
        }
        $requestParamList = array("MID" => $this->parameters['MID'] , "ORDERID" => $parameters['ORDERID']);  
        $requestParamList['CHECKSUMHASH'] = $this->getChecksumFromArray($requestParamList,$this->MERCHANT_KEY);
        $responseParamList = $this->getTxnStatusNew($requestParamList);
        if(!is_array($responseParamList) || !isset($responseParamList['STATUS'])){
            return ['status' => 'failure'];
        }
        if($responseParamList['STATUS'] == "TXN_SUCCESS"
            && isset($responseParamList['ORDERID'])
            && (string) $responseParamList['ORDERID'] === (string) $parameters['ORDERID']
            && (!isset($responseParamList['MID']) || (string) $responseParamList['MID'] === (string) $this->parameters['MID'])
            && $this->amountMatches($responseParamList, $parameters)){
            $responseParamList['status'] = "success";
            return $responseParamList;
        }
        $responseParamList['status'] = "failure";
        return $responseParamList;
    }

    /**
     * Compare the reply's TXNAMOUNT with the expected amount.
     * Without an expected amount any amount passes, which is deprecated.
     */
    protected function amountMatches($responseParamList, $parameters)
    {
        if(!isset($parameters['amount'])){
            return true;
        }
        return isset($responseParamList['TXNAMOUNT']) && is_numeric($responseParamList['TXNAMOUNT'])
            && is_numeric($parameters['amount'])
            && (float) $responseParamList['TXNAMOUNT'] == (float) $parameters['amount'];
    }


    /**
     * @param $parameters
     * @throws IndipayParametersMissingException
     */
    public function checkParameters($parameters)
    
    {
        $validator = Validator::make($parameters, [
            'MID' => 'required',
            'ORDER_ID' => 'required',
            'CUST_ID' => 'required',
            'CHANNEL_ID' => 'required',
            'WEBSITE' => 'required',
            'INDUSTRY_TYPE_ID' => 'required',
            'CALLBACK_URL' => 'required|url',
            'TXN_AMOUNT' => 'required|numeric',
            'CHECKSUMHASH' => 'required',
        ]);

        if ($validator->fails()) {
            throw new IndipayParametersMissingException($validator->errors());
        }

    }
    
    

    /**
     * Paytm Gateway Functions
     */


    function encrypt_e($input, $ky) {
        $key   = html_entity_decode($ky);
        $iv = "@@@@&&&&####$$$$";
        $data = openssl_encrypt ( $input , "AES-128-CBC" , $key, 0, $iv );
        return $data;
    }

    function decrypt_e($crypt, $ky) {
        $key   = html_entity_decode($ky);
        $iv = "@@@@&&&&####$$$$";
        $data = openssl_decrypt ( $crypt , "AES-128-CBC" , $key, 0, $iv );
        return $data;
    }

    function generateSalt_e($length) {
        $random = "";

        $data = "AbcDE123IJKLMN67QRSTUVWXYZ";
        $data .= "aBCdefghijklmn123opq45rs67tuv89wxyz";
        $data .= "0FGH45OP89";

        for ($i = 0; $i < $length; $i++) {
            $random .= substr($data, (rand() % (strlen($data))), 1);
        }

        return $random;
    }

    function checkString_e($value) {
        if ($value == 'null')
            $value = '';
        return $value;
    }

    function getChecksumFromArray($arrayList, $key, $sort=1) {
        if ($sort != 0) {
            ksort($arrayList);
        }
        $str = $this->getArray2Str($arrayList);
        $salt = $this->generateSalt_e(4);
        $finalString = $str . "|" . $salt;
        $hash = hash("sha256", $finalString);
        $hashString = $hash . $salt;
        $checksum = $this->encrypt_e($hashString, $key);
        return $checksum;
    }
    function getChecksumFromString($str, $key) {
        
        $salt = $this->generateSalt_e(4);
        $finalString = $str . "|" . $salt;
        $hash = hash("sha256", $finalString);
        $hashString = $hash . $salt;
        $checksum = $this->encrypt_e($hashString, $key);
        return $checksum;
    }

    function verifychecksum_e($arrayList, $key, $checksumvalue) {
        $arrayList = $this->removeCheckSumParam($arrayList);
        ksort($arrayList);
        $str = $this->getArray2StrForVerify($arrayList);
        $paytm_hash = $this->decrypt_e($checksumvalue, $key);
        $salt = substr($paytm_hash, -4);

        $finalString = $str . "|" . $salt;

        $website_hash = hash("sha256", $finalString);
        $website_hash .= $salt;

        $validFlag = "FALSE";
        if ($website_hash == $paytm_hash) {
            $validFlag = "TRUE";
        } else {
            $validFlag = "FALSE";
        }
        return $validFlag;
    }

    function verifychecksum_eFromStr($str, $key, $checksumvalue) {
        $paytm_hash = $this->decrypt_e($checksumvalue, $key);
        $salt = substr($paytm_hash, -4);

        $finalString = $str . "|" . $salt;

        $website_hash = hash("sha256", $finalString);
        $website_hash .= $salt;

        $validFlag = "FALSE";
        if ($website_hash == $paytm_hash) {
            $validFlag = "TRUE";
        } else {
            $validFlag = "FALSE";
        }
        return $validFlag;
    }

    function getArray2Str($arrayList) {
        $findme   = 'REFUND';
        $findmepipe = '|';
        $paramStr = "";
        $flag = 1;	
        foreach ($arrayList as $key => $value) {
            $pos = strpos($value, $findme);
            $pospipe = strpos($value, $findmepipe);
            if ($pos !== false || $pospipe !== false) 
            {
                continue;
            }
            
            if ($flag) {
                $paramStr .= $this->checkString_e($value);
                $flag = 0;
            } else {
                $paramStr .= "|" . $this->checkString_e($value);
            }
        }
        return $paramStr;
    }

    function getArray2StrForVerify($arrayList) {
        $paramStr = "";
        $flag = 1;
        foreach ($arrayList as $key => $value) {
            if ($flag) {
                $paramStr .= $this->checkString_e($value);
                $flag = 0;
            } else {
                $paramStr .= "|" . $this->checkString_e($value);
            }
        }
        return $paramStr;
    }

    function redirect2PG($paramList, $key) {
        $hashString = $this->getchecksumFromArray($paramList, $key);
        $checksum = $this->encrypt_e($hashString, $key);
    }

    function removeCheckSumParam($arrayList) {
        if (isset($arrayList["CHECKSUMHASH"])) {
            unset($arrayList["CHECKSUMHASH"]);
        }
        return $arrayList;
    }

    function getTxnStatus($requestParamList) {
        return $this->callAPI($this->getStatusEndPoint(), $requestParamList);
    }

    function getTxnStatusNew($requestParamList) {
        return $this->callNewAPI($this->getStatusEndPoint(), $requestParamList);
    }

    function initiateTxnRefund($requestParamList) {
        //$CHECKSUM = $this->getRefundChecksumFromArray($requestParamList,PAYTM_MERCHANT_KEY,0);
        //$requestParamList["CHECKSUM"] = $CHECKSUM;
        //return $this->callAPI(PAYTM_REFUND_URL, $requestParamList);
    }

    function callAPI($apiURL, $requestParamList) {
        return $this->postJsonData($apiURL, $requestParamList);
    }

    function callNewAPI($apiURL, $requestParamList) {
        return $this->postJsonData($apiURL, $requestParamList);
    }
    function getRefundChecksumFromArray($arrayList, $key, $sort=1) {
        if ($sort != 0) {
            ksort($arrayList);
        }
        $str = $this->getRefundArray2Str($arrayList);
        $salt = $this->generateSalt_e(4);
        $finalString = $str . "|" . $salt;
        $hash = hash("sha256", $finalString);
        $hashString = $hash . $salt;
        $checksum = $this->encrypt_e($hashString, $key);
        return $checksum;
    }
    function getRefundArray2Str($arrayList) {	
        $findmepipe = '|';
        $paramStr = "";
        $flag = 1;	
        foreach ($arrayList as $key => $value) {		
            $pospipe = strpos($value, $findmepipe);
            if ($pospipe !== false) 
            {
                continue;
            }
            
            if ($flag) {
                $paramStr .= $this->checkString_e($value);
                $flag = 0;
            } else {
                $paramStr .= "|" . $this->checkString_e($value);
            }
        }
        return $paramStr;
    }
    function callRefundAPI($refundApiURL, $requestParamList) {
        return $this->postJsonData($refundApiURL, $requestParamList);
    }

    /**
     * POST the parameters as JsonData and decode the JSON reply.
     * Returns null when the call fails or the reply is not a 200.
     */
    protected function postJsonData($apiURL, $requestParamList) {
        $postData = 'JsonData='.urlencode(json_encode($requestParamList));
        $client = $this->client ?: new \GuzzleHttp\Client();
        try {
            // post() exists on Guzzle 5's interface and on Guzzle 6/7's Client.
            $res = $client->post($apiURL, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $postData,
            ]);
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            Log::warning('Indipay Paytm API call failed: '.$e->getMessage());
            return null;
        }
        if($res->getStatusCode() != 200){
            Log::warning('Indipay Paytm API call returned HTTP '.$res->getStatusCode());
            return null;
        }
        return json_decode((string) $res->getBody(), true);
    }



}