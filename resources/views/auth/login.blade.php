<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#7c263e">
    <title>{{ $setup ? 'Welcome home' : 'Sign in' }} · Home Pantry</title>
    <link rel="icon" href="{{ asset('icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-story">
        <a class="brand" href="{{ route('login') }}"><span class="brand-symbol"><x-icon name="home" /></span>home<span class="brand-light">pantry</span></a>
        <div>
            <span class="eyebrow">YOUR HOME, IN ORDER.</span>
            <h1>A little less<br>“are we out?”<br>A lot more ease.</h1>
            <p>Your everyday essentials, your grocery list,<br>and a well-stocked home. All in one place.</p>
            <div class="auth-decoration"><x-icon name="leaf" /><span>Good things start at home.</span></div>
        </div>
        <small>MADE FOR THE EVERYDAY</small>
    </section>

    <section class="auth-form">
        <span class="eyebrow">{{ $setup ? 'LET’S MAKE YOURSELF AT HOME' : 'NICE TO HAVE YOU BACK' }}</span>
        <h2>{{ $setup ? 'Welcome to your pantry.' : 'Welcome home.' }}</h2>
        <p class="muted">{{ $setup ? 'Create the first administrator account to get started.' : 'Sign in to see what’s on your shelves.' }}</p>

        @if ($errors->any())
            <div class="notice error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form class="stack-form" method="post" action="{{ $setup ? route('setup') : route('login') }}">
            @csrf
            @if ($setup)
                <label>Your name<input name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="120"></label>
            @endif
            <label>Email address<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
            <label>Password<input type="password" name="password" required autocomplete="{{ $setup ? 'new-password' : 'current-password' }}" @if ($setup) minlength="12" @endif></label>
            @if ($setup)
                <small class="muted">Use at least 12 characters.</small>
                <label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" minlength="12"></label>
            @else
                <label class="checkbox-label"><input type="checkbox" name="remember" value="1" checked>Keep me signed in</label>
            @endif
            <button class="button primary">{{ $setup ? 'Create my home pantry' : 'Sign in' }}<x-icon name="arrow" /></button>
        </form>

        <p class="auth-note">{{ $setup ? 'This account manages the shared product catalog. Pantry stock and shopping lists stay personal to each account.' : 'Your shelves are right where you left them.' }}</p>
    </section>
</main>
</body>
</html>
