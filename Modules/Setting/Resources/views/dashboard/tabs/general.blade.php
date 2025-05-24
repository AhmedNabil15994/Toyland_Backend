<div class="tab-pane active fade in" id="global_setting">
{{--    <h3 class="page-title">{{ __('setting::dashboard.settings.form.tabs.general') }}</h3>--}}
    <div class="col-md-10">
        <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.locales') }}
            </label>
            <div class="col-md-9">
                <select name="locales[]" class="form-control select2" multiple="">
                    @foreach (config('core.available-locales') as $key => $language)
                        <option value="{{ $key }}"
                                @if (in_array($key,array_keys(config('laravellocalization.supportedLocales'))))
                                selected
                            @endif>
                            {{ $language['native'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.rtl_locales') }}
            </label>
            <div class="col-md-9">
                <select name="rtl_locales[]" class="form-control select2" multiple="">
                    @foreach (config('core.available-locales') as $key => $language)
                        <option value="{{ $key }}"
                                @if (in_array($key,config('rtl_locales')))
                                selected
                            @endif>
                            {{ $language['native'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.default_language') }}
            </label>
            <div class="col-md-9">
                <select name="default_locale" class="form-control select2">
                    @foreach (config('core.available-locales') as $key => $language)
                        <option value="{{ $key }}"
                                @if (config('default_locale') == $key)
                                selected
                            @endif>
                            {{ $language['native'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.supported_countries') }}
            </label>
            <div class="col-md-9">
                <select name="countries[]" class="form-control select2" multiple="">
                    @foreach ($countries as $code => $country)
                        <option value="{{ $code }}"
                                @if (collect(config('setting.supported_countries'))->contains($code))
                                selected=""
                            @endif>
                            {{ $country }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.default_currency') }}
            </label>
            <div class="col-md-9">
                <select name="default_currency" class="form-control select2">
                    <option> Select Value</option>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}"
                                {{$default_currency == $currency->id ? 'selected' : ''}}>
                            {{ $currency->translate('name','ar') }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.supported_currencies') }}
            </label>
            <div class="col-md-9">
                <select name="supported_currencies[]" class="form-control select2" multiple="">
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}"
                                @if (in_array($currency->id,$supported_currencies ?? []))
                                selected=""
                            @endif>
                            {{ $currency->translate('name','ar') }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div> --}}

        {{-- <div class="form-group">
            <label class="col-md-2">
                {{ __('setting::dashboard.settings.form.default_vendor') }}
            </label>
            <div class="col-md-9">
                <select name="default_vendor" id="single" class="form-control select2">
                    <option value=""></option>
                    @foreach ($vendors as $vendor)
                    <option value="{{ $vendor->id }}"
                    @if (Setting::get('default_vendor') == $vendor->id)
                    selected
                    @endif>
                        {{ $vendor->title }}
                    </option>
                    @endforeach
                </select>
            </div>
        </div> --}}
    </div>
</div>
