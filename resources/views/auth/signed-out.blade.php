<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('ui.identity.signed_out_title') }} · {{ __('ui.app.name') }}</title>
    <link rel="stylesheet" href="{{ wr_asset('assets/app.css') }}">
</head>
<body>
<main class="main" style="max-width:560px;margin:14vh auto">
    <h1 class="h2">{{ __('ui.identity.signed_out_title') }}</h1>
    @if (session('status'))
        <p class="flash" role="status">{{ session('status') }}</p>
    @endif
    <p class="muted">{{ __('ui.identity.signed_out_body') }}</p>
    <p>
        <a class="btn" href="{{ config('workreport.sso.issuer') }}">{{ __('ui.identity.open_sso') }}</a>
        <a class="btn btn--ghost" href="{{ route('board') }}">{{ __('ui.identity.sign_in_again') }}</a>
    </p>
</main>
</body>
</html>
