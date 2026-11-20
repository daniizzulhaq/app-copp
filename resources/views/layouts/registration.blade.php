<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · COPP Luxury</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="registration-body">
<main class="registration-card">
    <a href="/" class="registration-brand"><x-logo/></a>
    @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error-summary" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @yield('content')
    <small class="auth-footer">© {{ date('Y') }} COPP Luxury</small>
</main>
</body>
</html>
