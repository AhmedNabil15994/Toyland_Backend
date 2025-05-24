<div class="order-payment">
    @foreach (array_keys(config('setting.supported_payments')) ?? [] as $key => $payment)

    @if(config('setting.supported_payments.' . $payment . '.status') == 'on')
    <div class="checkboxes radios mb-20">
        <input id="payment-{{ $payment }}" type="radio" value="{{$payment}}" name="payment"
            {{ old('payment') == $payment  ? 'checked' : '' }}>
        <label for="payment-{{ $payment }}">
            {{ config('setting.supported_payments.' . $payment . '.title.' . locale()) }}
        </label>
    </div>
    @endif
@endforeach
</div>
