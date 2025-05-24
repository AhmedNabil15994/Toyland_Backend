<?php

namespace Modules\Transaction\Services;

class HesabeService
{

    private $secretKey  = 'XZx9dzjrPM748jDjDE9apOkbVYyBALGR';
    private $ivKey      = 'PM748jDjDE9apOkb';
    private $accessCode = 'cfe713f8-bb26-4871-8024-53283eeb48e7';
    private $testEnv    = true;
    private $merchantCode = '50881422';
    protected $paymentMode = 'test_mode';

    /**
     * Payment constructor.
     *
     * @param $secretKey
     * @param $ivKey
     * @param $accessCode
     * @param $testEnv
     */
    public function __construct()
    {
        if (config('setting.supported_payments.hesabe.payment_mode') == 'live_mode') {
            $this->paymentMode = 'live_mode';
            $this->testEnv     = false;
        }
        $this->secretKey = config('setting.supported_payments.hesabe.' . $this->paymentMode . '.secretKey');
        $this->ivKey     = config('setting.supported_payments.hesabe.' . $this->paymentMode . '.ivKey');
        $this->accessCode = config('setting.supported_payments.hesabe.' . $this->paymentMode . '.accessCode');
        $this->merchantCode = config('setting.supported_payments.hesabe.' . $this->paymentMode . '.merchantCode');
    }

    public function send($order,$payment, $type = 'api-order')
    {
        $url = $this->paymentUrls($type);
        if (auth()->check()) {
            $user = [
                'name' => auth()->user()->name ?? '',
                'email' => auth()->user()->email ?? '',
                'mobile' => auth()->user()->calling_code ?? '' . auth()->user()->mobile ?? '',
            ];
        } else {
            $user = [
                'name' => 'Guest User',
                'email' => 'test@test.com',
                'mobile' => '12345678',
            ];
        }
        $params = [
            "merchantCode" => $this->merchantCode,
            "amount" => $order['total'],
            'currency' => 'KWD',
            "paymentType" => "1", // 0 => Indirect,1=>KNET,2=>MPGS
            "responseUrl" => $url['success'],
            "failureUrl" => $url['failed'],
            "orderReferenceNumber" => $order['id'],
            "variable1" => null,
            "variable2" => null,
            "variable3" => null,
            "variable4" => null,
            "variable5" => null,
            "version" => "2.0",
            'name' => $user['name'],
            'mobile_number' => $user['mobile'],
            'email' => $user['email'],
        ];
        $encryptedData = self::encrypt(json_encode($params), $this->secretKey, $this->ivKey);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->getRedirectBaseUrl()."/checkout",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => array('data' => $encryptedData),
            CURLOPT_HTTPHEADER => array(
                "accessCode: $this->accessCode",
                "Accept: application/json"
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $decryptedResponse = self::decrypt($response, $this->secretKey, $this->ivKey);
        $jsonData = json_decode($decryptedResponse, true);

        if (isset($jsonData['status']) && !$jsonData['status']) {
            return ['status' => false];
        }

        $token = isset($jsonData['response']['data'])? $jsonData['response']['data'] : null;
        if(!$token){
            return route('frontend.orders.failed');
        }
        $baseUrl = $this->getRedirectBaseUrl();
        return ['status' => true, 'url' => $baseUrl.'/payment?data='.$token ];
    }

    private function getRedirectBaseUrl(): string
    {
        $endpoints = [
            true => 'https://sandbox.hesabe.com',
            false => 'https://api.hesabe.com'
        ];

        return $endpoints[$this->testEnv];
    }

    public function paymentUrls($type)
    {
        if($type == 'api-order'){

            $url['success'] = route('api.orders.success');
            $url['failed'] = route('api.orders.failed');
        }else{

            $url['success'] = url(route('frontend.orders.success'));
            $url['failed'] = url(route('frontend.orders.failed'));
        }

        return $url;
    }

/************ Response Example ****************/
    // {
    //     "status": true,
    //     "code": 200,
    //     "message": "Transaction Success",
    //     "response": {
    //         "data": {
    //             "resultCode": "CAPTURED",
    //             "amount": 10,
    //             "paymentToken": "1569830677725743478",
    //             "paymentId": "100201927384634224",
    //             "paidOn": "2019-09-30 11:05:16",
    //             "orderReferenceNumber": null,
    //             "variable1": null,
    //             "variable2": null,
    //             "variable3": null,
    //             "variable4": null,
    //             "variable5": null,
    //             "method": 1,
    //             "administrativeCharge": "5"
    //         }
    //     }
    // }

    public function getTransactionDetails($request)
    {
        $responseData  = $request->all();
        $responseData  = json_decode( $responseData, true );
        try {
            $status         = isset($responseData[ 'status' ] ) ? $responseData[ 'status' ]  : false;
            if($status == false){
                return [ 'server_response' => 'error'];
            }
            return json_decode($responseData, true);
        } catch (\Exception $e) {
            return [
                'server_response' => 'error',
            ];
        }
    }

     /**
     * AES Encryption Method
     * @param $str
     * @param $key
     * @param $ivKey
     * @return string
     */
    public static function encrypt($str, $key, $ivKey): string
    {
        $str = self::pkcs5Pad($str);
        $encrypted = openssl_encrypt($str, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $ivKey);
        $encrypted = base64_decode($encrypted);
        $encrypted = unpack('C*', $encrypted);
        $encrypted = self::byteArray2Hex($encrypted);
        return urlencode($encrypted);
    }

    /**
     * Decryption Method for AES Algorithm
     * @param $code
     * @param $key
     * @param $ivKey
     * @return false|string
     */
    public static function decrypt($code, $key, $ivKey)
    {
        if (!(ctype_xdigit($code) && strlen($code) % 2 === 0)) {
            return false;
        }
        $code = self::hex2ByteArray(trim($code));
        $code = self::byteArray2String($code);
        $code = base64_encode($code);
        $decrypted = openssl_decrypt($code, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $ivKey);
        return self::pkcs5Unpad($decrypted);
    }

    private static function pkcs5Pad($text): string
    {
        $blockSize = 32;
        $pad = $blockSize - (strlen($text) % $blockSize);
        return $text . str_repeat(chr($pad), $pad);
    }

    private static function byteArray2Hex($byteArray): string
    {
        $chars = array_map("chr", $byteArray);
        $bin = implode($chars);
        return bin2hex($bin);
    }

    private static function hex2ByteArray($hexString)
    {
        $string = hex2bin($hexString);
        return unpack('C*', $string);
    }

    private static function byteArray2String($byteArray): string
    {
        $chars = array_map("chr", $byteArray);
        return implode($chars);
    }

    private static function pkcs5Unpad($text)
    {
        $pad = ord($text[strlen($text) - 1]);
        if ($pad > strlen($text)) {
            return false;
        }
        if (strspn($text, chr($pad), strlen($text) - $pad) !== $pad) {
            return false;
        }
        return substr($text, 0, -1 * $pad);
    }
}

