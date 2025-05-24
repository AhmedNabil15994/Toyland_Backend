<?php

namespace Modules\Order\Http\Controllers\WebService;

use Cart;
use Illuminate\Http\Request;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

//use Modules\Order\Http\Requests\WebService\CreateOrderRequestOld;
use Modules\Apps\Http\Controllers\WebService\WebServiceController;
use Modules\Cart\Traits\CartTrait;
use Modules\Catalog\Repositories\WebService\CatalogRepository as Catalog;
use Modules\Company\Repositories\WebService\CompanyRepository as Company;
use Modules\Order\Entities\OrderAddons;

//use Modules\Transaction\Services\PaymentService;
use Modules\Order\Entities\OrderCard;
use Modules\Order\Entities\OrderGift;
use Modules\Order\Entities\OrderStatusesHistory;
use Modules\Order\Entities\PaymentStatus;
use Modules\Order\Events\ActivityLog;

//use Modules\Order\Repositories\WebService\OrderRepositoryOld as Order;
use Modules\Order\Http\Requests\WebService\CreateOrderRequest;
use Modules\Order\Http\Requests\WebService\RateRequest;
use Modules\Order\Jobs\SendOrderToMultipleJob;
use Modules\Order\Repositories\WebService\OrderRepository as Order;
use Modules\Order\Repositories\WebService\RateRepository as Rate;
use Modules\Order\Transformers\WebService\OrderProductResource;
use Modules\Order\Transformers\WebService\OrderResource;
use Modules\Transaction\Hesabe\Helpers\ModelBindingHelper;
use Modules\Transaction\Services\TapPaymentService;
use Modules\Transaction\Services\UPaymentService;
use Modules\Transaction\Services\HesabeService;
use Modules\Transaction\Traits\PaymentTrait;
use Modules\User\Repositories\WebService\AddressRepository;
use Modules\Wrapping\Repositories\WebService\WrappingRepository as Wrapping;
use Setting;

class OrderController extends WebServiceController
{
    use CartTrait;

    protected $payment;
    protected $order;
    protected $company;
    protected $catalog;
    protected $address;
    protected $wrap;
    protected $rate;

    public function __construct(
        Order $order,
        HesabeService $payment,
        Company $company,
        Catalog $catalog,
        AddressRepository $address,
        Wrapping $wrap,
        Rate $rate
    ) {
        $this->payment = $payment;
        $this->order = $order;
        $this->company = $company;
        $this->catalog = $catalog;
        $this->address = $address;
        $this->wrap = $wrap;
        $this->rate = $rate;

        if (request()->has('address_type') && request()->address_type == "selected_address") {
            $this->middleware('auth:api')->only([
                'createOrder', // Could add bunch of more methods too
            ]);
        }
    }

    public function createOrder(CreateOrderRequest $request)
    {
        logger('::::::::::createOrder::::::::::::');
        logger($request->all());
        if (auth('api')->check()) {
            $userToken = auth('api')->user()->id;
        } else {
            $userToken = $request->user_id;
        }

        // Check if address is not found
        /* if ($request->address_type == 'selected_address') {
        // get address by id
        $companyDeliveryFees = getCartConditionByName($userToken, 'company_delivery_fees');
        $addressId = isset($companyDeliveryFees->getAttributes()['address_id'])
        ? $companyDeliveryFees->getAttributes()['address_id']
        : null;
        $address = $this->address->findByIdWithoutAuth($addressId);
        if (!$address) {
        return $this->error(__('user::webservice.address.errors.address_not_found'), [], 422);
        }

        } */
        if (auth('api')->check()) {
            // get address by id
            $companyDeliveryFees = getCartConditionByName($userToken, 'company_delivery_fees');
            $addressId = $companyDeliveryFees ? (isset($companyDeliveryFees->getAttributes()['address_id']) ? $companyDeliveryFees->getAttributes()['address_id'] : null) : null;
            $address = $this->address->findByIdWithoutAuth($addressId);
            if (!$address) {
                return $this->error(__('user::webservice.address.errors.address_not_found'), [], 422);
            }

        }

        $wrappingData = [];
        // check cart gifts validation
        $giftCondition = Cart::session($userToken)->getCondition('gift');
        if ($giftCondition) {
            $requestGifts = $giftCondition->getAttributes()['wrapping'];
            $wrappingData['gifts'] = $this->wrappingGiftValidation($requestGifts);
            if (gettype($wrappingData['gifts']) == 'string') {
                return $this->error($wrappingData['gifts'], [], 422);
            }
        }

        // check cart cards validation
        $cardCondition = Cart::session($userToken)->getCondition('card');
        if ($cardCondition) {
            $requestCards = $cardCondition->getAttributes()['cards'];
            $wrappingData['cards'] = $this->wrappingCardValidation($requestCards);
            if (gettype($wrappingData['cards']) == 'string') {
                return $this->error($wrappingData['cards'], [], 422);
            }
        }

        // check cart cards validation
        $addonsCondition = Cart::session($userToken)->getCondition('addons');
        if ($addonsCondition) {
            $requestAddons = $addonsCondition->getAttributes()['addons'];
            $wrappingData['addons'] = $this->wrappingAddonsValidation($requestAddons);
            if (gettype($wrappingData['addons']) == 'string') {
                return $this->error($wrappingData['addons'], [], 422);
            }
        }


        $coupon_discount = getCartConditionByName($userToken, 'coupon_discount');
        if (!is_null($coupon_discount)) {
            $is_valid = $this->order->checkCoupon($coupon_discount->getAttributes()['coupon']->code);
            if (!$is_valid) {
                Cart::session($userToken)->removeConditionsByType('coupon_discount');
            }
        }
        foreach (getCartContent($userToken) as $key => $item) {

            if ($item->attributes->product->product_type == 'product') {
                $cartProduct = $item->attributes->product;
                $product = $this->catalog->findOneProduct($cartProduct->id);
                if (!$product) {
                    return $this->error(__('cart::api.cart.product.not_found') . $cartProduct->id, [], 422);
                }

                $product->product_type = 'product';
            } else {
                $cartProduct = $item->attributes->product;
                $product = $this->catalog->findOneProductVariant($cartProduct->id);
                if (!$product) {
                    return $this->error(__('cart::api.cart.product.not_found') . $cartProduct->id, [], 422);
                }

                $product->product_type = 'variation';
            }

            $checkPrdFound = $this->productFound($product, $item);
            if ($checkPrdFound) {
                return $this->error($checkPrdFound, [], 422);
            }

            $checkPrdStatus = $this->checkProductActiveStatus($product, $request);
            if ($checkPrdStatus) {
                return $this->error($checkPrdStatus, [], 422);
            }

            if (!is_null($product->qty)) {
                $checkPrdMaxQty = $this->checkMaxQty($product, $item->quantity);
                if ($checkPrdMaxQty) {
                    return $this->error($checkPrdMaxQty, [], 422);
                }
            }
        }
        $payment = $request['payment'] != 'cash' ? PaymentTrait::getPaymentGateway($request['payment']) : 'cash';

        $order = $this->order->create($request, $userToken, $wrappingData);

        if(is_array($order) && isset($order['status']) && !$order['status']) {
            return $this->error(isset($order['message']), [], 422);
        }

        if (!$order) {
            return $this->error('error', [], 422);
        }

        // if ($request['payment'] != 'cash' && !$payment) {
        //     return $this->error(__('order::frontend.orders.index.alerts.payment_not_supported_now'), [], 422);
        // }

        /* if ($request['payment'] != 'cash') {
        $payment = $this->payment->send($order, 'api-order');

        return $this->response([
        'paymentUrl' => $payment,
        ]);
        } */
        if(!$order->by_with_points_to_orders){
            if ($request['payment'] != 'cash') {

                $get_payment_url = !$request->has("use_charge_v2") ? true : ($request->get_payment_url ?? false);
                $url = "";
                if ($get_payment_url) {

                    $redirect = $payment->send($order, 'online', 'api-order');
                    if (isset($redirect['status'])) {
                        if ($redirect['status'] == true && isset($redirect['url'])) {
                            $url = $redirect['url'];
                        } else {
                            return $this->error('Online Payment not valid now', [], 422);
                        }
                    }
                }

                return $this->response([
                    'used_sdk' => $request->use_charge_v2 ? true : false,
                    'paymentUrl' => $url,
                    'order_id' => $order->id,
                ]);
            }
        }


        $this->fireLog($order);
        $this->clearCart($userToken);

        $htmlOrder = $this->returnHtmlOrder($order);
        return $this->response(new OrderResource($order), 'Successfully', $htmlOrder);
    }

    public function webhooks(Request $request)
    {
        $this->order->updateOrder($request);
    }

    public function success(Request $request)
    {
        logger('::::::::::SUCCESS::::::::::::');
        logger($request->all());

        $orderDetails = $this->order->findById($request['OrderID']);
        if (!$orderDetails) {
            return $this->error(__('order::frontend.orders.index.alerts.order_not_found'), [], 422);
        }
        $order = $this->order->updateOrder($request);
        if ($order) {
            $userToken = $orderDetails->user_id ?? $orderDetails->user_token;
            if ($orderDetails) {
                $this->fireLog($orderDetails);
                $this->clearCart($userToken);
                $htmlOrder = $this->returnHtmlOrder($orderDetails);
                return $this->response(new OrderResource($orderDetails), 'Successfully', $htmlOrder);
            } else {
                return $this->error(__('order::frontend.orders.index.alerts.order_failed'), [], 422);
            }
        }
    }

    public function successTap(Request $request)
    {
        $data = (new TapPaymentService())->getTransactionDetails($request);

        $request = PaymentTrait::buildTapRequestData($data, $request);

        if ($request->Result == 'CAPTURED') {
            return $this->success($request);
        }
        return $this->failed($request);
    }

    public function successHesabe(Request $request)
    {
        $data = (new HesabeService())->getTransactionDetails($request);
        $request = PaymentTrait::buildHesabeRequestData($data, $request);
        if ($request->Result == 'CAPTURED') {
            return $this->success($request);
        }
        return $this->failed($request);
    }

    public function successHesabePayment(Request $request)
    {
        try {
            //Decrypt the response received
            $hesabeModelBinding = new ModelBindingHelper();
            $decryptedResponse = $hesabeModelBinding->getPaymentResponseDecrypt($request->data, config('hesabe.MERCHANT_SECRET_KEY'), config('hesabe.MERCHANT_IV'));

            $orderDetails = $this->order->findById($decryptedResponse->response['orderReferenceNumber']);
            if (!$orderDetails) {
                return $this->error(__('order::frontend.orders.index.alerts.order_not_found'), [], 422);
            }
            $order = $this->order->updateOrder(['OrderID' => $decryptedResponse->response['orderReferenceNumber'], 'Result' => $decryptedResponse->response['resultCode'], 'PaymentID' => $decryptedResponse->response['paymentId']]);
            if ($order) {
                $userToken = $orderDetails->user_id ?? $orderDetails->user_token;
                if ($orderDetails) {
                    $this->fireLog($orderDetails);
                    $this->clearCart($userToken);
                    $htmlOrder = $this->returnHtmlOrder($orderDetails);
                    return $this->response(new OrderResource($orderDetails), 'Successfully', $htmlOrder);
                } else {
                    return $this->error(__('order::frontend.orders.index.alerts.order_failed'), [], 422);
                }
            }
            return $this->error(__('order::frontend.orders.index.alerts.order_failed'), [], 422);
        } catch (\Exception $e) {
            return $this->error(__('order::frontend.orders.index.alerts.order_failed'), [], 422);
        }
    }

    public function failedHesabePayment(Request $request)
    {
        //Decrypt the response received
        $hesabeModelBinding = new ModelBindingHelper();
        $decryptedResponse = $hesabeModelBinding->getPaymentResponseDecrypt($request->data, config('hesabe.MERCHANT_SECRET_KEY'), config('hesabe.MERCHANT_IV'));

        $this->order->updateOrder(['OrderID' => $decryptedResponse->response['orderReferenceNumber'], 'Result' => $decryptedResponse->response['resultCode'], 'PaymentID' => $decryptedResponse->response['paymentId']]);
        return $this->error(__('order::frontend.orders.index.alerts.order_failed'), [], 422);
    }

    public function failed(Request $request)
    {
        logger('::::::::::FAILED::::::::::::');
        logger($request->all());

        $this->order->updateOrder($request);
        return $this->error(__('order::frontend.orders.index.alerts.order_failed'), [], 422);
    }

    public function userOrdersList(Request $request)
    {
        if (auth('api')->check()) {
            $userId = auth('api')->id();
            $userColumn = 'user_id';
        } else {
            $userId = $request->user_token ?? 'not_found';
            $userColumn = 'user_token';
        }
        $orders = $this->order->getAllByUser($userId, $userColumn);
        return $this->response(OrderResource::collection($orders));
    }

    public function getOrderDetails(Request $request, $id)
    {
        $order = $this->order->findById($id);

        if (!$order) {
            return $this->error(__('order::api.orders.validations.order_not_found'), [], 422);
        }

        $allOrderProducts = $order->orderProducts->mergeRecursive($order->orderVariations);
        return $this->response(OrderProductResource::collection($allOrderProducts));
    }

    public function fireLog($order)
    {

        $dashboardUrl = LaravelLocalization::localizeUrl(url(route('dashboard.orders.show', [$order->id, 'current_orders'])));
        $data = [
            'id' => $order->id,
            'type' => 'orders',
            'url' => $dashboardUrl,
            'description_en' => 'New Order',
            'description_ar' => 'طلب جديد ',
        ];

//        event(new ActivityLog($data));
//        $this->sendNotifications($order);
    }

    public function sendNotifications($order)
    {
        $email = optional($order->orderAddress)->email ?? (optional($order->user)->email ?? null);
        if (!is_null($email)) {
            $emails[] = $email;
            dispatch(new SendOrderToMultipleJob($order, $emails, 'user_email'));
        }

        if (config('setting.contact_us.email')) {
            $emails = [];
            $emails[] = config('setting.contact_us.email');
            dispatch(new SendOrderToMultipleJob($order, $emails, 'admin_email'));
        }
    }

    private function wrappingGiftValidation($requestGifts)
    {
        $totalGiftsPrice = 0;
        $gifts = [];

        foreach ($requestGifts as $k => $value) {

            if (isset($value['gift_id']) && !is_null($value['gift_id'])) {
                $gift = $this->wrap->findGiftById($value['gift_id']);
            } else {
                $gift = null;
            }
            /* if ($gift) { */

            if ($value['products'][0]['type'] == 'product') {
                $productModel = $this->catalog->findById($value['products'][0]['id']);
                if ($productModel->allow_wrapping == 0 && $value['gift_id']) {
                    return __('wrapping::frontend.gifts.can_not_wrapp_product') . ': ' . $productModel->title;
                }
            } else {
                $productModel = $this->catalog->findOneProductVariant($value['products'][0]['id']);
                if ($productModel->product->allow_wrapping == 0 && $value['gift_id']) {
                    return __('wrapping::frontend.gifts.can_not_wrapp_product') . ': ' . $productModel->product->title;
                }
            }

            $totalGiftsPrice += !is_null($gift) ? floatval($gift->price) : 0;
            $giftCardContent = [
                'gift_card_message' => $value['gift_card_message'] ?? null,
                'gift_card_from' => $value['gift_card_from'] ?? null,
                'gift_card_to' => $value['gift_card_to'] ?? null,
            ];
            $gifts[] = new OrderGift([
                'gift_id' => !is_null($gift) ? $gift->id : null,
                'products_ids' => $value['products'] ?? [],
                'price' => !is_null($gift) ? $gift->price : null,
                'gift_card_content' => $giftCardContent,
            ]);
            /* } else {
        return __('wrapping::webservice.gifts.this_gift_not_exist') . ' # ' . $k;
        } */
        }

        return [
            'totalGiftsPrice' => $totalGiftsPrice,
            'gifts' => $gifts,
        ];
    }

    private function wrappingCardValidation($requestCards)
    {
        $totalCardsPrice = 0;
        $cards = [];

        /* foreach ($requestCards as $k => $value) {
        $card = $this->wrap->findCardById($value['id']);
        if ($card) {
        $totalCardsPrice += floatval($card->price);
        $cards[] = new OrderCard([
        'card_id' => $card->id,
        'price' => $card->price,
        'sender_name' => $value['sender_name'],
        'receiver_name' => $value['receiver_name'],
        'message' => $value['message'],
        ]);
        } else {
        return __('wrapping::webservice.cards.this_card_not_exist') . ' # ' . $value['id'];
        }
        } */

        $cards[] = new OrderCard([
            'card_id' => null,
            'price' => 0,
            'sender_name' => $requestCards['sender_name'],
            'receiver_name' => $requestCards['receiver_name'],
            'message' => $requestCards['message'],
        ]);

        return [
            'totalCardsPrice' => $totalCardsPrice,
            'cards' => $cards,
        ];
    }

    private function wrappingAddonsValidation($requestAddons)
    {
        $totalAddonsPrice = 0;
        $addons = [];

        /* foreach ($requestAddons as $k => $value) {
        $row = $this->wrap->findAddonsById($value['id']);
        if ($row) {

        if (intval($value['qty']) > floatval($row->qty)) {
        return __('wrapping::webservice.addons.quantity_exceeded') . ' # ' . $row->title;
        }

        $totalAddonsPrice += floatval($row->price) * intval($value['qty']);
        $addons[] = new OrderAddons([
        'addons_id' => $row->id,
        'price' => $row->price,
        'qty' => $value['qty'],
        ]);
        } else {
        return __('wrapping::webservice.addons.this_addons_not_exist') . ' # ' . $value['id'];
        }
        } */

        foreach ($requestAddons as $key => $addon) {
            $row = $this->wrap->findAddonsById($addon['id']);
            if ($row) {

                if (intval($addon['qty']) > floatval($row->qty)) {
                    return __('wrapping::webservice.addons.quantity_exceeded') . ' # ' . $row->title;
                }

                $addons[] = new OrderAddons([
                    'addons_id' => $row->id,
                    'price' => $row->price,
                    'qty' => $addon['qty'],
                ]);
            } else {
                return __('wrapping::webservice.addons.this_addons_not_exist') . ' # ' . $addon['id'];
            }
        }

        return [
            'totalAddonsPrice' => $totalAddonsPrice,
            'addons' => $addons,
        ];
    }

    public function orderRate(RateRequest $request, $id)
    {
        $order = $this->rate->findOrderByIdWithUserId($id);
        if ($order) {
            $rate = $this->rate->checkUserRate($id);
            if (!$rate) {
                $createdRate = $this->rate->create($request, $id);
                return $this->response([]);
            } else {
                return $this->error(__('order::api.rates.user_rate_before'));
            }

        } else {
            return $this->error(__('order::api.rates.user_not_have_order'));
        }

    }

    private function returnHtmlOrder($order)
    {
        $this->caculateOrderPoints($order);
        $order->allProducts = $order->orderProducts->mergeRecursive($order->orderVariations);
        $htmlOrderView = view('order::api.html-order', compact('order'))->render();
        $htmlOrder['html_order'] = trim(preg_replace('/\s+/', ' ', $htmlOrderView));
        return $htmlOrder;
    }

    private function caculateOrderPoints($order)
    {
        $user = $order->user;

        if($user && !$order->by_with_points_to_orders){
            logger("add ponts to user");
            logger($order->subtotal * (Setting::get('points.add_point_price') ?? 0));
            $user->increment('points', $order->subtotal * (Setting::get('points.add_point_price') ?? 0));
        }
    }

    public function displayHtmlOrder(Request $request, $id)
    {
        $order = $this->order->findById($id);
        if (!$order) {
            return $this->error(__('order::api.orders.validations.order_not_found'), [], 422);
        }
        $order->allProducts = $order->orderProducts->mergeRecursive($order->orderVariations);
        $htmlOrderView = view('order::api.html-order', compact('order'))->render();
        $htmlOrder['html_order'] = trim(preg_replace('/\s+/', ' ', $htmlOrderView));
        return $this->response($htmlOrder, 'Successfully');
    }

    public function cancelOrderPayment(Request $request, $id)
    {
        if (auth('api')->check()) {
            $userData['column'] = 'user_id';
            $userData['value'] = auth('api')->id();
        } else {
            $userData['column'] = 'user_token';
            $userData['value'] = $request->user_token;
        }

        $order = $this->order->checkOrderPendingPayment($id, $userData);
        if ($order) {
            $orderStatusId = $this->order->getOrderStatusByFlag('failed')->id;
            $paymentStatusId = optional(PaymentStatus::where('flag', 'failed')->first())->id ?? $order->payment_status_id;

            $order->update([
                'order_status_id' => $orderStatusId, // failed
                'payment_status_id' => $paymentStatusId, // failed
                'payment_confirmed_at' => null,
                'increment_qty' => true,
            ]);

            // Add Order Status History
            OrderStatusesHistory::create([
                'order_id' => $order->id,
                'order_status_id' => $orderStatusId, // failed
                'user_id' => null,
            ]);

            if ($order->orderProducts) {
                foreach ($order->orderProducts as $i => $orderProduct) {
                    if (!is_null($orderProduct->product->qty)) {
                        $orderProduct->product->increment('qty', $orderProduct->qty);
                    }
                }
            }

            if ($order->orderVariations) {
                foreach ($order->orderVariations as $i => $orderProduct) {
                    if (!is_null($orderProduct->variant->qty)) {
                        $orderProduct->variant->increment('qty', $orderProduct->qty);
                    }
                }
            }

        }
        return $this->response(null);
    }

    public function createPaymentChargeData(Request $request, $id, $tapToken)
    {
        logger(':::::::::::::::::::::::::::::::::::::: START TRANSACTION :::::::::::::::::::::::::::::::::::::');
        logger('::::::::::::: orderID ::::::::::::');
        logger($id);
        logger('::::::::::::::::::: tapToken ::::::::::::::::::');
        logger($tapToken);
        logger('----------------------------------------');

        $order = $this->order->findById($id);
        if (!$order) {
            return $this->error(__('order::api.orders.validations.order_not_found'), [], 422);
        }
        $payment = PaymentTrait::getPaymentGateway('tap');
        $chargeRequest = $payment->send($order, 'online', 'api-order', $tapToken);

        if (isset($chargeRequest['status']) && $chargeRequest['status'] == false) {
            return $this->error('failed to create charge request');
        }

        logger(':::::::::: Request BEFORE BUILD ::::::::::::');
        logger($request);
        logger('----------------------------------------');

        logger(':::::::::: Charge Request Body BEFORE BUILD ::::::::::::');
        logger($chargeRequest['body']);
        logger('----------------------------------------');

        $requestData = PaymentTrait::buildTapRequestData($chargeRequest['body'], $request);

        logger(':::::::::: requestData AFTER BUILD ::::::::::::');
        logger($requestData);
        logger('::::::::::::::::::::::::::::::::::::: END TRANSACTION ::::::::::::::::::::::::::::::::::::::');

        if (in_array($requestData['Result'], ['INITIATED', 'CAPTURED']) && $requestData->OrderID == $id) {
            return $this->success($requestData);
        }
        return $this->failed($requestData);
    }

    public function getPaymentChargeData(Request $request, $id, $chargeId)
    {
        $chargeRequest = json_decode($this->buildPaymentChargeRequest($chargeId), true);
        if (isset($chargeRequest['errors']) && !empty($chargeRequest['errors'])) {
            return $this->error($chargeRequest['errors'][0]['description'] ?? 'Invalid Request');
        }
        $requestData = PaymentTrait::buildTapRequestData($chargeRequest, $request);
        if ($requestData['Result'] == 'CAPTURED' && $requestData->OrderID == $id) {
            return $this->success($requestData);
        }
        return $this->failed($requestData);
    }

    private function buildPaymentChargeRequest($chargeId)
    {
        $curl = curl_init();
        $apiKey = config('setting.supported_payments.tap.' . (config('setting.supported_payments.tap.payment_mode')) . '.API_KEY') ?? 'sk_test_It9P85fNqeMFchvx3uiaTDCQ';
        $url = "https://api.tap.company/v2/charges/" . $chargeId;
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_POSTFIELDS => "{}",
            CURLOPT_HTTPHEADER => array(
                "authorization: Bearer " . $apiKey,
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            return "cURL Error #:" . $err;
        } else {
            return $response;
        }
    }
}
