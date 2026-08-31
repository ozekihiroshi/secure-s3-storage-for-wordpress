/* Read-only status polling. Starting is an authenticated POST form, never a poll. */
(function () {
    'use strict';
    const panel = document.getElementById('odbfs3-media');
    if (!panel) return;
    const button = panel.querySelector('[data-media-start]');
    const form = panel.querySelector('form');
    const error = panel.querySelector('[data-media-poll-error]');
    const generation = form.elements.odbfs3_previous_job.value;
    form.addEventListener('submit', function () { button.disabled = true; });
    let stopped = false;
    async function poll() {
        if (stopped) return;
        if (document.hidden) { window.setTimeout(poll, 15000); return; }
        const abort = new AbortController();
        const timeout = window.setTimeout(function () { abort.abort(); }, 10000);
        try {
            const body = new URLSearchParams({ action: panel.dataset.action, odbfs3_media_nonce: panel.dataset.nonce });
            const response = await fetch(panel.dataset.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body, signal: abort.signal });
            if (response.status === 401 || response.status === 403) stopped = true;
            if (!response.ok) throw new Error('status unavailable');
            const payload = await response.json();
            if (!payload.success || !payload.data || typeof payload.data.id !== 'string') throw new Error('invalid status');
            const state = payload.data;
            panel.querySelectorAll('[data-media-field]').forEach(function (field) {
                const value = state[field.dataset.mediaField];
                if (typeof value === 'string' && field.textContent !== value) field.textContent = value;
            });
            // Never re-enable a stale form automatically after another job completes.
            if (state.active || (state.id || 'none') !== generation) button.disabled = true;
            error.hidden = true;
        } catch (failure) {
            error.hidden = false;
            button.disabled = true;
        } finally {
            window.clearTimeout(timeout);
            if (!stopped) window.setTimeout(poll, 15000);
        }
    }
    window.setTimeout(poll, 15000);
}());
