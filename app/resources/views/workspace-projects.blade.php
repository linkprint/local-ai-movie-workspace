<x-layouts.app :title="__('ui.workspace.choose_project_title')">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
            <p class="text-sm font-medium uppercase tracking-[0.22em] text-amber-300">{{ __('ui.workspace.entry') }}</p>
            <h1 class="mt-2 text-3xl font-semibold">{{ $enabled ? __('ui.workspace.choose_project_folder') : __('ui.workspace.secure_workspace') }}</h1>
            @if ($enabled)
                <p class="mt-2 max-w-3xl text-sm text-slate-400">
                    {{ __('ui.workspace.private_root_help', ['root' => $profile->root_directory]) }}
                </p>
            @endif
            </div>
            @if ($enabled)
                <a class="inline-flex w-fit rounded-lg border border-sky-400/25 px-4 py-2 text-sm font-semibold text-sky-200" href="{{ route('workspace.videos.index') }}">{{ __('ui.workspace.open_video_library') }}</a>
            @endif
        </div>

        @if ($activeWorkspace)
            <section class="rounded-2xl border border-amber-300/25 bg-amber-300/5 p-5 text-sm text-amber-100">
                <p class="font-semibold">{{ __('ui.workspace.active_at', ['directory' => $profile->selectedProject?->directory_name ?? 'selected-project']) }}</p>
                <p class="mt-1 text-amber-100/70">
                    {{ __('ui.workspace.active_lock_help', ['time' => ($activeWorkspace->idle_expires_at ?? now())->copy()->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.date_time'))]) }}
                </p>
                <div class="mt-4">
                    <x-workspace-stop-control :reservation="$activeReservation" />
                </div>
            </section>
        @endif

        @if (! $enabled)
            <section class="rounded-2xl border border-white/10 bg-white/5 p-8">
                <h2 class="text-xl font-semibold">{{ __('ui.workspace.not_enabled') }}</h2>
                <p class="mt-3 text-slate-400">{{ __('ui.workspace.runtime_disabled') }}</p>
            </section>
        @elseif ($projects->isEmpty())
            <section class="rounded-2xl border border-amber-300/20 bg-amber-300/5 p-8">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-300">{{ __('ui.workspace.first_visit') }}</p>
                <h2 class="mt-2 text-xl font-semibold">{{ __('ui.workspace.create_first') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ __('ui.workspace.project_fields_help') }}</p>
                <form class="mt-6 max-w-2xl space-y-4" method="POST" action="{{ route('workspace.projects.store') }}">
                    @csrf
                    <div>
                        <label class="text-sm font-medium text-slate-200" for="project-name">{{ __('ui.workspace.project_name') }}</label>
                        <p class="mt-1 text-xs text-slate-500">{{ __('ui.workspace.project_name_help') }}</p>
                        <input class="mt-2 w-full rounded-lg border border-white/15 bg-slate-950 px-4 py-2.5 text-slate-100 placeholder:text-slate-600" id="project-name" name="name" maxlength="80" required autofocus placeholder="e.g. 七月流火" value="{{ old('name') }}">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-200" for="project-directory-name">{{ __('ui.workspace.directory_name') }}</label>
                        <p class="mt-1 text-xs text-slate-500">{{ __('ui.workspace.directory_rules') }}</p>
                        <div class="mt-2 flex items-center rounded-lg border border-white/15 bg-slate-950 focus-within:border-amber-300/60">
                            <span class="pl-4 font-mono text-sm text-slate-500">/workspace/</span>
                            <input class="min-w-0 flex-1 border-0 bg-transparent px-1 py-2.5 font-mono text-slate-100 outline-none placeholder:text-slate-600" id="project-directory-name" name="directory_name" maxlength="64" pattern="[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?" required autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="qi-yue-liu-huo" value="{{ old('directory_name') }}">
                        </div>
                    </div>
                    <button class="rounded-lg bg-amber-300 px-5 py-2.5 text-sm font-semibold text-slate-950" type="submit">{{ __('ui.workspace.create_enter') }}</button>
                </form>
            </section>
        @else
            <section class="rounded-2xl border border-white/10 bg-white/5 p-6">
                <h2 class="text-xl font-semibold">{{ __('ui.workspace.your_projects') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ __('ui.workspace.project_selection_help') }}</p>
                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    @foreach ($projects as $project)
                        <div class="rounded-xl border border-white/10 bg-slate-950/60 p-4">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-slate-100">{{ $project->name }}</p>
                                    <p class="mt-1 truncate font-mono text-xs text-slate-500">/workspace/{{ $project->directory_name }}</p>
                                </div>
                                <form method="POST" action="{{ route('workspace.projects.select', $project) }}">
                                    @csrf
                                    <button class="shrink-0 rounded-lg bg-amber-300 px-4 py-2 text-sm font-semibold text-slate-950" type="submit">{{ __('ui.workspace.enter') }}</button>
                                </form>
                            </div>
                            <details class="mt-4 border-t border-white/10 pt-3">
                                <summary class="cursor-pointer text-sm font-medium text-slate-400">{{ __('ui.workspace.edit_project') }}</summary>
                                <form class="mt-4 space-y-3" method="POST" action="{{ route('workspace.projects.update', $project) }}">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label class="text-xs font-medium text-slate-300" for="project-name-{{ $project->id }}">{{ __('ui.workspace.project_name') }}</label>
                                        <input class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950 px-3 py-2 text-sm text-slate-100" id="project-name-{{ $project->id }}" name="name" maxlength="80" required value="{{ $project->name }}">
                                    </div>
                                    <div>
                                        <label class="text-xs font-medium text-slate-300" for="project-directory-{{ $project->id }}">{{ __('ui.workspace.directory_name') }}</label>
                                        <input class="mt-1 w-full rounded-lg border border-white/15 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-100 read-only:cursor-not-allowed read-only:opacity-60" id="project-directory-{{ $project->id }}" name="directory_name" maxlength="64" pattern="[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?" required autocapitalize="none" autocomplete="off" spellcheck="false" value="{{ $project->directory_name }}" @readonly($activeWorkspace)>
                                        @if ($activeWorkspace)
                                            <p class="mt-1 text-xs text-amber-200/70">{{ __('ui.workspace.rename_locked') }}</p>
                                        @else
                                            <p class="mt-1 text-xs text-slate-500">{{ __('ui.workspace.directory_rules_short') }}</p>
                                        @endif
                                    </div>
                                    <button class="rounded-lg border border-amber-300/40 px-4 py-2 text-sm font-semibold text-amber-200" type="submit">{{ __('ui.workspace.save_project') }}</button>
                                </form>
                                <div class="mt-5 rounded-lg border border-red-400/20 bg-red-400/5 p-4">
                                    <h3 class="text-sm font-semibold text-red-200">{{ __('ui.workspace.delete_project') }}</h3>
                                    <p class="mt-1 text-xs text-slate-400">{{ __('ui.workspace.delete_project_help', ['directory' => $project->directory_name]) }}</p>
                                    @if ($activeWorkspace)
                                        <p class="mt-3 text-xs font-medium text-amber-200/80">{{ __('ui.workspace.deletion_locked') }}</p>
                                    @else
                                        <form class="mt-3 space-y-3" method="POST" action="{{ route('workspace.projects.destroy', $project) }}">
                                            @csrf
                                            @method('DELETE')
                                            <div>
                                                <label class="text-xs font-medium text-red-100" for="delete-confirmation-{{ $project->id }}">{{ __('ui.workspace.type_delete') }}</label>
                                                <input class="mt-1 w-full rounded-lg border border-red-400/25 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-100" id="delete-confirmation-{{ $project->id }}" name="delete_confirmation" pattern="delete" required autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="delete">
                                            </div>
                                            <button class="rounded-lg bg-red-500 px-4 py-2 text-sm font-semibold text-white hover:bg-red-400" type="submit">{{ __('ui.workspace.delete_project') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                        </div>
                    @endforeach
                </div>
            </section>

            <details class="rounded-2xl border border-white/10 bg-white/5 p-6">
                <summary class="cursor-pointer font-semibold text-slate-200">{{ __('ui.workspace.create_another') }}</summary>
                <form class="mt-5 max-w-2xl space-y-4" method="POST" action="{{ route('workspace.projects.store') }}">
                    @csrf
                    <div>
                        <label class="text-sm font-medium text-slate-200" for="another-project-name">{{ __('ui.workspace.project_name') }}</label>
                        <input class="mt-2 w-full rounded-lg border border-white/15 bg-slate-950 px-4 py-2.5 text-slate-100 placeholder:text-slate-600" id="another-project-name" name="name" maxlength="80" required placeholder="e.g. 七月流火" value="{{ old('name') }}">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-200" for="another-project-directory">{{ __('ui.workspace.directory_name') }}</label>
                        <p class="mt-1 text-xs text-slate-500">{{ __('ui.workspace.directory_rules_short') }}</p>
                        <div class="mt-2 flex items-center rounded-lg border border-white/15 bg-slate-950 focus-within:border-amber-300/60">
                            <span class="pl-4 font-mono text-sm text-slate-500">/workspace/</span>
                            <input class="min-w-0 flex-1 border-0 bg-transparent px-1 py-2.5 font-mono text-slate-100 outline-none placeholder:text-slate-600" id="another-project-directory" name="directory_name" maxlength="64" pattern="[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?" required autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="qi-yue-liu-huo" value="{{ old('directory_name') }}">
                        </div>
                    </div>
                    <button class="rounded-lg border border-amber-300/40 px-5 py-2.5 text-sm font-semibold text-amber-200" type="submit">{{ __('ui.workspace.create_enter') }}</button>
                </form>
            </details>
        @endif
    </div>
</x-layouts.app>
