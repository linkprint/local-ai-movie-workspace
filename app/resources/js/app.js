import './bootstrap';

const encodedTranslations = document.body?.dataset.portalTranslations || '';
const translations = encodedTranslations
    ? JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(encodedTranslations), (character) => character.charCodeAt(0))))
    : {};
const t = (key, replacements = {}) => {
    let value = translations[key] || key;
    Object.entries(replacements).forEach(([name, replacement]) => {
        value = value.replaceAll(`:${name}`, String(replacement));
    });
    return value;
};

const reservationPicker = document.querySelector('[data-reservation-picker]');

if (reservationPicker) {
    const nodeInput = reservationPicker.querySelector('[data-node-value]');
    const nodeList = reservationPicker.querySelector('[data-node-list]');
    const dateSelect = reservationPicker.querySelector('[data-date-select]');
    const startSelect = reservationPicker.querySelector('[data-start-select]');
    const endSelect = reservationPicker.querySelector('[data-end-select]');
    const startValue = reservationPicker.querySelector('[data-start-value]');
    const immediateValue = reservationPicker.querySelector('[data-start-immediately]');
    const endValue = reservationPicker.querySelector('[data-end-value]');
    const reserveButton = reservationPicker.querySelector('[data-reserve-button]');
    const summary = reservationPicker.querySelector('[data-selection-summary]');
    const heading = reservationPicker.querySelector('[data-availability-heading]');
    const windows = reservationPicker.querySelector('[data-availability-windows]');
    let slots = [];
    let nodes = [];
    let requestController = null;
    let pendingStart = reservationPicker.dataset.oldStart || '';
    let pendingEnd = reservationPicker.dataset.oldEnd || '';
    let pendingNode = reservationPicker.dataset.oldNode || '';

    try {
        nodes = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(reservationPicker.dataset.initialNodes || ''), (character) => character.charCodeAt(0))));
    } catch {
        nodes = [];
    }

    const option = (label, value = '') => new Option(label, value);

    const setSummary = (message, tone = 'muted') => {
        summary.replaceChildren();
        const text = document.createElement('p');
        text.textContent = message;
        text.className = tone === 'error' ? 'text-sm text-red-200' : tone === 'ready' ? 'text-sm text-emerald-200' : 'text-sm text-slate-400';
        summary.append(text);
    };

    const setFormValues = () => {
        const selected = slots.find((slot) => slot.value === startSelect.value);
        startValue.value = startSelect.value;
        immediateValue.value = selected?.immediate ? '1' : '0';
        endValue.value = endSelect.value;
        reserveButton.disabled = !nodeInput.value || !startValue.value || !endValue.value;

        if (!nodeInput.value) {
            setSummary(t('select_server_first'));
            return;
        }

        if (!startValue.value) {
            setSummary(t('choose_start'));
            return;
        }

        if (!endValue.value) {
            setSummary(t('choose_end'));
            return;
        }

        const startLabel = startSelect.options[startSelect.selectedIndex]?.text || '';
        const endLabel = endSelect.options[endSelect.selectedIndex]?.text || '';
        setSummary(`${dateSelect.options[dateSelect.selectedIndex].text}: ${startLabel} → ${endLabel}`, 'ready');
    };

    const renderNodes = () => {
        nodeList.replaceChildren();

        nodes.forEach((node) => {
            const selected = node.id === nodeInput.value;
            const card = document.createElement('button');
            card.type = 'button';
            card.disabled = !node.selectable;
            card.dataset.nodeCard = '';
            card.dataset.nodeId = node.id;
            card.className = `group rounded-2xl border p-5 text-left transition ${selected ? 'border-amber-300 bg-amber-300/10 ring-2 ring-amber-300/20' : node.selectable ? 'border-white/10 bg-slate-950/50 hover:border-amber-300/50' : 'cursor-not-allowed border-red-400/20 bg-red-950/10 opacity-75'}`;
            card.setAttribute('aria-pressed', selected ? 'true' : 'false');

            const top = document.createElement('span');
            top.className = 'flex items-start justify-between gap-4';
            const icon = document.createElement('span');
            icon.className = 'flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-200';
            const computerIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            computerIcon.setAttribute('class', 'h-6 w-6');
            computerIcon.setAttribute('viewBox', '0 0 24 24');
            computerIcon.setAttribute('fill', 'none');
            computerIcon.setAttribute('stroke', 'currentColor');
            computerIcon.setAttribute('stroke-width', '1.5');
            computerIcon.setAttribute('stroke-linecap', 'round');
            computerIcon.setAttribute('stroke-linejoin', 'round');
            computerIcon.innerHTML = '<rect x="2.5" y="3.5" width="19" height="13" rx="2"/><path d="M8 20.5h8M12 16.5v4M6 13.5h12"/>';
            icon.append(computerIcon);
            icon.setAttribute('aria-hidden', 'true');
            const state = document.createElement('span');
            state.className = `rounded-full px-2.5 py-1 text-xs font-medium ${node.availability_state === 'idle' ? 'bg-emerald-400/10 text-emerald-200' : node.availability_state === 'busy' ? 'bg-amber-400/10 text-amber-200' : 'bg-red-400/10 text-red-200'}`;
            state.textContent = node.state_label;
            top.append(icon, state);

            const name = document.createElement('span');
            name.className = 'mt-4 block font-semibold text-white';
            name.textContent = node.display_name;
            card.append(top, name);

            if (Array.isArray(node.capability_labels) && node.capability_labels.length) {
                const capabilities = document.createElement('span');
                capabilities.className = 'mt-2 block text-xs text-slate-500';
                capabilities.textContent = node.capability_labels.join(' · ');
                card.append(capabilities);
            }

            card.addEventListener('click', () => {
                if (!node.selectable || nodeInput.value === node.id) return;
                nodeInput.value = node.id;
                pendingStart = '';
                pendingEnd = '';
                dateSelect.disabled = false;
                renderNodes();
                loadAvailability();
            });
            nodeList.append(card);
        });
    };

    const populateEnds = () => {
        const selected = slots.find((slot) => slot.value === startSelect.value);
        endSelect.replaceChildren(option(selected ? t('choose_end_option') : t('select_start')));
        endSelect.disabled = !selected;

        if (selected) {
            selected.ends.forEach((end) => endSelect.add(option(`${end.label} · ${end.duration}`, end.value)));

            if (pendingEnd && selected.ends.some((end) => end.value === pendingEnd)) {
                endSelect.value = pendingEnd;
            }
        }

        pendingEnd = '';
        setFormValues();
    };

    const renderWindows = (availability) => {
        windows.replaceChildren();
        heading.textContent = availability.date_label;

        if (availability.windows.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'rounded-xl border border-white/10 bg-white/5 p-4';
            const title = document.createElement('p');
            title.className = 'font-medium text-slate-200';
            title.textContent = t('no_windows');
            const detail = document.createElement('p');
            detail.className = 'mt-1 text-sm text-slate-500';
            detail.textContent = t('choose_another_date');
            empty.append(title, detail);
            windows.append(empty);
            return;
        }

        availability.windows.forEach((window) => {
            const card = document.createElement('div');
            card.className = 'rounded-xl border border-emerald-400/20 bg-emerald-950/20 p-4';
            const range = document.createElement('p');
            range.className = 'font-medium text-emerald-100';
            range.textContent = window.start_range;
            const detail = document.createElement('p');
            detail.className = 'mt-1 text-sm text-emerald-200/60';
            detail.textContent = t('end_by', { time: window.end_by });
            card.append(range, detail);
            windows.append(card);
        });
    };

    const loadAvailability = async () => {
        if (!nodeInput.value) {
            dateSelect.disabled = true;
            setSummary(t('select_server_first'));
            return;
        }
        requestController?.abort();
        requestController = new AbortController();
        slots = [];
        startSelect.replaceChildren(option(t('loading')));
        startSelect.disabled = true;
        endSelect.replaceChildren(option(t('select_start')));
        endSelect.disabled = true;
        reserveButton.disabled = true;
        startValue.value = '';
        immediateValue.value = '0';
        endValue.value = '';
        setSummary(t('loading_availability'));

        try {
            const url = new URL(reservationPicker.dataset.availabilityUrl, window.location.origin);
            url.searchParams.set('date', dateSelect.value);
            url.searchParams.set('compute_node_id', nodeInput.value);
            const response = await window.axios.get(url.toString(), {
                headers: { Accept: 'application/json' },
                signal: requestController.signal,
            });
            if (response.data.compute_node_id !== nodeInput.value) return;
            slots = response.data.slots;
            renderWindows(response.data);
            startSelect.replaceChildren(option(slots.length ? t('choose_start_option') : t('no_start_times')));
            slots.forEach((slot) => startSelect.add(option(slot.label, slot.value)));
            startSelect.disabled = slots.length === 0;

            if (pendingStart && slots.some((slot) => slot.value === pendingStart)) {
                startSelect.value = pendingStart;
            }

            if (startSelect.value) {
                populateEnds();
            } else {
                setSummary(slots.length ? t('available_start_times', { count: response.data.available_count }) : t('no_bookable_times'));
            }

            pendingStart = '';
        } catch (error) {
            if (error.code === 'ERR_CANCELED') {
                return;
            }

            windows.replaceChildren();
            heading.textContent = t('availability_unavailable');
            startSelect.replaceChildren(option(t('unable_load_times')));
            setSummary(t('load_failed'), 'error');
        }
    };

    dateSelect.addEventListener('change', () => {
        pendingStart = '';
        pendingEnd = '';
        loadAvailability();
    });
    startSelect.addEventListener('change', populateEnds);
    endSelect.addEventListener('change', setFormValues);
    reservationPicker.addEventListener('submit', (event) => {
        if (!nodeInput.value || !startValue.value || !endValue.value) {
            event.preventDefault();
            setSummary(t('choose_both'), 'error');
        }
    });

    const refreshNodes = async () => {
        try {
            const response = await window.axios.get(reservationPicker.dataset.nodesUrl, { headers: { Accept: 'application/json' } });
            nodes = response.data.nodes || [];
            const selected = nodes.find((node) => node.id === nodeInput.value);
            if (nodeInput.value && (!selected || !selected.selectable)) {
                nodeInput.value = '';
                slots = [];
                dateSelect.disabled = true;
                startSelect.disabled = true;
                endSelect.disabled = true;
                reserveButton.disabled = true;
                setSummary(t('selected_server_unavailable'), 'error');
            }
            renderNodes();
        } catch {
            // Keep the last known public status; reservation submission revalidates it.
        }
    };

    if (pendingNode && nodes.some((node) => node.id === pendingNode && node.selectable)) {
        nodeInput.value = pendingNode;
        dateSelect.disabled = false;
    } else {
        nodeInput.value = '';
    }
    pendingNode = '';
    renderNodes();
    if (nodeInput.value) loadAvailability();
    setInterval(refreshNodes, 15000);
}

const localAiCountdown = document.querySelector('[data-local-ai-countdown]');

if (localAiCountdown) {
    const label = localAiCountdown.querySelector('[data-local-ai-countdown-label]');
    const indicator = localAiCountdown.querySelector('[data-local-ai-countdown-indicator]');
    const terminalBorder = document.querySelector('[data-local-ai-terminal-border]');
    const terminalIndicator = document.querySelector('[data-local-ai-terminal-indicator]');
    let startsAt = Date.parse(localAiCountdown.dataset.startsAt || '');
    let serverOffset = Date.parse(localAiCountdown.dataset.serverNow || '') - Date.now();
    let phase = localAiCountdown.dataset.phase || 'countdown';
    let localAiEnabled = localAiCountdown.dataset.initialReady === 'true';
    const terminalRefreshEnabled = localAiCountdown.dataset.terminalRefreshEnabled === 'true';
    let refreshRequested = false;

    if (!Number.isFinite(serverOffset)) {
        serverOffset = 0;
    }

    const setTone = (ready) => {
        localAiCountdown.classList.remove(
            'border-red-400/60', 'bg-red-400/10', 'text-red-200',
            'border-emerald-400/60', 'bg-emerald-400/10', 'text-emerald-200',
        );
        localAiCountdown.classList.add(...(ready
            ? ['border-emerald-400/60', 'bg-emerald-400/10', 'text-emerald-200']
            : ['border-red-400/60', 'bg-red-400/10', 'text-red-200']));
        indicator?.classList.toggle('bg-emerald-400', ready);
        indicator?.classList.toggle('bg-red-400', !ready);
        terminalBorder?.classList.toggle('border-emerald-400', ready);
        terminalBorder?.classList.toggle('border-red-400', !ready);
        terminalIndicator?.classList.toggle('bg-emerald-400', ready);
        terminalIndicator?.classList.toggle('bg-red-400', !ready);
    };

    const formatRemaining = (milliseconds) => {
        const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const clock = [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');

        return days > 0 ? t('local_ai_countdown_days', { days, time: clock }) : clock;
    };

    const renderLocalAiStatus = () => {
        setTone(localAiEnabled);
        if (localAiEnabled) {
            label.textContent = t('local_ai_ready');
            return;
        }

        const remaining = startsAt - (Date.now() + serverOffset);
        if (phase === 'countdown' && Number.isFinite(remaining) && remaining > 0) {
            label.textContent = t('local_ai_countdown', { time: formatRemaining(remaining) });
        } else if (phase === 'failed') {
            label.textContent = t('local_ai_start_failed');
        } else if (phase === 'unavailable' || phase === 'disabled') {
            label.textContent = t('local_ai_unavailable');
        } else {
            label.textContent = t('local_ai_starting');
        }
    };

    const syncLocalAiStatus = async () => {
        try {
            const response = await window.axios.get(localAiCountdown.dataset.statusUrl, {
                headers: { Accept: 'application/json' },
            });
            const status = response.data;
            const nextServerNow = Date.parse(status.server_now || '');
            const nextStartsAt = Date.parse(status.starts_at || '');
            if (Number.isFinite(nextServerNow)) {
                serverOffset = nextServerNow - Date.now();
            }
            if (Number.isFinite(nextStartsAt)) {
                startsAt = nextStartsAt;
            }
            phase = status.phase || phase;
            localAiEnabled = status.local_ai_enabled === true;
            renderLocalAiStatus();

            if (terminalRefreshEnabled && localAiEnabled && !document.querySelector('#workspace-terminal-frame') && !refreshRequested) {
                refreshRequested = true;
                window.location.reload();
            }
        } catch (error) {
            renderLocalAiStatus();
        }
    };

    renderLocalAiStatus();
    const countdownTimer = window.setInterval(renderLocalAiStatus, 1000);
    const statusTimer = window.setInterval(syncLocalAiStatus, 3000);
    syncLocalAiStatus();
    window.addEventListener('pagehide', () => {
        window.clearInterval(countdownTimer);
        window.clearInterval(statusTimer);
    }, { once: true });
}

const workspaceTerminalFrame = document.querySelector('#workspace-terminal-frame');

if (workspaceTerminalFrame && !localAiCountdown) {
    const statusUrl = workspaceTerminalFrame.closest('[data-runtime-status-url]')?.dataset.runtimeStatusUrl;
    const heartbeat = () => window.axios.get(statusUrl, {
        headers: { Accept: 'application/json' },
    }).catch(() => {});
    const timer = window.setInterval(heartbeat, 60000);
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
}

const workspaceTerminalReadiness = document.querySelector('[data-terminal-readiness]');

if (workspaceTerminalFrame && workspaceTerminalReadiness) {
    const loading = workspaceTerminalReadiness.querySelector('[data-terminal-loading]');
    const loadingMessage = workspaceTerminalReadiness.querySelector('[data-terminal-loading-message]');
    const promptPattern = /(^|\n)\s*[›❯]\s|ask codex|what can i help|context left/i;
    const attentionPattern = /auth\.openai\.com|device[- ]code|codex login|login did not complete|failed to start|did not become ready|session selection is invalid|workspace project is unavailable/i;
    let finished = false;
    let alternateSince = 0;
    let stableSnapshot = '';
    let stableSamples = 0;

    document.body.dataset.terminalReady = 'false';
    workspaceTerminalFrame.style.pointerEvents = 'none';

    const terminalBufferText = (terminal) => {
        const buffer = terminal?.buffer?.active;
        if (!buffer) {
            return '';
        }
        const lines = [];
        const start = Math.max(0, buffer.viewportY || 0);
        const end = Math.min(buffer.length, start + (terminal.rows || buffer.length));
        for (let index = start; index < end; index += 1) {
            const line = buffer.getLine(index);
            if (!line) {
                continue;
            }
            const value = line.translateToString(true);
            if (line.isWrapped && lines.length) {
                lines[lines.length - 1] += value;
            } else {
                lines.push(value);
            }
        }
        return lines.join('\n');
    };

    const finishLoading = (state, terminal = null) => {
        if (finished) {
            return;
        }
        finished = true;
        window.clearInterval(readinessTimer);
        window.clearTimeout(slowTimer);
        loading?.classList.add('hidden');
        workspaceTerminalFrame.style.pointerEvents = '';
        workspaceTerminalFrame.removeAttribute('tabindex');
        workspaceTerminalFrame.setAttribute('aria-busy', 'false');
        document.body.dataset.terminalReady = state === 'ready' ? 'true' : 'false';
        document.dispatchEvent(new CustomEvent('workspace:terminal-readiness', {
            detail: { state },
        }));
        if (state === 'ready' && window.location.hash === '#workspace-terminal') {
            window.setTimeout(() => terminal?.focus?.(), 0);
        }
    };

    const inspectTerminal = () => {
        let terminal = null;
        let documentText = '';
        try {
            terminal = workspaceTerminalFrame.contentWindow?.term || null;
            documentText = workspaceTerminalFrame.contentDocument?.body?.innerText || '';
        } catch {
            return;
        }

        if (!terminal) {
            if (attentionPattern.test(documentText)) {
                finishLoading('attention');
            }
            return;
        }

        const buffer = terminal.buffer?.active;
        const text = terminalBufferText(terminal);
        if (attentionPattern.test(text)) {
            finishLoading('attention', terminal);
            return;
        }

        const snapshot = text.replace(/\s+/g, ' ').trim();
        const alternateActive = buffer?.type === 'alternate' || buffer === terminal.buffer?.alternate;
        if (!alternateActive || !snapshot) {
            alternateSince = 0;
            stableSnapshot = '';
            stableSamples = 0;
            return;
        }

        if (!alternateSince) {
            alternateSince = Date.now();
        }
        if (snapshot === stableSnapshot) {
            stableSamples += 1;
        } else {
            stableSnapshot = snapshot;
            stableSamples = 1;
        }

        const promptVisible = promptPattern.test(text);
        const stableInteractiveScreen = stableSamples >= 5 && Date.now() - alternateSince >= 1500;
        if (promptVisible || stableInteractiveScreen) {
            finishLoading('ready', terminal);
        }
    };

    const readinessTimer = window.setInterval(inspectTerminal, 200);
    const slowTimer = window.setTimeout(() => {
        if (loadingMessage) {
            loadingMessage.textContent = workspaceTerminalReadiness.dataset.loadingSlow;
        }
    }, 12000);
    workspaceTerminalFrame.addEventListener('load', () => {
        alternateSince = 0;
        stableSnapshot = '';
        stableSamples = 0;
        inspectTerminal();
    });
    inspectTerminal();
    window.addEventListener('pagehide', () => {
        window.clearInterval(readinessTimer);
        window.clearTimeout(slowTimer);
    }, { once: true });
}

const codexAccountChoice = document.querySelector('[data-codex-account-choice]');

if (codexAccountChoice) {
    const forms = [...codexAccountChoice.querySelectorAll('[data-codex-account-form]')];
    const buttons = [...codexAccountChoice.querySelectorAll('[data-codex-account-submit]')];
    const loading = document.querySelector('[data-codex-account-loading]');
    let submitting = false;

    const submitOnce = (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;
        codexAccountChoice.dataset.submitting = 'true';
        codexAccountChoice.setAttribute('aria-busy', 'true');
        buttons.forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });
        loading?.classList.remove('hidden');
        loading?.classList.add('flex');
        loading?.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('overflow-hidden');
    };

    forms.forEach((form) => form.addEventListener('submit', submitOnce));
}

const companyCodexChoice = document.querySelector('[data-company-codex-choice]');

if (companyCodexChoice) {
    const button = companyCodexChoice.querySelector('[data-company-codex-button]');
    const label = companyCodexChoice.querySelector('[data-company-codex-label]');
    let state = companyCodexChoice.dataset.companyState || 'unavailable';

    const render = () => {
        const selectable = state === 'available' || state === 'owned_by_me';
        const submitting = companyCodexChoice.closest('[data-codex-account-choice]')?.dataset.submitting === 'true';
        button.disabled = submitting || !selectable;
        if (label) {
            label.textContent = state === 'occupied'
                ? t('company_codex_occupied')
                : state === 'owned_by_me'
                    ? t('company_codex_owned_by_me')
                    : state === 'available'
                        ? t('company_codex_available')
                        : t('company_codex_unavailable');
        }
    };

    const sync = async () => {
        try {
            const response = await window.axios.get(companyCodexChoice.dataset.statusUrl, {
                headers: { Accept: 'application/json' },
            });
            state = response.data?.company_codex?.state || 'unavailable';
            render();
        } catch (error) {
            state = 'unavailable';
            render();
        }
    };

    render();
    const timer = window.setInterval(sync, 4000);
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
}

const workspaceSessionHistory = document.querySelector('[data-workspace-session-history]');

if (workspaceSessionHistory) {
    const list = workspaceSessionHistory.querySelector('[data-session-list]');
    const status = workspaceSessionHistory.querySelector('[data-session-status]');
    const newButton = workspaceSessionHistory.querySelector('[data-session-new]');
    const toggleButton = workspaceSessionHistory.querySelector('[data-session-toggle]');
    const toggleIcon = workspaceSessionHistory.querySelector('[data-session-toggle-icon]');
    const toggleLabel = workspaceSessionHistory.querySelector('[data-session-toggle-label]');
    const sessionBody = workspaceSessionHistory.querySelector('[data-session-body]');
    let busy = false;

    const setDisabled = (disabled) => {
        newButton.disabled = disabled;
        list.querySelectorAll('button').forEach((button) => { button.disabled = disabled; });
    };

    const switchSession = async (action, sessionId = null) => {
        if (busy || !window.confirm(workspaceSessionHistory.dataset.switchConfirm)) {
            return;
        }
        busy = true;
        setDisabled(true);
        status.textContent = action === 'resume' ? t('resuming_session') : t('opening_blank_session');
        try {
            const response = await window.axios.post(workspaceSessionHistory.dataset.selectUrl, {
                action,
                session_id: sessionId,
            }, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const redirect = new URL(response.data.redirect_url, window.location.origin);
            redirect.hash = 'workspace-terminal';
            window.location.assign(redirect.toString());
        } catch (error) {
            status.textContent = error.response?.data?.message || t('session_switch_failed');
            status.className = 'mt-4 text-sm text-red-200';
            busy = false;
            setDisabled(false);
        }
    };

    const deleteSession = async (sessionId) => {
        if (busy) {
            return;
        }
        busy = true;
        setDisabled(true);
        status.textContent = t('deleting_session');
        status.className = 'mt-4 text-sm text-amber-200';
        try {
            await window.axios.delete(`${workspaceSessionHistory.dataset.deleteUrl}/${encodeURIComponent(sessionId)}`, {
                data: { confirmed: true },
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            busy = false;
            await loadSessions();
            status.textContent = t('session_deleted');
            status.className = 'mt-4 text-sm text-emerald-200';
        } catch (error) {
            status.textContent = error.response?.data?.message || t('session_delete_failed');
            status.className = 'mt-4 text-sm text-red-200';
            busy = false;
            setDisabled(false);
        }
    };

    const formatUpdated = (value) => {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    };

    const renderSession = (session, currentSessionId) => {
        const current = session.id === currentSessionId;
        const card = document.createElement('article');
        card.className = current
            ? 'flex h-[9.25rem] flex-col rounded-xl border border-emerald-300/35 bg-emerald-300/10 p-4'
            : 'flex h-[9.25rem] flex-col rounded-xl border border-white/10 bg-slate-950/60 p-4';
        const title = document.createElement('p');
        title.className = 'line-clamp-2 min-h-10 text-sm font-medium text-slate-100';
        title.textContent = session.title;
        const meta = document.createElement('p');
        meta.className = 'mt-2 text-xs text-slate-500';
        meta.textContent = t('session_updated', {
            time: formatUpdated(session.updated_at),
            id: session.id.slice(0, 8),
        });
        const actions = document.createElement('div');
        actions.className = 'mt-auto flex flex-wrap items-center gap-2 pt-3';
        const resumeButton = document.createElement('button');
        resumeButton.type = 'button';
        resumeButton.className = current
            ? 'workspace-action rounded-lg border border-emerald-300/30 px-3 py-1.5 text-xs font-semibold text-emerald-200 disabled:cursor-default'
            : 'workspace-action rounded-lg border border-violet-300/30 px-3 py-1.5 text-xs font-semibold text-violet-100 transition hover:border-violet-200 hover:bg-violet-300/10';
        resumeButton.textContent = current ? t('current_session') : t('continue_this_session');
        resumeButton.disabled = current;
        if (!current) {
            resumeButton.addEventListener('click', () => switchSession('resume', session.id));
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'workspace-action rounded-lg border border-red-300/25 px-3 py-1.5 text-xs font-semibold text-red-200 transition hover:border-red-200 hover:bg-red-300/10';
            deleteButton.textContent = t('delete_session');
            deleteButton.addEventListener('click', () => {
                const confirmButton = document.createElement('button');
                confirmButton.type = 'button';
                confirmButton.className = 'workspace-action rounded-lg bg-red-400 px-3 py-1.5 text-xs font-semibold text-slate-950 transition hover:bg-red-300';
                confirmButton.textContent = t('confirm_delete_session');
                confirmButton.title = t('session_delete_warning');
                confirmButton.addEventListener('click', () => deleteSession(session.id));
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'workspace-action rounded-lg border border-white/15 px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:bg-white/5';
                cancelButton.textContent = t('cancel_delete_session');
                cancelButton.addEventListener('click', () => actions.replaceChildren(resumeButton, deleteButton));
                actions.replaceChildren(confirmButton, cancelButton);
                confirmButton.focus();
            });
            actions.append(resumeButton, deleteButton);
        } else {
            actions.append(resumeButton);
        }
        card.append(title, meta, actions);
        list.append(card);
    };

    const loadSessions = async () => {
        try {
            status.className = 'mt-4 text-sm text-slate-400';
            const response = await window.axios.get(workspaceSessionHistory.dataset.indexUrl, {
                headers: { Accept: 'application/json' },
            });
            list.replaceChildren();
            if (!response.data.available) {
                status.textContent = t('session_personal_only');
                return;
            }
            const sessions = Array.isArray(response.data.sessions) ? response.data.sessions : [];
            status.textContent = sessions.length
                ? t('session_count', { count: sessions.length })
                : t('no_saved_sessions');
            sessions.forEach((session) => renderSession(session, response.data.current_session_id));
            setDisabled(false);
        } catch (error) {
            status.textContent = error.response?.data?.message || t('session_history_failed');
            status.className = 'mt-4 text-sm text-red-200';
        }
    };

    toggleButton.addEventListener('click', () => {
        const expanded = toggleButton.getAttribute('aria-expanded') === 'true';
        toggleButton.setAttribute('aria-expanded', String(!expanded));
        sessionBody.classList.toggle('hidden', expanded);
        toggleIcon.textContent = expanded ? '＋' : '−';
        toggleLabel.textContent = expanded ? t('expand_sessions') : t('collapse_sessions');
    });
    newButton.addEventListener('click', () => switchSession('new'));
    loadSessions();
}

const workspaceMediaUpload = document.querySelector('[data-workspace-media-upload]');

const styleLibrary = document.querySelector('[data-style-library]');

if (styleLibrary) {
    const openButton = styleLibrary.querySelector('[data-style-library-open]');
    const modal = styleLibrary.querySelector('[data-style-library-modal]');
    const closeButton = styleLibrary.querySelector('[data-style-library-close]');
    const cards = [...styleLibrary.querySelectorAll('[data-style-card]')];
    const scrollRegion = styleLibrary.querySelector('[data-style-library-scroll]');
    let returnFocus = null;

    const pauseVideos = (scope) => {
        scope.querySelectorAll('[data-style-video]').forEach((video) => video.pause());
    };

    const visibilityObserver = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    pauseVideos(entry.target);
                }
            });
        }, { root: scrollRegion, threshold: 0.2 })
        : null;

    cards.forEach((card) => visibilityObserver?.observe(card));

    const open = () => {
        returnFocus = document.activeElement;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        scrollRegion.scrollTop = 0;
        closeButton.focus();
    };

    const close = () => {
        pauseVideos(modal);
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        returnFocus?.focus();
    };

    const fallbackCopy = (value) => {
        const helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.append(helper);
        helper.select();
        const copied = document.execCommand('copy');
        helper.remove();
        return copied;
    };

    openButton.addEventListener('click', open);
    closeButton.addEventListener('click', close);
    modal.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') {
            return;
        }
        const focusable = [...modal.querySelectorAll('button:not([disabled]), video[controls]')]
            .filter((element) => !element.closest('[hidden]'));
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });

    styleLibrary.querySelectorAll('[data-style-copy]').forEach((button) => {
        const original = button.textContent;
        button.addEventListener('click', async () => {
            const value = button.dataset.skillValue || '';
            let copied = false;
            try {
                await navigator.clipboard.writeText(value);
                copied = true;
            } catch {
                copied = fallbackCopy(value);
            }
            button.textContent = copied ? t('copied') : t('clipboard_blocked');
            window.setTimeout(() => { button.textContent = original; }, 1600);
        });
    });
}

if (workspaceMediaUpload) {
    const form = workspaceMediaUpload.querySelector('[data-media-upload-form]');
    const input = workspaceMediaUpload.querySelector('[data-media-input]');
    const dropzone = workspaceMediaUpload.querySelector('[data-media-dropzone]');
    const label = workspaceMediaUpload.querySelector('[data-media-label]');
    const detail = workspaceMediaUpload.querySelector('[data-media-detail]');
    const uploadButton = workspaceMediaUpload.querySelector('[data-media-upload-button]');
    const preview = workspaceMediaUpload.querySelector('[data-media-preview]');
    const previewImage = workspaceMediaUpload.querySelector('[data-media-preview-image]');
    const previewVideo = workspaceMediaUpload.querySelector('[data-media-preview-video]');
    const previewName = workspaceMediaUpload.querySelector('[data-media-preview-name]');
    const previewSize = workspaceMediaUpload.querySelector('[data-media-preview-size]');
    const result = workspaceMediaUpload.querySelector('[data-media-upload-result]');
    const status = workspaceMediaUpload.querySelector('[data-media-upload-status]');
    const commandOutput = workspaceMediaUpload.querySelector('[data-media-mention-command]');
    const copyButton = workspaceMediaUpload.querySelector('[data-media-copy-command]');
    const errorOutput = workspaceMediaUpload.querySelector('[data-media-upload-error]');
    const terminalFrame = document.querySelector('#workspace-terminal-frame');
    let previewUrl = null;
    let selectedFile = null;

    const formatBytes = (bytes) => {
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        if (bytes < 1024 * 1024) {
            return `${(bytes / 1024).toFixed(1)} KB`;
        }
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const showError = (message) => {
        errorOutput.textContent = message;
        errorOutput.classList.remove('hidden');
    };

    const clearMessages = () => {
        errorOutput.textContent = '';
        errorOutput.classList.add('hidden');
        result.classList.add('hidden');
    };

    const selectFile = (file) => {
        clearMessages();
        selectedFile = file || null;
        uploadButton.disabled = !selectedFile;
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
        if (!selectedFile) {
            preview.classList.add('hidden');
            preview.classList.remove('flex');
            previewImage.classList.add('hidden');
            previewVideo.classList.add('hidden');
            label.textContent = t('choose_media');
            return;
        }
        const imageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        const videoTypes = ['video/mp4', 'video/x-m4v', 'video/webm', 'video/quicktime'];
        const isImage = imageTypes.includes(selectedFile.type);
        const isVideo = videoTypes.includes(selectedFile.type);
        if (!isImage && !isVideo) {
            selectedFile = null;
            uploadButton.disabled = true;
            preview.classList.add('hidden');
            preview.classList.remove('flex');
            showError(t('invalid_media'));
            return;
        }
        const maximumBytes = isVideo ? 32 * 1024 * 1024 : 20 * 1024 * 1024;
        if (selectedFile.size > maximumBytes) {
            selectedFile = null;
            uploadButton.disabled = true;
            preview.classList.add('hidden');
            preview.classList.remove('flex');
            showError(isVideo ? t('video_too_large') : t('image_too_large'));
            return;
        }
        previewUrl = URL.createObjectURL(selectedFile);
        previewImage.classList.toggle('hidden', !isImage);
        previewVideo.classList.toggle('hidden', !isVideo);
        if (isImage) {
            previewImage.src = previewUrl;
            previewVideo.removeAttribute('src');
        } else {
            previewVideo.src = previewUrl;
            previewImage.removeAttribute('src');
        }
        previewName.textContent = selectedFile.name;
        previewSize.textContent = `${selectedFile.type} · ${formatBytes(selectedFile.size)}`;
        preview.classList.remove('hidden');
        preview.classList.add('flex');
        label.textContent = selectedFile.name;
        detail.textContent = t('ready_upload');
    };

    const insertMentionIntoTerminal = async (command) => {
        for (let attempt = 0; attempt < 15; attempt += 1) {
            if (document.body.dataset.terminalReady !== 'true') {
                await new Promise((resolve) => window.setTimeout(resolve, 200));
                continue;
            }
            try {
                const terminal = terminalFrame?.contentWindow?.term;
                if (terminal && typeof terminal.paste === 'function') {
                    terminal.focus();
                    terminal.paste(command);
                    return true;
                }
            } catch {
                return false;
            }
            await new Promise((resolve) => window.setTimeout(resolve, 200));
        }
        return false;
    };

    input.addEventListener('change', () => selectFile(input.files?.[0]));
    for (const eventName of ['dragenter', 'dragover']) {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('border-sky-300', 'bg-sky-400/10');
        });
    }
    for (const eventName of ['dragleave', 'drop']) {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('border-sky-300', 'bg-sky-400/10');
        });
    }
    dropzone.addEventListener('drop', (event) => selectFile(event.dataTransfer?.files?.[0]));

    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(commandOutput.textContent || '');
            copyButton.textContent = t('copied');
            window.setTimeout(() => { copyButton.textContent = t('copy_command'); }, 1500);
        } catch {
            showError(t('clipboard_blocked'));
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!selectedFile) {
            showError(t('choose_media_before_upload'));
            return;
        }

        clearMessages();
        uploadButton.disabled = true;
        uploadButton.textContent = t('uploading');
        const body = new FormData();
        body.append('media', selectedFile);

        try {
            const response = await window.axios.post(workspaceMediaUpload.dataset.uploadUrl, body, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const command = response.data.mention_command;
            commandOutput.textContent = command;
            const inserted = await insertMentionIntoTerminal(command);
            status.textContent = inserted
                ? t('uploaded_inserted')
                : t('uploaded_copy');
            result.classList.remove('hidden');
        } catch (error) {
            const message = error.response?.data?.errors?.media?.[0]
                || error.response?.data?.message
                || t('upload_failed');
            showError(message);
        } finally {
            uploadButton.disabled = false;
            uploadButton.textContent = t('upload_add');
        }
    });
}

const terminalCopy = document.querySelector('[data-terminal-copy]');

if (terminalCopy) {
    const terminalFrame = document.querySelector('#workspace-terminal-frame');
    const openButton = terminalCopy.querySelector('[data-terminal-copy-open]');
    const screenButton = terminalCopy.querySelector('[data-terminal-copy-screen]');
    const status = terminalCopy.querySelector('[data-terminal-copy-status]');
    const panel = document.querySelector('[data-terminal-copy-panel]');
    const text = panel?.querySelector('[data-terminal-copy-text]');
    const copyAllButton = panel?.querySelector('[data-terminal-copy-all]');
    const closeButton = panel?.querySelector('[data-terminal-copy-close]');
    let statusTimer = null;

    const showStatus = (message, error = false) => {
        window.clearTimeout(statusTimer);
        status.textContent = message;
        status.classList.toggle('text-red-300', error);
        status.classList.toggle('text-emerald-300', !error);
        statusTimer = window.setTimeout(() => { status.textContent = ''; }, 3000);
    };

    const getTerminal = () => {
        try {
            return terminalFrame?.contentWindow?.term || null;
        } catch {
            return null;
        }
    };

    const bufferText = (visibleOnly = false) => {
        const terminal = getTerminal();
        const buffer = terminal?.buffer?.active;
        if (!terminal || !buffer) {
            return '';
        }

        const start = visibleOnly ? buffer.viewportY : 0;
        const end = visibleOnly
            ? Math.min(buffer.length, buffer.viewportY + terminal.rows)
            : buffer.length;
        const lines = [];

        for (let index = start; index < end; index += 1) {
            const line = buffer.getLine(index);
            if (!line) {
                continue;
            }
            const value = line.translateToString(true);
            if (line.isWrapped && lines.length) {
                lines[lines.length - 1] += value;
            } else {
                lines.push(value);
            }
        }

        while (lines.length && lines[lines.length - 1] === '') {
            lines.pop();
        }
        return lines.join('\n');
    };

    const fallbackCopy = (value) => {
        const helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.append(helper);
        helper.select();
        const copied = document.execCommand('copy');
        helper.remove();
        return copied;
    };

    const writeClipboard = async (value) => {
        if (!value) {
            showStatus(t('terminal_not_ready'), true);
            return false;
        }
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            if (!fallbackCopy(value)) {
                showStatus(t('terminal_copy_failed'), true);
                return false;
            }
        }
        showStatus(t('terminal_copied', { count: value.length }));
        return true;
    };

    const installSelectionCopy = () => {
        const terminal = getTerminal();
        if (!terminal || terminal.__moviePortalCopyHandler) {
            return Boolean(terminal);
        }
        terminal.attachCustomKeyEventHandler((event) => {
            const copyShortcut = event.type === 'keydown'
                && (event.metaKey || event.ctrlKey)
                && event.key.toLowerCase() === 'c';
            if (!copyShortcut || !terminal.hasSelection()) {
                return true;
            }
            writeClipboard(terminal.getSelection());
            return false;
        });
        terminal.__moviePortalCopyHandler = true;
        return true;
    };

    const waitForTerminal = () => {
        let attempts = 0;
        const timer = window.setInterval(() => {
            attempts += 1;
            if (installSelectionCopy() || attempts >= 120) {
                window.clearInterval(timer);
            }
        }, 250);
    };

    openButton.addEventListener('click', () => {
        const value = bufferText();
        if (!value) {
            showStatus(t('terminal_not_ready'), true);
            return;
        }
        text.value = value;
        panel.classList.remove('hidden');
        text.focus();
        text.select();
        showStatus(t('terminal_text_ready', { count: value.length }));
    });
    screenButton.addEventListener('click', () => writeClipboard(bufferText(true)));
    copyAllButton.addEventListener('click', () => writeClipboard(text.value));
    closeButton.addEventListener('click', () => {
        panel.classList.add('hidden');
        getTerminal()?.focus();
    });
    terminalFrame.addEventListener('load', waitForTerminal);
    waitForTerminal();
}
