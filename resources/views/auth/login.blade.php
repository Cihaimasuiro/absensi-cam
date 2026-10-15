<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Smart Absensi</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas-soft text-ink font-sans antialiased min-h-screen flex items-center justify-center p-md">

    <div class="card p-xl w-full max-w-[400px]">
        <div class="text-center mb-lg">
            <img src="{{ asset('images/logo-auth.png') }}" class="w-24 h-24 mx-auto object-contain mb-sm" alt="Logo">
            <h1 class="text-[24px] font-bold text-ink tracking-tight mb-xs">Smart Absensi</h1>
            <p class="text-caption text-ink-muted">Sign in to manage devices and enrollments</p>
        </div>

        @if($errors->any())
            <div class="alert alert-error mb-md text-caption">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.post') }}" class="flex flex-col gap-md">
            @csrf
            <div>
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-input w-full" value="{{ old('email') }}" required autofocus>
            </div>

            <div>
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-input w-full" required>
            </div>

            <div class="flex items-center gap-xs">
                <input type="checkbox" name="remember" id="remember" class="rounded border-hairline text-primary focus:ring-primary">
                <label for="remember" class="text-caption text-ink-muted">Remember me</label>
            </div>

            <button type="submit" class="btn btn-primary w-full justify-center">
                Sign In
            </button>
        </form>
    </div>

</body>
</html>
