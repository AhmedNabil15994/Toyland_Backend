@extends('apps::frontend.layouts.master')
@section('title', __('apps::frontend.contact_us.title') )
@section('content')

    {{--<div class="second-header contact-header d-flex align-items-center">
        <div class="container">
            <h1>{{ __('apps::frontend.contact_us.header_title') }}</h1>
        </div>
    </div>--}}
    <div class="inner-page">
        <div class="container">
            <div class="row">
                <div class="col-md-6 contact-details">
                    <ul class="contact-details">

                        @if(config('setting.contact_us.mobile'))
                            <li>
                                <i class="ti-mobile"></i> <strong>{{ __('apps::frontend.contact_us.info.mobile')}}
                                    :</strong>
                                <span>{{ config('setting.contact_us.mobile') }} </span>
                            </li>
                        @endif

                        @if(config('setting.contact_us.technical_support'))
                            <li>
                                <i class="ti-headphone-alt"></i>
                                <strong>{{ __('apps::frontend.contact_us.info.technical_support')}}:</strong>
                                <span>{{ config('setting.contact_us.technical_support') }} </span>
                            </li>
                        @endif

                        <li>
                            <i class="ti-world"></i> <strong>{{ __('apps::frontend.contact_us.info.our_site')}}
                                :</strong>
                            <span><a href="{{ route('frontend.home') }}">{{ env('APP_URL') }}</a></span>
                        </li>
                        <li>
                            <i class="ti-email"></i> <strong>{{ __('apps::frontend.contact_us.info.email')}}:</strong>
                            <span><a href="mailto:{{ config('setting.contact_us.email') }}">{{ config('setting.contact_us.email') }}</a></span>
                        </li>
                    </ul>
                    <div class="footer-social mt-30 pt-30">
{{--                        <a href="{{ config('setting.social.facebook') ?? '#' }}" target="_blank" class="social-icon">--}}
{{--                            <i class="ti-facebook"></i>--}}
{{--                        </a>--}}
                        <a href="{{ config('setting.social.instagram') ?? '#' }}" target="_blank" class="social-icon">
                            <i class="ti-instagram"></i>
                        </a>
{{--                        <a href="{{ config('setting.social.linkedin') ?? '#' }}" target="_blank" class="social-icon">--}}
{{--                            <i class="ti-linkedin"></i>--}}
{{--                        </a>--}}
                        <a href="{{ config('setting.social.twitter') ?? '#' }}" target="_blank" class="social-icon">
                            <i class="ti-twitter-alt"></i>
                        </a>
                        <a href="{{ config('setting.social.tiktok') ?? '#' }}" target="_blank" class="social-icon">
                            <i class="ti-tumblr"></i>
                        </a>
                    </div>
                </div>
                <div class="col-md-6">

                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            <center>
                                {{ session('status') }}
                            </center>
                        </div>
                    @endif

                    <form class="form-contact" action="{{ url(route('frontend.send-contact-us')) }}" method="post">
                        @csrf

                        <div class="form-group">
                            <input type="text" name="username" value="{{ old('username') }}"
                                   placeholder="{{ __('apps::frontend.contact_us.form.username')}}">

                            @error('username')
                            <p class="text-danger m-2" role="alert">
                                <strong>{{ $message }}</strong>
                            </p>
                            @enderror

                        </div>
                        <div class="row">
                            <div class="col-md-6 col-12">
                                <div class="form-group">
                                    <input type="email" value="{{ old('email') }}"
                                           placeholder="{{ __('apps::frontend.contact_us.form.email')}}" name="email">

                                    @error('email')
                                    <p class="text-danger m-2" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </p>
                                    @enderror

                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="form-group">
                                    <input type="text" value="{{ old('mobile') }}"
                                           placeholder="{{ __('apps::frontend.contact_us.form.mobile')}}" name="mobile">

                                    @error('mobile')
                                    <p class="text-danger m-2" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </p>
                                    @enderror

                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <textarea aria-invalid="false" class="textarea-control" name="message"
                                      placeholder="{{ __('apps::frontend.contact_us.form.message')}}">{{ old('message') }}</textarea>

                            @error('message')
                            <p class="text-danger m-2" role="alert">
                                <strong>{{ $message }}</strong>
                            </p>
                            @enderror

                        </div>
                        <div class="form-group">
                            <button class="btn btn-them btn-block main-custom-btn"
                                    type="submit">{{ __('apps::frontend.contact_us.form.btn.send')}}</button>
                        </div>
                    </form>
                </div>

            </div>

            <div id="google-map" class="mt-40">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3476.734923636862!2d47.981704175825996!3d29.378049749835892!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3fcf8534346f2e23%3A0x629d958dbb1929bd!2sNew%20Toy%20Land!5e0!3m2!1sen!2skw!4v1713992053580!5m2!1sen!2skw" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </div>
    </div>


@endsection

@push('plugins_scripts')
{{--    <script src="https://maps.google.com/maps/api/js?key=AIzaSyBkdsK7PWcojsO-o_q2tmFOLBfPGL8k8Vg&language={{locale()}}"></script>--}}
@endpush
