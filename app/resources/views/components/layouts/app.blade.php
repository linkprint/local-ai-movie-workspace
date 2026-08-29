<!doctype html>
<html lang="{{ app()->getLocale() === 'zh_CN' ? 'zh-CN' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Movie AI Workspace' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="min-h-screen bg-slate-950 text-slate-100 antialiased"
    data-portal-translations="{{ base64_encode(json_encode(array_merge(__('ui.javascript'), is_array(__('ui.media_upload.javascript')) ? __('ui.media_upload.javascript') : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}"
>
    <header class="border-b border-white/10 bg-slate-950/90">
        <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-4">
            <a class="font-semibold tracking-wide text-amber-300" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Movie AI Workspace</a>
            <div class="flex flex-wrap items-center justify-end gap-4 text-sm">
                @auth
                    @if(auth()->user()->two_factor_confirmed_at)
                        <a href="{{ route('reservations.index') }}">{{ __('ui.nav.reservations') }}</a>
                        <a href="{{ route('workspace') }}">{{ __('ui.nav.workspace') }}</a>
                        <a href="{{ route('workspace.images.index') }}">{{ __('ui.nav.images') }}</a>
                        <a href="{{ route('workspace.videos.index') }}">{{ __('ui.nav.videos') }}</a>
                    @endif
                    <a href="{{ route('profile') }}">{{ __('ui.nav.profile') }}</a>
                    @if(auth()->user()->isAdmin())<a href="/admin">{{ __('ui.nav.admin') }}</a>@endif
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">{{ __('ui.nav.sign_out') }}</button></form>
                @endauth
                <x-language-switcher compact />
            </div>
        </nav>
    </header>
    <main class="mx-auto max-w-6xl px-6 py-10">
        @if(session('status'))<div class="mb-6 rounded-lg border border-emerald-500/40 bg-emerald-950/60 p-4 text-emerald-200">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="mb-6 rounded-lg border border-red-500/40 bg-red-950/60 p-4 text-red-200"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        {{ $slot }}
    </main>
</body>
</html>
