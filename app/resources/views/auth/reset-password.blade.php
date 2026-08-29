<x-layouts.app :title="__('ui.auth.choose_new_password')">
    <form class="mx-auto max-w-md space-y-5 rounded-2xl border border-white/10 bg-white/5 p-8" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <h1 class="text-2xl font-semibold">{{ __('ui.auth.choose_new_password') }}</h1>
        <label class="block">{{ __('ui.auth.email') }}<input class="mt-2 w-full rounded bg-slate-900 p-3" type="email" name="email" value="{{ old('email', $request->email) }}" required></label>
        <label class="block">{{ __('ui.auth.new_password') }}<input class="mt-2 w-full rounded bg-slate-900 p-3" type="password" name="password" required autocomplete="new-password"></label>
        <label class="block">{{ __('ui.auth.confirm_password') }}<input class="mt-2 w-full rounded bg-slate-900 p-3" type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.auth.reset_password') }}</button>
    </form>
</x-layouts.app>
