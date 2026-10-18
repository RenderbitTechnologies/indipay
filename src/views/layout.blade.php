@php
    $indipayNonce = isset($nonce) && $nonce ? $nonce : null;

    if ($indipayNonce === null && class_exists('Illuminate\Foundation\Vite')) {
        $indipayNonce = app('Illuminate\Foundation\Vite')->cspNonce();
    }
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IndiPay</title>
</head>
<body>
    <form method="@yield('method', 'post')" id="indipay-redirect" name="redirect" action="@yield('action')">
@yield('fields')
        <noscript>
            <button type="submit">Continue to payment</button>
        </noscript>
    </form>
    <script{!! $indipayNonce ? ' nonce="'.e($indipayNonce).'"' : '' !!}>document.getElementById('indipay-redirect').submit();</script>
</body>
</html>
