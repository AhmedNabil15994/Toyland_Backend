<?php

namespace Modules\Order\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Attribute\Entities\AttributeValue;
use Modules\Company\Entities\Company;
use Modules\Core\Traits\ScopesTrait;
use Modules\Log\Traits\LogModelTrait;

class Order extends Model
{
    use SoftDeletes, ScopesTrait, LogModelTrait;

    protected $guarded = ['id'];

    protected function asJson($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function transactions()
    {
        return $this->morphOne(\Modules\Transaction\Entities\Transaction::class, 'transaction');
    }

    public function orderStatus()
    {
        return $this->belongsTo(OrderStatus::class);
    }

    public function paymentStatus()
    {
        return $this->belongsTo(PaymentStatus::class, 'payment_status_id');
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id');
    }

    public function user()
    {
        return $this->belongsTo(\Modules\User\Entities\User::class);
    }

    public function cashier()
    {
        return $this->belongsTo(\Modules\User\Entities\User::class, "cashier_id");
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id')->where("is_refund", 0);
    }

    public function orderVariations()
    {
        return $this->hasMany(OrderVariantProduct::class, 'order_id')->where("is_refund", 0);
    }

    public function orderAddress()
    {
        return $this->hasOne(OrderAddress::class, 'order_id');
    }

    public function unknownOrderAddress()
    {
        return $this->hasOne(UnknownOrderAddress::class, 'order_id');
    }

    public function driver()
    {
        return $this->hasOne(OrderDriver::class, 'order_id');
    }

    public function subRefund($refund)
    {
        $this->update([
            "original_subtotal" => $this->original_subtotal > $refund ? $this->original_subtotal - $refund : 0,
            "subtotal" => $this->subtotal > $refund ? $this->subtotal - $refund : 0,
            "total" => $this->total > $refund ? $this->total - $refund : 0,

        ]);
    }

    public function rate()
    {
        return $this->hasOne(Rate::class, 'order_id');
    }

    public function orderCards()
    {
        return $this->hasMany(OrderCard::class, 'order_id');
    }

    public function orderGifts()
    {
        return $this->hasMany(OrderGift::class, 'order_id');
    }

    public function orderAddons()
    {
        return $this->hasMany(OrderAddons::class, 'order_id');
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'order_companies')->withPivot('availabilities', 'delivery');
    }

    public function orderStatusesHistory()
    {
        return $this->belongsToMany(OrderStatus::class, 'order_statuses_history')->withPivot(['id', 'user_id'])->withTimestamps();
    }

    public function orderPaymentTypeLogs()
    {
        return $this->hasMany(OrderPaymentLog::class)->where('paymentable_type', get_class(new PaymentType))->orderBy('created_at', 'desc');
    }

    public function orderPaymentStatusLogs()
    {
        return $this->hasMany(OrderPaymentLog::class)->where('paymentable_type', get_class(new PaymentStatus()))->orderBy('created_at', 'desc');
    }

    public function orderCoupons()
    {
        return $this->hasOne(OrderCoupon::class, 'order_id');
    }

    public function attributes()
    {
        return $this->morphMany(AttributeValue::class, 'attributeValuable', 'order_product_attributes_type', 'order_product_attributes_id');
    }

    public function getOrderFlagAttribute()
    {
        $orderStatusFlag = $this->orderStatus->flag ?? '';
        if (in_array($orderStatusFlag, ['new_order', 'received', 'processing', 'is_ready'])) {
            return 'current_orders';
        } elseif (in_array($orderStatusFlag, ['on_the_way', 'delivered'])) {
            return 'completed_orders';
        } elseif (in_array($orderStatusFlag, ['failed'])) {
            return 'not_completed_orders';
        } elseif (in_array($orderStatusFlag, ['refund'])) {
            return 'refunded_orders';
        } else {
            return 'all_orders';
        }
    }

    public function scopeCompletedOrders()
    {
        return $this->whereHas('orderStatus', function ($query) {
            $query->whereIn('flag', ['on_the_way', 'delivered']);
        })->whereHas('paymentStatus', function ($query) {
            $query->where('flag', 'success');
            $query->orWhere(function ($query) {
                $query->where("payment_statuses.flag", 'cash');
                $query->whereNotNull("orders.payment_confirmed_at");
            });
        })->whereNotNull("payment_confirmed_at");
    }

    public function scopeSuccessOrders()
    {
        return $this->whereNotNull("payment_confirmed_at")
            ->whereHas('paymentStatus', function ($query) {
                $query->where('flag', 'success');
                $query->orWhere(function ($query) {
                    $query->where("payment_statuses.flag", 'cash');
                    $query->whereNotNull("orders.payment_confirmed_at");
                });
            });
    }
}
