<?php

namespace Modules\Order\Repositories\WebService;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Coupon\Entities\Coupon;
use Modules\Order\Entities\Order;
use Modules\Order\Entities\OrderCoupon;
use Modules\Order\Entities\OrderStatus;
use Modules\Order\Entities\PaymentStatus;
use Modules\Order\Entities\PaymentType;
use Modules\Order\Traits\OrderCalculationTrait;
use Modules\User\Repositories\WebService\AddressRepository;
use Modules\Variation\Entities\ProductVariant;
use Setting;

class OrderRepository
{
    use OrderCalculationTrait;

    protected $variantPrd;
    protected $order;
    protected $address;

    public function __construct(Order $order, ProductVariant $variantPrd, AddressRepository $address)
    {
        $this->variantPrd = $variantPrd;
        $this->order = $order;
        $this->address = $address;
    }

    public function getAllByUser($userId, $userColumn = 'user_id', $order = 'id', $sort = 'desc')
    {
        $orders = $this->order->with(['orderStatus'])->successOrders()->where($userColumn, $userId)->orderBy($order, $sort)->get();
        return $orders;
    }

    public function findById($id)
    {
        $order = $this->order->with(['orderProducts', 'orderVariations'])->find($id);
        return $order;
    }

    public function findByIdWithUserId($id)
    {
        $order = $this->order->where('user_id', auth()->id())->find($id);
        return $order;
    }

    public function create($request, $userToken = null, $wrappingData = [])
    {
        $orderData = $this->calculateTheOrder($userToken);

        DB::beginTransaction();

        try {
            $orderStatusId = $this->getOrderStatusByFlag('new_order')->id;
            $userId = auth('api')->check() ? auth('api')->id() : null;
            $by_with_points_to_orders = false;
            $paymentTypeId = PaymentType::where('flag', $request['payment'])->first()->id;
            $pendingPaymentStatus = PaymentStatus::where('flag', 'pending')->first()->id; // pending
            $successPaymentStatus = PaymentStatus::where('flag', 'success')->first()->id; // success

            $paymentConfirmedAt = null;
            if ($request['payment'] == 'cash') {
                $orderStatus = $orderStatusId; // new_order
                $paymentStatus = $successPaymentStatus;
                $paymentConfirmedAt = date('Y-m-d H:i:s');
            } elseif ($request['payment'] != 'cash' && $orderData['total'] <= 0) {
                $orderStatus = $orderStatusId; // new_order
                $paymentStatus = $successPaymentStatus;
            } else {
                $orderStatus = null;
                $paymentStatus = $pendingPaymentStatus;
            }


            if($request->buy_with_point && $request->buy_with_point == 'yes' && auth('api')->check()){
                $pointsAsked = auth('api')->user()->points * (Setting::get('points.add_point_price') ?? 0);
                if($orderData['subtotal'] <= $pointsAsked && $orderData['subtotal'] >= (Setting::get('points.add_point_price') ?? 0)){
                    $by_with_points_to_orders = true;
                    auth('api')->user()->decrement('points', ($orderData['subtotal'] / (Setting::get('points.add_point_price') ?? 0)));
                }else{
                    return ['status' => false,'message' => __("Your points in not enough")];
                }
            }

            $orderCreated = $this->order->create([
                'original_subtotal' => $orderData['original_subtotal'],
                'subtotal' => $orderData['subtotal'],
                'off' => $orderData['off'],
                'shipping' => $orderData['shipping'],
                'total' => $orderData['total'],
                'total_profit' => $orderData['profit'],
                'by_with_points_to_orders' => $by_with_points_to_orders,
                'user_id' => $userId,
                'user_token' => auth('api')->guest() ? $request->user_id : null,
                'name' => auth()->check() ? auth()->user()->name : \request('username'),
                'mobile' => auth()->check() ? auth()->user()->calling_code . auth()->user()->mobile : \request('mobile'),
                'email' => auth()->check() ? auth()->user()->email : (\request('email') ? \request('email') : 'info@toylandkw.com'),
                'order_status_id' => $orderStatus,
                'payment_status_id' => $paymentStatus,
                'payment_type_id' => $paymentTypeId,
                'notes' => $request['notes'] ?? null,
                'payment_confirmed_at' => $paymentConfirmedAt,
            ]);

            // \File::append(storage_path().'/logs/orders_log'.date('Y-m-d').'.log', json_encode($orderCreated));
            $orderCreated->transactions()->create([
                'method' => $request['payment'],
                'result' => ($request['payment'] == 'cash') ? 'CASH' : null,
            ]);

            if (!is_null($orderStatus)) {
                // Add Order Status History
                $orderCreated->orderStatusesHistory()->sync([$orderStatus => ['user_id' => $userId]]);
            }

            $this->createOrderProducts($orderCreated, $orderData);
            $this->createOrderVendors($orderCreated, $orderData['vendors']);

            if ($request->shipping_company) {
                $this->createOrderCompanies($orderCreated, $request);
            }

            if (!is_null($orderData['coupon'])) {
                $orderCreated->orderCoupons()->create([
                    'coupon_id' => $orderData['coupon']['id'],
                    'code' => $orderData['coupon']['code'],
                    'discount_type' => $orderData['coupon']['type'],
                    'discount_percentage' => $orderData['coupon']['discount_percentage'],
                    'discount_value' => $orderData['coupon']['discount_value'],
                    'products' => $orderData['coupon']['products'],
                ]);
            }

            ############ START To Add Order Address ###################
            /* if ($request->address_type == 'guest_address') {
            $this->createOrderAddress($orderCreated, $request, 'guest_address');
            } elseif ($request->address_type == 'selected_address') {
            // get address by id
            $companyDeliveryFees = getCartConditionByName($userToken, 'company_delivery_fees');
            $addressId = isset($companyDeliveryFees->getAttributes()['address_id'])
            ? $companyDeliveryFees->getAttributes()['address_id']
            : null;
            $address = $this->address->findByIdWithoutAuth($addressId);
            if ($address) {
            $this->createOrderAddress($orderCreated, $address, 'selected_address');
            } else {
            return false;
            }

            } */

            if (auth('api')->guest()) {
                $this->createOrderAddress($orderCreated, $request, 'guest_address');
            } elseif (auth('api')->check()) {
                // get address by id
                $companyDeliveryFees = getCartConditionByName($userToken, 'company_delivery_fees');
                $addressId = isset($companyDeliveryFees->getAttributes()['address_id'])
                ? $companyDeliveryFees->getAttributes()['address_id']
                : null;
                $address = $this->address->findByIdWithoutAuth($addressId);
                if ($address) {
                    $this->createOrderAddress($orderCreated, $address, 'selected_address');
                } else {
                    return false;
                }

            }
            ############ END To Add Order Address ###################

            if (isset($wrappingData['gifts']) && !empty($wrappingData['gifts'])) {
                $this->createOrderGift($orderCreated, $wrappingData['gifts']['gifts']);
            }

            if (isset($wrappingData['cards']) && !empty($wrappingData['cards'])) {
                $this->createOrderCard($orderCreated, $wrappingData['cards']['cards']);
            }

            if (isset($wrappingData['addons']) && !empty($wrappingData['addons'])) {
                $this->createOrderAddons($orderCreated, $wrappingData['addons']['addons']);
            }

            DB::commit();
            $orderCreated->refresh();
            return $orderCreated;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createOrderProducts($orderCreated, $orderData)
    {
        foreach ($orderData['products'] as $product) {

            if ($product['product_type'] == 'product') {

                $orderProduct = $orderCreated->orderProducts()->create([
                    'product_id' => $product['product_id'],
                    'off' => $product['off'],
                    'qty' => $product['quantity'],
                    'price' => $product['original_price'],
                    'sale_price' => $product['sale_price'],
                    'original_total' => $product['original_total'],
                    'total' => $product['total'],
                    'total_profit' => $product['total_profit'],
                    'notes' => $product['notes'] ?? null,
                    // 'add_ons_option_ids' => !empty($product['add_ons_option_ids']) && count($product['add_ons_option_ids']) > 0 ? $product['add_ons_option_ids'] : [],
                ]);

                $productObject = $product['product'];
                if (!is_null($productObject->qty) && intval($productObject->qty) >= intval($product['quantity'])) {
                    $productObject->decrement('qty', $product['quantity']);
                }

                /* foreach ($orderCreated->orderProducts as $value) {
            $value->product()->decrement('qty', $value['qty']);
            } */
            } else {
                $orderProduct = $orderCreated->orderVariations()->create([
                    'product_variant_id' => $product['product_id'],
                    'off' => $product['off'],
                    'qty' => $product['quantity'],
                    'price' => $product['original_price'],
                    'sale_price' => $product['sale_price'],
                    'original_total' => $product['original_total'],
                    'total' => $product['total'],
                    'total_profit' => $product['total_profit'],
                    'notes' => $product['notes'] ?? null,
                    // 'add_ons_option_ids' => !empty($product['add_ons_option_ids']) && count($product['add_ons_option_ids']) > 0 ? $product['add_ons_option_ids'] : [],
                ]);

                $productVariant = $this->variantPrd->with('productValues')->find($product['product_id']);

                // add product_variant_values to order variations
                if (count($productVariant->productValues) > 0) {
                    foreach ($productVariant->productValues as $k => $value) {
                        $orderProduct->orderVariantValues()->create([
                            'product_variant_value_id' => $value->id,
                        ]);
                    }
                }

                $productObject = $product['product'];
                if (!is_null($productObject->qty) && intval($productObject->qty) >= intval($product['quantity'])) {
                    $productObject->decrement('qty', $product['quantity']);
                }

                /* foreach ($orderCreated->orderVariations as $value) {
            $value->variant()->decrement('qty', $value['qty']);
            } */
            }
        }
    }

    public function createOrderVendors($orderCreated, $vendors)
    {
        foreach ($vendors as $k => $vendor) {
            $orderCreated->vendors()->attach($vendor['id'], [
                'total_comission' => $vendor['commission'],
                'total_profit_comission' => $vendor['totalProfitCommission'],
                'original_subtotal' => $vendor['original_subtotal'],
                'subtotal' => $vendor['subtotal'],
                'qty' => $vendor['qty'],
            ]);
        }
    }

    public function createOrderAddress($orderCreated, $address, $type = '')
    {
        $data = [];
        if ($type == 'guest_address') {
            $data = [
                'username' => $address['address']['username'] ?? null,
                'email' => $address['address']['email'] ?? null,
                'mobile' => $address['address']['mobile'],
                'address' => $address['address']['address'],
                'block' => $address['address']['block'],
                'street' => $address['address']['street'],
                'building' => $address['address']['building'] ?? null,
                'state_id' => $address['address']['state_id'],
                'avenue' => $address['address']['avenue'] ?? null,
                'floor' => $address['address']['floor'] ?? null,
                'flat' => $address['address']['flat'] ?? null,
                'automated_number' => $address['address']['automated_number'] ?? null,
                'address_title' => $address['address']['address_title'] ?? null,
            ];
        } elseif ($type == 'selected_address') {
            $data = [
                'username' => $address['username'] ?? (auth('api')->user()->name ?? null),
                'email' => $address['email'] ?? (auth('api')->user()->email ?? null),
                'mobile' => $address['mobile'] ?? (auth('api')->user()->mobile ?? null),
                'address' => $address['address'],
                'block' => $address['block'],
                'street' => $address['street'],
                'building' => $address['building'] ?? null,
                'state_id' => $address['state_id'],
                'avenue' => $address['avenue'] ?? null,
                'floor' => $address['floor'] ?? null,
                'flat' => $address['flat'] ?? null,
                'automated_number' => $address['automated_number'] ?? null,
                'address_title' => $address['address_title'] ?? null,
            ];
        }
        $orderCreated->orderAddress()->create($data);
    }

    public function createOrderCompanies($orderCreated, $request)
    {
        $price = getOrderShipping(auth('api')->check() ? auth('api')->id() : $request->user_id) ?? 0;

        $data = [
            'company_id' => config('setting.other.shipping_company') ?? null,
            'delivery' => floatval($price) ?? null,
        ];

        if (isset($request->shipping_company['availabilities']['day_code']) && !empty($request->shipping_company['availabilities']['day_code'])) {
            $dayCode = $request->shipping_company['availabilities']['day_code'] ?? '';
            $availabilities = [
                'day_code' => $dayCode,
                'day' => getDayByDayCode($dayCode)['day'],
                'full_date' => getDayByDayCode($dayCode)['full_date'],
            ];

            $data['availabilities'] = \GuzzleHttp\json_encode($availabilities);
        }

        if (config('setting.other.shipping_company')) {
            $orderCreated->companies()->attach(config('setting.other.shipping_company'), $data);
        }
    }

    public function updateOrder($request)
    {
        $order = $this->findById($request['OrderID']);
        if (!$order) {
            return false;
        }
        $this->updateQtyOfProduct($order, $request);

        // if ($request['Result'] == 'CAPTURED') {
        if (in_array($request['Result'], ['INITIATED', 'CAPTURED'])) {
            $newOrderStatus = $this->getOrderStatusByFlag('new_order')->id;
            $newPaymentStatus = optional(PaymentStatus::where('flag', 'success')->first())->id ?? $order->payment_status_id;
            $paymentConfirmedAt = date('Y-m-d H:i:s');
        } else {
            $newOrderStatus = $this->getOrderStatusByFlag('failed')->id;
            $newPaymentStatus = optional(PaymentStatus::where('flag', 'failed')->first())->id ?? $order->payment_status_id;
            $paymentConfirmedAt = null;
        }

        $order->update([
            'order_status_id' => $newOrderStatus,
            'payment_status_id' => $newPaymentStatus, // success : failed
            'payment_number' => isset($request['PaymentID']) ? $request['PaymentID'] : null,
            'payment_confirmed_at' => $paymentConfirmedAt,
            'increment_qty' => true,
        ]);

        // Add new order history
        $order->orderStatusesHistory()->attach([$newOrderStatus => ['user_id' => $order->user_id ?? null]]);

        $order->transactions()->updateOrCreate(
            [
                'transaction_id' => $request['OrderID'],
            ],
            [
                'auth' => isset($request['Auth']) ? $request['Auth'] : null,
                'tran_id' => isset($request['TranID']) ? $request['TranID'] : null,
                'result' => isset($request['Result']) ? $request['Result'] : null,
                'post_date' => isset($request['PostDate']) ? $request['PostDate'] : null,
                'ref' => isset($request['Ref']) ? $request['Ref'] : null,
                'track_id' => isset($request['TrackID']) ? $request['TrackID'] : null,
                'payment_id' => isset($request['PaymentID']) ? $request['PaymentID'] : null,
            ]
        );

        return in_array($request['Result'], ['INITIATED', 'CAPTURED']) ? true : false;
        // return $request['Result'] == 'CAPTURED' ? true : false;
    }

    public function updateQtyOfProduct($order, $request)
    {
        // if ($request['Result'] != 'CAPTURED' && $order->increment_qty != true) {
        if (!in_array($request['Result'], ['INITIATED', 'CAPTURED']) && $order->increment_qty != true) {
            foreach ($order->orderProducts as $value) {
                if (!is_null($value->product->qty)) {
                    $value->product()->increment('qty', $value['qty']);
                }

                $variant = $value->orderVariant;
                if (!is_null($variant)) {
                    if (!is_null($variant->variant->qty)) {
                        $variant->variant()->increment('qty', $value['qty']);
                    }

                }
            }
        }
    }

    /* public function updateQtyOfProduct($order, $request)
    {
    if ($request['Result'] != 'CAPTURED' && $order->increment_qty != true) {
    foreach ($order->orderProducts as $value) {
    $value->product()->increment('qty', $value['qty']);
    $variant = $value->orderVariant;
    if (!is_null($variant)) {
    $variant->variant()->increment('qty', $value['qty']);
    }

    }
    }
    } */

    private function createOrderGift($orderCreated, $gifts)
    {
        $orderCreated->orderGifts()->saveMany($gifts);
    }

    private function createOrderCard($orderCreated, $cards)
    {
        $orderCreated->orderCards()->saveMany($cards);
    }

    private function createOrderAddons($orderCreated, $addons)
    {
        $orderCreated->orderAddons()->saveMany($addons);
    }

    public function getOrderStatusByFlag($flag)
    {
        return OrderStatus::where('flag', $flag)->first();
    }

    public function checkOrderPendingPayment($id, array $userData)
    {
        return $this->order->where($userData['column'], $userData['value'])
            ->where('payment_status_id', 1)
            ->find($id);
    }
    public function checkCoupon($code)
    {
        $coupon = Coupon::where('code', $code)->active()->first();
        if (!$coupon) {
            return false;
        }
        if (!is_null($coupon->start_at) && !is_null($coupon->expired_at)) {
            if ($coupon->start_at > Carbon::now()->format('Y-m-d') || $coupon->expired_at < Carbon::now()->format('Y-m-d')) {
                return false;
            }
        }

        if (auth('api')->guest() && !in_array('guest', $coupon->user_type ?? [])) {
            return false;
        }

        if (auth('api')->check() && !in_array('user', $coupon->user_type ?? [])) {
            return false;
        }

        $coupon_users = $coupon->users->pluck('id')->toArray() ?? [];
        if ($coupon_users != []) {
            if (auth('api')->check() && !in_array(auth('api')->id(), $coupon_users)) {
                return false;
            }

        }

        if (auth('api')->check()) {
            $userCouponsCount = OrderCoupon::where('coupon_id', $coupon->id)
                ->whereHas('order', function ($q) {
                    $q->where('user_id', auth('api')->id());
                    $q->whereHas('paymentStatus', function ($q) {
                        $q->whereIn('flag', ['success', 'cash']);
                    });
                })->count();

            if (!is_null($coupon->user_max_uses) && $userCouponsCount > intval($coupon->user_max_uses)) {
                return false;
            }
        }

        return true;
    }
}
