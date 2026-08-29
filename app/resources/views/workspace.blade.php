<x-layouts.app :title="__('ui.nav.workspace')">
    <div class="space-y-6" data-style-library>
        @if ($authModeRequired)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 px-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="codex-account-title" data-testid="codex-account-modal" data-codex-account-choice>
                <section class="w-full max-w-2xl rounded-3xl border border-white/15 bg-slate-900 p-6 shadow-2xl md:p-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">{{ __('ui.workspace.codex_account') }}</p>
                    <h2 id="codex-account-title" class="mt-3 text-2xl font-semibold text-white">{{ __('ui.workspace.choose_codex_account') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-400">{{ __('ui.workspace.codex_account_help') }}</p>
                    @if ($errors->has('auth_mode'))
                        <p class="mt-4 rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ $errors->first('auth_mode') }}</p>
                    @endif
                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        @php($companySelectable = $companyCodexEnabled && in_array($companyCodex['state'] ?? 'unavailable', ['available', 'owned_by_me'], true))
                        <form method="POST" action="{{ route('workspace.auth-mode') }}" data-codex-account-form data-company-codex-choice data-company-state="{{ $companyCodex['state'] ?? 'unavailable' }}" data-status-url="{{ route('workspace.runtime-status') }}">
                            @csrf
                            <input type="hidden" name="auth_mode" value="company">
                            <input type="hidden" name="auth_attempt" value="{{ $authModeAttempt }}">
                            <button class="workspace-action flex h-full min-h-40 w-full flex-col items-start rounded-2xl border border-amber-300/35 bg-amber-300/10 p-5 text-left transition enabled:hover:border-amber-200 enabled:hover:bg-amber-300/15 disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled(! $companySelectable) data-codex-account-submit data-company-codex-button>
                                <span class="text-lg font-semibold text-amber-100">{{ __('ui.workspace.company_account') }}</span>
                                <span class="mt-2 text-sm leading-6 text-slate-300">{{ __('ui.workspace.company_account_help') }}</span>
                                @if (($companyCodex['state'] ?? 'unavailable') === 'occupied')
                                    <span class="mt-auto pt-4 text-xs font-semibold text-red-300" data-company-codex-label>{{ __('ui.workspace.company_occupied') }}</span>
                                    <span class="mt-1 text-xs text-slate-400">{{ __('ui.workspace.company_single_user') }}</span>
                                @elseif (! $companyCodexEnabled || ($companyCodex['state'] ?? 'unavailable') === 'unavailable')
                                    <span class="mt-auto pt-4 text-xs font-semibold text-red-300">{{ __('ui.workspace.company_unavailable') }}</span>
                                @elseif (($companyCodex['state'] ?? '') === 'owned_by_me')
                                    <span class="mt-auto pt-4 text-xs font-semibold text-emerald-300" data-company-codex-label>{{ __('ui.workspace.company_owned_by_me') }}</span>
                                @else
                                    <span class="mt-auto pt-4 text-xs font-semibold text-amber-300" data-company-codex-label>{{ __('ui.workspace.company_managed') }}</span>
                                @endif
                            </button>
                        </form>
                        <form method="POST" action="{{ route('workspace.auth-mode') }}" data-codex-account-form>
                            @csrf
                            <input type="hidden" name="auth_mode" value="personal">
                            <input type="hidden" name="auth_attempt" value="{{ $authModeAttempt }}">
                            <button class="workspace-action flex h-full min-h-40 w-full flex-col items-start rounded-2xl border border-sky-300/30 bg-sky-300/10 p-5 text-left transition hover:border-sky-200 hover:bg-sky-300/15 disabled:cursor-not-allowed disabled:opacity-45" type="submit" data-codex-account-submit>
                                <span class="text-lg font-semibold text-sky-100">{{ __('ui.workspace.personal_account') }}</span>
                                <span class="mt-2 text-sm leading-6 text-slate-300">{{ __('ui.workspace.personal_account_help') }}</span>
                                <span class="mt-auto pt-4 text-xs font-semibold text-sky-300">{{ __('ui.workspace.personal_isolated') }}</span>
                            </button>
                        </form>
                    </div>
                    <a class="mt-6 inline-flex text-sm text-slate-400 hover:text-slate-200" href="{{ route('workspace') }}">{{ __('ui.workspace.back_projects') }}</a>
                </section>
            </div>
            <div class="fixed inset-0 z-[80] hidden items-center justify-center overflow-hidden bg-slate-950/95 px-6 backdrop-blur-md" role="status" aria-live="assertive" aria-hidden="true" data-testid="codex-account-loading" data-codex-account-loading>
                <div class="relative flex max-w-xl flex-col items-center text-center">
                    <span class="h-16 w-16 animate-spin rounded-full border-4 border-sky-300/20 border-t-sky-300 motion-reduce:animate-none" aria-hidden="true"></span>
                    <h2 class="mt-7 text-2xl font-semibold text-white">{{ __('ui.workspace.account_loading') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-400">{{ __('ui.workspace.account_loading_help') }}</p>
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-amber-300">{{ __('ui.workspace.secure_workspace') }}</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ __('ui.workspace.isolated_terminal') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-400">{{ __('ui.workspace.terminal_intro', ['project' => $project->name, 'directory' => $project->directory_name]) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($enabled && $localAiReservation)
                    <div
                        class="inline-flex h-9 w-fit items-center gap-2 rounded-lg border px-3 text-xs font-semibold transition-colors {{ $localAiEnabled ? 'border-emerald-400/60 bg-emerald-400/10 text-emerald-200' : 'border-red-400/60 bg-red-400/10 text-red-200' }}"
                        role="status"
                        aria-live="polite"
                        data-local-ai-countdown
                        data-status-url="{{ route('workspace.runtime-status') }}"
                        data-starts-at="{{ $localAiReservation->starts_at->utc()->toIso8601String() }}"
                        data-server-now="{{ $serverNow }}"
                        data-phase="{{ $localAiPhase }}"
                        data-initial-ready="{{ $localAiEnabled ? 'true' : 'false' }}"
                        data-terminal-refresh-enabled="{{ $authMode !== null ? 'true' : 'false' }}"
                    >
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $localAiEnabled ? 'bg-emerald-400' : 'bg-red-400' }}" aria-hidden="true" data-local-ai-countdown-indicator></span>
                        <span class="font-mono tabular-nums" data-local-ai-countdown-label>
                            @if ($localAiEnabled)
                                {{ __('ui.workspace.local_ai_ready') }}
                            @elseif ($localAiPhase === 'countdown')
                                {{ __('ui.workspace.local_ai_countdown', ['time' => '--:--:--']) }}
                            @elseif ($localAiPhase === 'failed')
                                {{ __('ui.workspace.local_ai_start_failed') }}
                            @else
                                {{ __('ui.workspace.local_ai_starting') }}
                            @endif
                        </span>
                    </div>
                @endif
                <button class="workspace-action inline-flex rounded-lg border border-fuchsia-300/30 bg-fuchsia-300/10 px-3 py-1.5 text-xs font-semibold text-fuchsia-100 transition hover:border-fuchsia-200 hover:bg-fuchsia-300/15" type="button" data-style-library-open>{{ __('ui.workspace.style_library') }}</button>
                <a class="workspace-action inline-flex rounded-lg border border-sky-400/25 px-3 py-1.5 text-xs font-semibold text-sky-200" href="{{ route('workspace.videos.index') }}" target="_blank" rel="noopener noreferrer">{{ __('ui.workspace.video_library') }}</a>
                <a class="workspace-action inline-flex rounded-lg border border-white/15 px-3 py-1.5 text-xs font-semibold text-slate-300" href="{{ route('workspace') }}">{{ __('ui.workspace.switch_project') }}</a>
                @if ($authMode)
                    <a class="workspace-action inline-flex rounded-lg border border-amber-300/25 px-3 py-1.5 text-xs font-semibold text-amber-200" href="{{ route('workspace.terminal') }}">{{ __('ui.workspace.reselect_account') }}</a>
                @endif
                @if ($current?->status === \App\Enums\ReservationStatus::Active)
                    <x-reservation-extend-control :reservation="$current" :options="$extensionOptions" />
                @endif
                @if ($workspaceRuntime?->status === 'running')
                    <x-workspace-stop-control :reservation="$current" />
                @endif
                @if ($current && in_array($current->status, [\App\Enums\ReservationStatus::Confirmed, \App\Enums\ReservationStatus::Provisioning, \App\Enums\ReservationStatus::Active], true))
                    <x-workspace-abandon-control :reservation="$current" />
                @endif
                <span class="inline-flex w-fit items-center rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-300">
                    @if (! $enabled)
                        {{ __('ui.common.disabled') }}
                    @elseif ($authMode === 'company')
                        {{ __('ui.workspace.company_badge') }}
                    @elseif ($authMode === 'personal')
                        {{ __('ui.workspace.personal_badge') }}
                    @else
                        {{ __('ui.workspace.isolation_enabled') }}
                    @endif
                </span>
            </div>
        </div>

        <div class="fixed inset-0 z-[70] hidden p-3 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="style-library-title" data-testid="style-library-modal" data-style-library-modal>
            <div class="absolute inset-0 bg-slate-950/90 backdrop-blur-md" aria-hidden="true" data-style-library-backdrop></div>
            <section class="relative mx-auto flex h-[94vh] w-full max-w-7xl flex-col overflow-hidden rounded-3xl border border-white/15 bg-slate-900 shadow-2xl">
                <header class="flex shrink-0 items-start justify-between gap-6 border-b border-white/10 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-fuchsia-300">{{ __('ui.workspace.style_library') }}</p>
                        <h2 id="style-library-title" class="mt-2 text-2xl font-semibold text-white">{{ __('ui.workspace.style_library_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">{{ __('ui.workspace.style_library_intro') }}</p>
                    </div>
                    <button class="workspace-action flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/15 bg-white/5 text-2xl leading-none text-slate-200 transition hover:border-white/35 hover:bg-white/10 hover:text-white" type="button" aria-label="{{ __('ui.workspace.close_style_library') }}" data-style-library-close>×</button>
                </header>

                <div class="min-h-0 flex-1 overflow-hidden px-6 py-4">
                    <div class="grid h-full grid-cols-3 gap-3 overflow-y-auto pr-2" style="grid-auto-rows: 21rem;" data-style-library-grid data-style-library-scroll>
                        @foreach ($styles as $index => $style)
                            <article class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-white/10 bg-slate-950/70" data-style-card data-style-index="{{ $index }}">
                                <div class="relative min-h-0 w-full flex-1 basis-0 overflow-hidden bg-black" data-style-media-slot>
                                    @if ($style['demo_url'])
                                        <video class="absolute inset-0 h-full w-full object-cover" controls playsinline preload="metadata" data-style-video>
                                            <source src="{{ $style['demo_url'] }}" type="video/mp4">
                                            {{ __('ui.workspace.video_unsupported') }}
                                        </video>
                                    @else
                                        <div class="absolute inset-0 flex h-full w-full flex-col items-center justify-center gap-2 border-b border-white/10 bg-gradient-to-br from-slate-950 via-slate-950 to-fuchsia-950/30 px-4 text-center text-xs text-slate-500" data-style-demo-unavailable>
                                            <span class="flex h-10 w-10 items-center justify-center rounded-full border border-white/10 bg-white/5 text-base text-slate-600" aria-hidden="true">▶</span>
                                            <span>{{ __('ui.workspace.style_demo_unavailable') }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="shrink-0 p-3">
                                    <h3 class="truncate text-sm font-semibold text-white">{{ $style['title'] }}</h3>
                                    <p class="mt-1 line-clamp-1 text-xs leading-4 text-slate-400">{{ $style['description'] }}</p>
                                    <div class="mt-2 flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 p-1.5">
                                        <code class="min-w-0 flex-1 truncate text-xs text-fuchsia-200">{{ '$'.$style['skill'] }}</code>
                                        <button class="workspace-action shrink-0 rounded-md border border-fuchsia-300/25 px-2 py-1 text-xs font-semibold text-fuchsia-100 transition hover:border-fuchsia-200" type="button" data-style-copy data-skill-value="{{ '$'.$style['skill'] }}">{{ __('ui.workspace.copy_skill_name') }}</button>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                <footer class="shrink-0 border-t border-white/10 px-6 py-4">
                    <p class="text-xs text-slate-500">{{ __('ui.workspace.style_library_close_hint') }}</p>
                </footer>
            </section>
        </div>

        @if (session('status'))
            <div class="rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
        @endif

        @if (! $enabled)
            <section class="rounded-2xl border border-white/10 bg-white/5 p-8">
                <h2 class="text-xl font-semibold">{{ __('ui.workspace.not_enabled') }}</h2>
                <p class="mt-3 text-slate-400">{{ __('ui.workspace.runtime_disabled') }}</p>
            </section>
        @elseif (! $current && ! $canEnterTerminal)
            <section class="rounded-2xl border border-white/10 bg-white/5 p-8">
                <h2 class="text-xl font-semibold">{{ __('ui.workspace.no_active_window') }}</h2>
                <p class="mt-3 text-slate-400">{{ __('ui.workspace.no_active_help') }}</p>
                @if ($next)
                    <p class="mt-4 text-sm text-slate-300">{{ __('ui.common.next') }}: {{ $next->starts_at->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.date_time')) }} – {{ $next->ends_at->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.time')) }}</p>
                @endif
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <a class="workspace-action inline-flex rounded-lg bg-amber-300 px-4 py-2 text-sm font-semibold text-slate-950" href="{{ route('reservations.create') }}">{{ __('ui.reservations.book_time') }}</a>
                    @if ($authMode)
                        <form method="POST" action="{{ route('workspace.auth-mode') }}">
                            @csrf
                            <input type="hidden" name="auth_mode" value="{{ $authMode }}">
                            <button class="workspace-action inline-flex rounded-lg bg-emerald-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-emerald-200" type="submit" data-testid="enter-workspace-without-reservation">{{ __('ui.workspace.enter_without_reservation') }}</button>
                        </form>
                    @endif
                </div>
            </section>
        @else
            @if ($current)
            <section class="grid gap-4 rounded-2xl border border-white/10 bg-white/5 p-5 md:grid-cols-4">
                <div><p class="text-xs uppercase tracking-wider text-slate-500">{{ __('ui.reservations.reservation') }}</p><p class="mt-1 font-mono text-sm text-slate-200">{{ Str::limit($current->id, 13, '') }}</p></div>
                <div><p class="text-xs uppercase tracking-wider text-slate-500">{{ __('ui.common.window') }}</p><p class="mt-1 text-sm text-slate-200">{{ $current->starts_at->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.time_short')) }} – {{ $current->ends_at->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.time')) }}</p></div>
                <div><p class="text-xs uppercase tracking-wider text-slate-500">{{ __('ui.common.state') }}</p><p class="mt-1 text-sm font-semibold text-amber-300">{{ __('ui.statuses.'.$current->status->value) }}</p></div>
                <div><p class="text-xs uppercase tracking-wider text-slate-500">{{ __('ui.common.broker') }}</p><p class="mt-1 text-sm font-semibold text-sky-300">Qwen · H3 video · Z-Image-Turbo</p></div>
            </section>
            @endif

            @if ($canEnterTerminal)
                <div class="rounded-2xl border border-violet-300/20 bg-violet-300/5 p-5" data-workspace-session-history data-index-url="{{ route('workspace.sessions.index') }}" data-select-url="{{ route('workspace.sessions.select') }}" data-delete-url="{{ url('/workspace/sessions') }}" data-switch-confirm="{{ __('ui.workspace.session_switch_confirm') }}">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-violet-300">{{ __('ui.workspace.codex_sessions') }}</p>
                            <h2 class="mt-2 text-lg font-semibold text-white">{{ __('ui.workspace.continue_session') }}</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a class="workspace-action inline-flex shrink-0 items-center justify-center rounded-lg bg-emerald-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-emerald-200" href="#workspace-terminal" data-enter-workspace>
                                <span class="mr-2 text-base leading-none" aria-hidden="true">↓</span>{{ __('ui.workspace.enter_workspace') }}
                            </a>
                            <button class="workspace-action inline-flex shrink-0 items-center justify-center rounded-lg border border-violet-300/30 px-4 py-2.5 text-sm font-semibold text-violet-100 transition hover:border-violet-200 hover:bg-violet-300/10" type="button" aria-expanded="true" aria-controls="workspace-session-history-body" data-session-toggle>
                                <span class="mr-2 text-base leading-none" aria-hidden="true" data-session-toggle-icon>−</span><span data-session-toggle-label>{{ __('ui.workspace.collapse_sessions') }}</span>
                            </button>
                            <button class="workspace-action inline-flex shrink-0 items-center justify-center rounded-lg bg-violet-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-violet-200 disabled:cursor-not-allowed disabled:opacity-45" type="button" data-session-new>
                                <span class="mr-2 text-base leading-none">＋</span>{{ __('ui.workspace.new_blank_session') }}
                            </button>
                        </div>
                    </div>
                    <div id="workspace-session-history-body" data-session-body>
                        <p class="mt-3 text-sm text-slate-400">{{ __('ui.workspace.session_history_help') }}</p>
                        <p class="mt-4 text-sm text-slate-400" role="status" aria-live="polite" data-session-status>{{ __('ui.workspace.loading_sessions') }}</p>
                        <div class="mt-4 grid max-h-[19.25rem] gap-3 overflow-y-auto overscroll-contain pr-2 [grid-auto-rows:9.25rem] md:grid-cols-2 xl:grid-cols-3" data-session-list></div>
                    </div>
                    <noscript><p class="mt-4 text-sm text-amber-200">{{ __('ui.workspace.session_javascript_required') }}</p></noscript>
                </div>
            @endif

            @if ($canStart)
                <section class="rounded-2xl border border-amber-300/20 bg-amber-300/5 p-8 text-center">
                    <h2 class="text-xl font-semibold">{{ $current->workspace_stopped_at ? __('ui.workspace.stopped') : __('ui.workspace.reservation_ready') }}</h2>
                    <p class="mt-2 text-sm text-slate-400">
                        @if ($current->workspace_stopped_at)
                            {{ __('ui.workspace.restart_help', ['time' => $current->ends_at->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.time'))]) }}
                        @else
                            {{ __('ui.workspace.start_help') }}
                        @endif
                    </p>
                    <form class="mt-5" method="POST" action="{{ route('workspace.start') }}">
                        @csrf
                        <button class="workspace-action rounded-lg bg-amber-300 px-5 py-2.5 text-sm font-semibold text-slate-950" type="submit">{{ $current->workspace_stopped_at ? __('ui.workspace.restart') : __('ui.workspace.start') }}</button>
                    </form>
                </section>
            @elseif ($canEnterTerminal)
                <section class="rounded-2xl border border-sky-400/20 bg-sky-400/5 p-5" data-workspace-media-upload data-upload-url="{{ route('workspace.media.store') }}">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-sky-200">{{ __('ui.workspace.upload_media') }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ __('ui.workspace.upload_help') }}</p>
                        </div>
                        <form class="flex w-full flex-col gap-3 lg:max-w-3xl lg:flex-row lg:items-center" data-media-upload-form>
                            <label class="group flex min-h-20 flex-1 cursor-pointer items-center gap-3 rounded-xl border border-dashed border-sky-300/30 bg-slate-950/70 px-4 py-3 transition hover:border-sky-300/70" data-media-dropzone>
                                <input class="sr-only" type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/x-m4v,video/webm,video/quicktime" data-media-input>
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-400/10 text-xl text-sky-200">＋</span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-200" data-media-label>{{ __('ui.workspace.choose_media') }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500" data-media-detail>{{ __('ui.workspace.upload_target', ['directory' => $project->directory_name]) }}</span>
                                </span>
                            </label>
                            <button class="workspace-action rounded-lg bg-sky-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition enabled:hover:bg-sky-200 disabled:cursor-not-allowed disabled:opacity-40" type="submit" disabled data-media-upload-button>{{ __('ui.workspace.upload_add') }}</button>
                        </form>
                    </div>
                    <div class="mt-3 hidden items-center gap-3 rounded-xl border border-white/10 bg-slate-950/60 p-3" data-media-preview>
                        <img class="hidden h-14 w-14 rounded-lg object-cover" alt="{{ __('ui.workspace.selected_preview') }}" data-media-preview-image>
                        <video class="hidden h-14 w-24 rounded-lg object-cover" muted playsinline preload="metadata" aria-label="{{ __('ui.workspace.selected_preview') }}" data-media-preview-video></video>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-slate-200" data-media-preview-name></p>
                            <p class="text-xs text-slate-500" data-media-preview-size></p>
                        </div>
                    </div>
                    <div class="mt-3 hidden rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3" data-media-upload-result>
                        <p class="text-sm text-emerald-100" data-media-upload-status></p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <code class="min-w-0 flex-1 overflow-x-auto rounded bg-black/30 px-2 py-1 text-xs text-emerald-200" data-media-mention-command></code>
                            <button class="workspace-action rounded border border-emerald-300/30 px-2.5 py-1 text-xs font-semibold text-emerald-100" type="button" data-media-copy-command>{{ __('ui.workspace.copy_command') }}</button>
                        </div>
                    </div>
                    <p class="mt-3 hidden text-sm text-red-200" role="alert" data-media-upload-error></p>
                </section>
                <section id="workspace-terminal" class="workspace-terminal-window scroll-mt-6 overflow-hidden rounded-2xl border {{ $localAiEnabled ? 'border-emerald-400' : 'border-red-400' }} bg-black shadow-2xl" tabindex="-1" data-testid="workspace-terminal-window" data-local-ai-terminal-border data-runtime-status-url="{{ route('workspace.runtime-status') }}">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 bg-slate-950 px-4 py-3" data-terminal-copy>
                        <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $localAiEnabled ? 'bg-emerald-400' : 'bg-red-400' }}" data-local-ai-terminal-indicator></span><span class="text-sm font-medium text-slate-200">movie-workspace · {{ $authMode === 'company' ? __('ui.workspace.company_badge') : __('ui.workspace.personal_badge') }}</span></div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <span class="mr-1 text-xs text-slate-500">Codex {{ config('movie.codex_version') }} · movie_workspace · never</span>
                            <button class="workspace-action rounded border border-sky-300/30 px-2.5 py-1 text-xs font-semibold text-sky-100 transition hover:border-sky-300/70" type="button" data-terminal-copy-open>{{ __('ui.workspace.copy_terminal_text') }}</button>
                            <button class="workspace-action rounded border border-sky-300/30 px-2.5 py-1 text-xs font-semibold text-sky-100 transition hover:border-sky-300/70" type="button" data-terminal-copy-screen>{{ __('ui.workspace.copy_terminal_screen') }}</button>
                            <span class="min-w-24 text-right text-xs text-emerald-300" role="status" aria-live="polite" data-terminal-copy-status></span>
                        </div>
                    </div>
                    <div class="hidden border-b border-sky-300/20 bg-slate-950 p-4" data-terminal-copy-panel>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-slate-400">{{ __('ui.workspace.copy_terminal_help') }}</p>
                            <div class="flex items-center gap-2">
                                <button class="workspace-action rounded bg-sky-300 px-3 py-1.5 text-xs font-semibold text-slate-950" type="button" data-terminal-copy-all>{{ __('ui.workspace.copy_all_terminal_text') }}</button>
                                <button class="workspace-action rounded border border-white/15 px-3 py-1.5 text-xs text-slate-200" type="button" data-terminal-copy-close>{{ __('ui.common.cancel') }}</button>
                            </div>
                        </div>
                        <textarea class="h-56 w-full resize-y rounded-lg border border-white/10 bg-black p-3 font-mono text-xs leading-relaxed text-slate-100 outline-none focus:border-sky-300/60" readonly spellcheck="false" aria-label="{{ __('ui.workspace.copy_terminal_text') }}" data-terminal-copy-text></textarea>
                    </div>
                    <div class="relative min-h-screen bg-black" data-terminal-readiness data-loading-slow="{{ __('ui.workspace.cli_loading_slow') }}">
                        <iframe id="workspace-terminal-frame" class="workspace-terminal-frame bg-black" src="/terminal/" title="Codex Web Terminal" referrerpolicy="same-origin" allow="clipboard-read; clipboard-write" tabindex="-1" aria-busy="true"></iframe>
                        <div class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-slate-950/95 px-6 text-center backdrop-blur-sm" role="status" aria-live="polite" data-terminal-loading>
                            <span class="h-11 w-11 animate-spin rounded-full border-4 border-sky-300/25 border-t-sky-300 motion-reduce:animate-none" aria-hidden="true"></span>
                            <p class="mt-5 text-base font-semibold text-sky-100">{{ __('ui.workspace.cli_loading') }}</p>
                            <p class="mt-2 max-w-xl text-sm text-slate-400" data-terminal-loading-message>
                                {{ $workspaceRuntime?->session_mode === 'resume' ? __('ui.workspace.cli_loading_resume') : __('ui.workspace.cli_loading_new') }}
                            </p>
                        </div>
                    </div>
                </section>
                <section class="rounded-2xl border border-sky-400/20 bg-sky-400/5 p-5 text-sm text-slate-300">
                    @if ($authMode === 'company')
                        <p class="font-semibold text-sky-300">{{ __('ui.workspace.company_badge') }}</p>
                        <p class="mt-2">{{ __('ui.workspace.company_runtime_help') }}</p>
                    @else
                        <p class="font-semibold text-sky-300">{{ __('ui.workspace.personal_badge') }}</p>
                        <p class="mt-2">{{ __('ui.workspace.personal_runtime_help') }}</p>
                    @endif
                </section>
            @else
                <section class="rounded-2xl border border-white/10 bg-white/5 p-8 text-center">
                    <h2 class="text-xl font-semibold">{{ __('ui.workspace.being_prepared') }}</h2>
                    <p class="mt-2 text-sm text-slate-400">{{ __('ui.workspace.prepared_help') }}</p>
                    <a class="workspace-action mt-5 inline-flex rounded-lg border border-white/15 px-4 py-2 text-sm" href="{{ route('workspace.terminal') }}">{{ __('ui.common.refresh_status') }}</a>
                </section>
            @endif
        @endif
    </div>
</x-layouts.app>
