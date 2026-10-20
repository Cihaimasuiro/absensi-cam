<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — Smart Absensi</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas-soft text-ink font-sans antialiased min-h-screen flex items-center justify-center p-md">

    <main class="w-full max-w-[380px]">

        <div class="flex flex-col items-center gap-xs mb-lg">
            <img src="{{ asset('images/logo-auth.png') }}" alt="Smart Absensi"
                 class="w-14 h-14 object-contain">
            <h1 class="text-[20px] font-bold text-ink tracking-tight m-0">Smart Absensi</h1>
            <p class="text-[12px] text-ink-muted m-0">Masuk untuk mengelola presensi dan perangkat</p>
        </div>

        <div class="card-elevated p-lg">

            @if($errors->any())
                <div class="alert alert-error mb-md" role="alert">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-px"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" class="flex flex-col gap-md">
                @csrf

                <div>
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email"
                           class="form-input @error('email') is-invalid @enderror"
                           value="{{ old('email') }}" required autofocus autocomplete="username"
                           placeholder="nama@instansi.id">
                    @error('email')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div>
                    <label for="password" class="form-label">Kata Sandi</label>
                    <input type="password" name="password" id="password"
                           class="form-input @error('password') is-invalid @enderror"
                           required autocomplete="current-password" placeholder="••••••••">
                    @error('password')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <label for="remember" class="flex items-center gap-xs cursor-pointer select-none">
                    <input type="checkbox" name="remember" id="remember"
                           class="w-3.5 h-3.5 rounded border-hairline text-primary focus:ring-2 focus:ring-primary/30 cursor-pointer">
                    <span class="text-[12px] text-ink-muted">Ingat saya</span>
                </label>

                <button type="submit" class="btn btn-primary w-full py-2">Masuk</button>
            </form>
        </div>

        <p class="text-center text-[11.5px] text-ink-faint mt-md">
            <a href="{{ route('landing') }}" class="hover:text-primary transition-colors">← Kembali ke halaman depan</a>
        </p>
    </main>

</body>
</html>
