<?php

namespace Modules\Transaction\Traits;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\Transaction\Hesabe\HesabeClient;
use Modules\Transaction\Services\MyFatoorahPaymentService;
use Modules\Transaction\Services\TapPaymentService;
use Modules\Transaction\Services\UPaymentService;
use Modules\Transaction\Services\HesabeService;

trait PaymentTrait
{
    static function getPaymentGateway($payment)
    {
        if (in_array($payment, array_keys(config('setting.supported_payments') ?? []) )  && config('setting.supported_payments.' . $payment . '.status') == 'on') {

            switch ($payment) {
                case 'upayment':
                    return new UPaymentService();
                case 'tap':
                    return new TapPaymentService();
                case 'my_fatoorah':
                    return new MyFatoorahPaymentService();
                case 'hesabe':
                    $hesabeClient = new HesabeClient();
                    !$hesabeClient->isConfigured() ? $hesabeClient->configure(config('hesabe.MERCHANT_CODE'), config('hesabe.MERCHANT_IV'), config('hesabe.MERCHANT_SECRET_KEY'), config('hesabe.ACCESS_CODE'), config('hesabe.PAYMENT_API_URL')) : false;
                    return $hesabeClient;
                default:
                    return false;
            }
        }
        return false;
    }

    static function buildTapRequestData($data ,Request $request){

        $request->merge([
           'OrderID' => $data['metadata']['udf5'] ?? $data['reference']['order'],
           'Result' => isset($data['status']) ? $data['status'] : null,
           'Auth' => isset($data['transaction']['authorization_id']) ? $data['transaction']['authorization_id'] : null,
           'TranID' => isset($data['id']) ? $data['id'] : null,
           'PostDate' => isset($data['transaction']['created']) ? $data['transaction']['created'] : null,
           'Ref' => null,
           'TrackID' => isset($data['reference']['track']) ? $data['reference']['track'] : null,
           'PaymentID' => isset($data['reference']['payment']) ? $data['reference']['payment'] : null,
        ]);

        return $request;
    }


    static function buildHesabeRequestData($data ,Request $request){

        $request->merge([
           'OrderID'   => isset($data['response']['data']['orderReferenceNumber']) ?? $data['response']['data']['orderReferenceNumber'],
           'Result'    => isset($data['response']['data']['resultCode']) ? $data['response']['data']['resultCode'] : null,
           'Auth'      =>  null,
           'TranID'    => isset($data['response']['data']['paymentId']) ? $data['response']['data']['paymentId'] : null,
           'PostDate'  => isset($data['response']['data']) ? $data['response']['data'] : null,
           'Ref'       => isset($data['response']['data']['orderReferenceNumber']) ?? $data['response']['data']['orderReferenceNumber'],
           'TrackID'   => isset($data['response']['data']['paymentToken']) ? $data['response']['data']['paymentToken'] : null,
           'PaymentID' => isset($data['response']['data']['paymentId']) ? $data['response']['data']['paymentId'] : null,
        ]);

        return $request;
    }

    static function buildMyFatoorahRequestData($data ,Request $request){
        $data = (array)$data;
        $request->merge([
           'OrderID' => isset($data['CustomerReference']) ? $data['CustomerReference'] : null,
           'Result' => isset($data['InvoiceStatus']) && $data['InvoiceStatus'] == 'Paid' ? 'CAPTURED' : null
        ]);

        if(isset($data['InvoiceTransactions']) && isset($data['InvoiceTransactions'][0])){
            $InvoiceTransactions = $data['InvoiceTransactions'][0];
            $request->merge([
                'Auth' => $InvoiceTransactions->AuthorizationId ?? null,
                'TranID' => $InvoiceTransactions->TransactionId ?? null,
                'PostDate' => $InvoiceTransactions->TransactionDate ?
                    Carbon::parse($InvoiceTransactions->TransactionDate)->toDateTimeString() : null,
                'Ref' => $InvoiceTransactions->ReferenceId ?? null,
                'TrackID' => $InvoiceTransactions->TrackId ?? null,
                'PaymentID' => $InvoiceTransactions->PaymentId ?? null,
             ]);
        }

        return $request;
    }
}
