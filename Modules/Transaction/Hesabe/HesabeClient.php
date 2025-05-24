<?php

namespace Modules\Transaction\Hesabe;

use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
//use Modules\Transaction\Hesabe\HesabeCrypt;
use Illuminate\Support\Facades\Log;
use Modules\Transaction\Hesabe\Helpers\ModelBindingHelper;
use Psr\Http\Message\RequestInterface;

class HesabeClient
{
    protected $apiKey;
    protected $apiSecret;
    protected $accessCode;
    protected $merchantCode;
    protected $configured = false;
    protected $hesabeCrypt;
    protected $client;
    protected $baseUrl;
    protected $modelBindingHelper;

    public function configure($merchantCode, $apiKey, $apiSecret, $accessCode, $apiUrl, $options = array())
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = $apiUrl;
        $this->accessCode = $accessCode;
        $this->merchantCode = $merchantCode;
        $this->hesabeCrypt = new HesabeCrypt();
        $this->modelBindingHelper = new ModelBindingHelper();

        if (empty($options['handler'])) {
            $handlerStack = HandlerStack::create();
        } else {
            $handlerStack = $options['handler'];
        }

        $this->client = new \GuzzleHttp\Client([
            'base_uri' => $apiUrl,
            'handler' => $handlerStack

        ]);

        $this->configured = true;

    }

    public function isConfigured()
    {
        return $this->configured;
    }

    private function createAuthHeader()
    {
        $headers = array(
            'Content-Type' => 'application/json',
            'accept' => 'application/json',
            'accessCode' => $this->accessCode,
        );
        return $headers;
    }

    public function send($requestBody, $payment, $type = 'api-order')
    {
        // set up the request
        $request = new Request('POST', '/checkout');
        $archiveJson = '';
        try {
            $requestData = $this->getCheckoutRequestData($requestBody, $type);
            $response = $this->client->send($request, [
                'verify' => false,
                'headers' => $this->createAuthHeader(),
                'json' => ['data' => $this->hesabeCrypt::encrypt(json_encode($requestData), $this->apiSecret, $this->apiKey)]
            ]);

            //Get encrypted and decrypted checkout data response
            $hesabeCheckoutResponseModel = $this->getCheckoutResponse($response->getBody()->getContents());
            $url = $this->baseUrl . '/payment?data=' . $hesabeCheckoutResponseModel->response['data'];
            $archiveJson = [
                'status' => $hesabeCheckoutResponseModel->status,
                'url' => $url
            ];
        } catch (\Exception $e) {
            $archiveJson = [
                'status' => false
            ];
        }
        return $archiveJson;
    }

    /**
     * Process the response after the form data has been requested.
     * @param
     * @return array De-serialize the decrypted response
     *
     */
    protected function getCheckoutResponse($response)
    {
        // Decrypt the response from the checkout API
        $decryptResponse = $this->hesabeCrypt::decrypt($response, $this->apiSecret, $this->apiKey);

        if (!$decryptResponse) {
            $decryptResponse = $response;
        }

        // De-serialize the JSON string into an object
        $decryptResponseData = json_decode($decryptResponse, true);

        //Binding the decrypted response data to the entity model
        $decryptedResponse = $this->modelBindingHelper->getCheckoutResponseData($decryptResponseData);

        //return encrypted and decrypted data
        return $decryptedResponse;
    }

    protected function getCheckoutRequestData($data, $type)
    {
        $url = $this->paymentUrls($type);
        $params = [
            "merchantCode" => $this->merchantCode,
            "amount" => $data['total'],
            'currency' => 'KWD',
            "paymentType" => "1", // 0 => Indirect,1=>KNET,2=>MPGS
            "responseUrl" => $url['success'],
            "failureUrl" => $url['failed'],
            "orderReferenceNumber" => $data['id'],
            "variable1" => null,
            "variable2" => null,
            "variable3" => null,
            "variable4" => null,
            "variable5" => null,
            "version" => config('hesabe.HESABE_VERSION'),
            'name' => auth()->check() ? auth()->user()->name : \request('name'),
            'mobile_number' => auth()->check() ? auth()->user()->calling_code . auth()->user()->mobile : \request('phone'),
            'email' => auth()->check() ? auth()->user()->email : (\request('email') ? \request('email') : 'info@toylandkw.com'),
        ];

        return $this->modelBindingHelper->getCheckoutRequestData($params);
    }

    public function paymentUrls($type)
    {
        if ($type == 'api-order') {
            $url['success'] = route('api.orders.success.hesabe-payment');
            $url['failed'] = route('api.orders.failed.hesabe-payment');
        } else {

            $url['success'] = url(route('frontend.orders.success'));
            $url['failed'] = url(route('frontend.orders.failed'));
        }

        return $url;
    }
}
