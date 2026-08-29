@props(['compact' => false])

<form class="language-switcher {{ $compact ? 'language-switcher-compact' : '' }}" method="POST" action="{{ route('locale.update') }}" aria-label="{{ __('ui.language.label') }}">
    @csrf
    @foreach (['zh_CN' => __('ui.language.chinese'), 'en' => __('ui.language.english')] as $locale => $label)
        <button
            class="language-switcher-option {{ app()->getLocale() === $locale ? 'is-active' : '' }}"
            type="submit"
            name="locale"
            value="{{ $locale }}"
            lang="{{ $locale === 'zh_CN' ? 'zh-CN' : 'en' }}"
            @if (app()->getLocale() === $locale) aria-current="true" @endif
        >{{ $label }}</button>
    @endforeach
</form>
