/* 活动弹窗独立生命周期，用户文本不作为 HTML 或 JavaScript 执行。 */
(function () {
    'use strict';
    function storage(kind, action, key) {
        try {
            var store = window[kind];
            if (action === 'get') return store.getItem(key) === '1';
            if (action === 'set') store.setItem(key, '1');
            if (action === 'remove') store.removeItem(key);
        } catch (_) { /* 隐私模式可能禁用存储，弹窗仍可正常操作。 */ }
        return false;
    }
    function isoTime(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value)) return NaN;
        return Date.parse(value);
    }
    function init(dialog) {
        if (dialog.dataset.promotionReady === '1') return;
        dialog.dataset.promotionReady = '1';
        var preview = dialog.dataset.preview === '1';
        var id = dialog.dataset.promotionId;
        if (!/^\d+$/.test(id)) return;
        var key = 'promotion-campaign:dismiss:' + id;
        var sessionKey = 'promotion-campaign:session:' + id;
        if (!preview && (storage('localStorage', 'get', key) || storage('sessionStorage', 'get', sessionKey))) return;
        var dismiss = dialog.querySelector('[data-promotion-dismiss]');
        var close = dialog.querySelector('[data-promotion-close]');
        var copy = dialog.querySelector('[data-promotion-copy]');
        var code = dialog.querySelector('[data-promotion-code]');
        var message = dialog.querySelector('[data-promotion-copy-status]');
        var endsAt = isoTime(dialog.dataset.endsAt);
        var serverAt = isoTime(dialog.dataset.serverNow);
        var measuredAt = performance.now();
        var finished = false, opened = false, request = null;
        var ticker = null, poller = null, locked = false, previousFocus = null;
        var oldOverflow = '', oldPriority = '';
        function now() { return serverAt + performance.now() - measuredAt; }
        function unlock() {
            if (!locked) return;
            locked = false;
            if (oldOverflow) document.body.style.setProperty('overflow', oldOverflow, oldPriority);
            else document.body.style.removeProperty('overflow');
        }
        function finish(userClosed) {
            if (finished) return;
            finished = true;
            if (userClosed && !preview) storage('sessionStorage', 'set', sessionKey);
            clearInterval(ticker);
            clearInterval(poller);
            if (request) request.abort();
            document.removeEventListener('visibilitychange', visibility);
            window.removeEventListener('pagehide', pagehide);
            window.removeEventListener('storage', storageChanged);
            if (dialog.open) dialog.close();
            unlock();
            if (opened && previousFocus && previousFocus.isConnected && typeof previousFocus.focus === 'function') {
                previousFocus.focus({ preventScroll: true });
            }
        }
        function tick() {
            if (finished) return;
            var remaining = Math.max(0, Math.ceil((endsAt - now()) / 1000));
            if (!Number.isFinite(remaining)) remaining = 0;
            var values = {
                days: Math.floor(remaining / 86400),
                hours: Math.floor(remaining / 3600) % 24,
                minutes: Math.floor(remaining / 60) % 60,
                seconds: remaining % 60
            };
            dialog.querySelectorAll('[data-promotion-unit]').forEach(function (node) {
                node.textContent = String(values[node.dataset.promotionUnit]).padStart(2, '0');
            });
            if (remaining === 0 && !preview) finish(false);
        }
        function show() {
            if (finished || opened) return;
            if (typeof dialog.showModal !== 'function') { finish(false); return; }
            previousFocus = document.activeElement;
            try {
                if (preview) dialog.show();
                else dialog.showModal();
                opened = true;
                if (!preview) {
                    oldOverflow = document.body.style.getPropertyValue('overflow');
                    oldPriority = document.body.style.getPropertyPriority('overflow');
                    document.body.style.setProperty('overflow', 'hidden', 'important');
                    locked = true;
                }
                close.focus({ preventScroll: true });
                tick();
                if (!finished) ticker = setInterval(tick, 250);
            } catch (_) { finish(false); }
        }
        async function validate() {
            if (preview || finished || request) return;
            if (typeof fetch !== 'function' || typeof AbortController !== 'function') { finish(false); return; }
            var controller = new AbortController();
            request = controller;
            var started = performance.now();
            var timeout = setTimeout(function () { controller.abort(); }, 8000);
            try {
                var response = await fetch('/promotions/' + encodeURIComponent(id) + '/status', {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store',
                    redirect: 'error', headers: { Accept: 'application/json' }, signal: controller.signal
                });
                if (!response.ok) throw new Error('Status unavailable');
                var status = await response.json();
                if (finished || controller.signal.aborted) return;
                var nextEnd = isoTime(status.ends_at), nextServer = isoTime(status.server_now);
                if (status.active !== true || !Number.isFinite(nextEnd) || !Number.isFinite(nextServer)) throw new Error('Inactive campaign');
                endsAt = nextEnd;
                // 保守计入完整请求耗时，网络延迟不能延长活动有效期。
                serverAt = nextServer + performance.now() - started;
                measuredAt = performance.now();
                tick();
                if (!finished && !document.hidden) show();
            } catch (_) {
                if (!finished) finish(false); // 首次加载与后续校验失败均保守关闭。
            } finally {
                clearTimeout(timeout);
                if (request === controller) request = null;
            }
        }
        function visibility() {
            if (document.hidden || finished) return;
            tick();
            if (!finished) validate();
        }
        function pagehide() { finish(false); }
        function storageChanged(event) {
            if (event.key === key && event.newValue === '1') finish(false);
        }
        close.addEventListener('click', function () { finish(true); });
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); finish(true); });
        dialog.addEventListener('close', function () { if (!finished) finish(true); });
        dismiss.addEventListener('change', function () {
            if (!preview) storage('localStorage', dismiss.checked ? 'set' : 'remove', key);
        });
        copy.addEventListener('click', async function () {
            try {
                if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(code.textContent);
                if (!finished) message.textContent = '优惠码已复制';
            } catch (_) {
                if (finished) return;
                code.focus();
                var selection = window.getSelection();
                if (selection) {
                    var range = document.createRange();
                    range.selectNodeContents(code);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
                message.textContent = '无法自动复制，请长按或按 Ctrl/Cmd+C 复制优惠码';
            }
        });
        window.addEventListener('pagehide', pagehide);
        if (preview) {
            dialog.querySelector('.promotion-cta').addEventListener('click', function (event) { event.preventDefault(); });
            show(); // 草稿或过期活动也强制预览，不请求状态、不读写偏好。
        } else {
            document.addEventListener('visibilitychange', visibility);
            window.addEventListener('storage', storageChanged);
            poller = setInterval(function () { if (!document.hidden) validate(); }, 30000);
            validate();
        }
    }
    function boot() { document.querySelectorAll('dialog.promotion-dialog[data-promotion-id]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
}());
