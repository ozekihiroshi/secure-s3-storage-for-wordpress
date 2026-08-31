'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../src/Admin/media-backup.js'), 'utf8');

function fixture() {
    const timers = [];
    const button = { disabled: false };
    const error = { hidden: true };
    const fields = ['id', 'status_label', 'phase_label', 'prepared_files', 'uploaded_files', 'uploaded_bytes', 'message']
        .map(key => ({ dataset: { mediaField: key }, textContent: '' }));
    const form = { elements: { odbfs3_previous_job: { value: 'none' } }, addEventListener(event, fn) { this.submit = fn; } };
    const panel = {
        dataset: { action: 'odbfs3_media_status', nonce: 'nonce', endpoint: '/wp-admin/admin-ajax.php' },
        querySelector(selector) { return selector === 'form' ? form : selector === '[data-media-start]' ? button : error; },
        querySelectorAll() { return fields; }
    };
    const document = { hidden: false, getElementById() { return panel; } };
    const requests = [];
    let response = { ok: true, status: 200, json: async () => ({ success: true, data: { id: '', active: false, status_label: 'No media backup yet' } }) };
    const context = {
        document, URLSearchParams, AbortController,
        window: { setTimeout(fn, ms) { const timer = { fn, ms }; timers.push(timer); return timer; }, clearTimeout(timer) { const i = timers.indexOf(timer); if (i >= 0) timers.splice(i, 1); } },
        fetch: async (url, options) => { requests.push({ url, options }); if (response instanceof Error) throw response; return response; }
    };
    vm.runInNewContext(source, context);
    return { document, button, error, fields, form, timers, requests, setResponse(value) { response = value; },
        async poll() { const timer = timers.shift(); assert.equal(timer.ms, 15000); await timer.fn(); } };
}

(async () => {
    const f = fixture();
    assert.equal(f.requests.length, 0, 'No immediate start or worker request.');
    await f.poll();
    assert.equal(f.requests[0].options.method, 'POST');
    assert.equal(f.requests[0].options.body.get('action'), 'odbfs3_media_status');
    assert.equal(f.requests[0].options.body.get('odbfs3_media_nonce'), 'nonce');
    assert.equal(f.requests[0].options.credentials, 'same-origin');
    assert.equal(f.requests[0].options.cache, 'no-store');
    assert.equal(f.button.disabled, false);
    f.setResponse({ ok: true, status: 200, json: async () => ({ success: true, data: {
        id: 'abc', active: true, status_label: 'Running', message: '<img src=x onerror=alert(1)>'
    } }) });
    await f.poll();
    assert.equal(f.button.disabled, true, 'An intervening job disables stale form.');
    assert.equal(f.fields.find(x => x.dataset.mediaField === 'message').textContent, '<img src=x onerror=alert(1)>', 'Only textContent, never innerHTML.');
    f.setResponse({ ok: true, status: 200, json: async () => ({ success: true, data: { id: 'abc', active: false, status_label: 'Succeeded' } }) });
    await f.poll();
    assert.equal(f.button.disabled, true, 'Completion never re-enables an old form.');
    f.document.hidden = true;
    const count = f.requests.length;
    await f.poll();
    assert.equal(f.requests.length, count, 'Hidden page does not keep polling.');
    f.document.hidden = false;
    f.setResponse(new Error('offline'));
    await f.poll();
    assert.equal(f.error.hidden, false, 'Network failure is visible, not job failure.');
    assert.equal(f.fields.find(x => x.dataset.mediaField === 'status_label').textContent, 'Succeeded');
    f.setResponse({ ok: false, status: 403 });
    await f.poll();
    assert.equal(f.timers.length, 0, 'Expired auth/nonce stops polling.');
    const double = fixture(); double.form.submit(); assert.equal(double.button.disabled, true, 'Double-click guard.');
    console.log('PASS media admin polling: authenticated read-only requests, stale forms, text output, hidden pages, errors and nonce expiry');
})().catch(error => { console.error(error); process.exitCode = 1; });
