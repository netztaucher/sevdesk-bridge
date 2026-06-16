(function () {
    'use strict';

    var SELECTOR_ORDER_LINK = 'a[href*="#/orders/"]';
    var ROW_CONTAINERS = 'tr, .el-table__row, [role="row"], .fct-list-item';
    var WRAP_CLASS = 'sd-wrap';
    var injected = new WeakMap();
    var stateCache = {};   // orderId -> last known state (survives Vue re-renders)

    function cfg() { return window.SevDeskBridge || null; }

    function getOrderId(a) {
        var m = (a.getAttribute('href') || '').match(/#\/orders\/(\d+)/);
        return m ? parseInt(m[1], 10) : null;
    }

    function api(path, method) {
        var c = cfg();
        return fetch(c.root + path, {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': c.nonce, 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                return { ok: r.ok, status: r.status, data: j };
            });
        });
    }

    // --- floating confirm popover (lives on <body>, immune to table re-render) ---

    var pop = null;
    function closePopover() {
        if (pop) { pop.remove(); pop = null; }
        document.removeEventListener('mousedown', onOutside, true);
        window.removeEventListener('scroll', closePopover, true);
        window.removeEventListener('resize', closePopover, true);
    }
    function onOutside(e) {
        if (pop && !pop.contains(e.target)) closePopover();
    }
    function openPopover(anchor, question, danger, onYes) {
        closePopover();
        var rect = anchor.getBoundingClientRect();
        pop = document.createElement('div');
        pop.className = 'sd-pop';

        var q = document.createElement('div');
        q.className = 'sd-pop-q';
        q.textContent = question;

        var actions = document.createElement('div');
        actions.className = 'sd-pop-actions';
        var yes = mkbtn(danger ? 'sd-pop-yes-danger' : 'sd-pop-yes', 'Ja', function () {
            closePopover(); onYes();
        });
        var no = mkbtn('sd-pop-no', 'Nein', closePopover);
        actions.appendChild(yes);
        actions.appendChild(no);

        pop.appendChild(q);
        pop.appendChild(actions);
        document.body.appendChild(pop);

        // position: below the button, clamped to viewport
        var top = rect.bottom + 6;
        var left = rect.left;
        var pw = pop.offsetWidth, ph = pop.offsetHeight;
        if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
        if (left < 8) left = 8;
        if (top + ph > window.innerHeight - 8) top = rect.top - ph - 6; // flip above
        pop.style.top = Math.max(8, top) + 'px';
        pop.style.left = left + 'px';

        setTimeout(function () {
            document.addEventListener('mousedown', onOutside, true);
            window.addEventListener('scroll', closePopover, true);
            window.addEventListener('resize', closePopover, true);
        }, 0);
    }

    function mkbtn(cls, text, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.textContent = text;
        b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); onClick(e); });
        return b;
    }

    // --- button rendering --------------------------------------------------

    function chip(cls, text, onClick, disabled, title) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.textContent = text;
        if (title) b.title = title;
        if (disabled) b.disabled = true;
        if (onClick) b.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation(); onClick(e, b);
        });
        return b;
    }

    function setState(wrap, state) {
        var id = parseInt(wrap.dataset.order, 10);
        if (state && state.mode && state.mode !== 'busy' && state.mode !== 'error') {
            stateCache[id] = state;   // cache stable states only
        }
        wrap._state = state;
        render(wrap, state);
    }

    function render(wrap, state) {
        wrap.innerHTML = '';
        wrap.dataset.mode = state.mode;

        if (state.mode === 'loading') {
            wrap.appendChild(chip('sd-btn sd-busy', '…', null, true));
        } else if (state.mode === 'unpushed') {
            wrap.appendChild(chip('sd-btn sd-push', '→ sevDesk', function (e, btn) {
                openPopover(btn, 'Rechnung in sevDesk anlegen?', false, function () { doPush(wrap, state); });
            }));
        } else if (state.mode === 'pushed') {
            wrap.appendChild(chip('sd-btn sd-ok', '✓ ' + (state.invoice_no || 'Rechnung'), function (e, btn) {
                openPopover(btn, 'Rechnung stornieren? Erstellt Gutschrift.', true, function () { doCancel(wrap, state); });
            }, false, 'Klicken zum Stornieren'));
        } else if (state.mode === 'canceled') {
            var lbl = '↻ Neu übertragen' + (state.storno_no ? ' (Storno ' + state.storno_no + ')' : '');
            wrap.appendChild(chip('sd-btn sd-cancelled', lbl, function (e, btn) {
                openPopover(btn, 'Rechnung neu übertragen? Erstellt neue Rechnung; Storno bleibt bestehen.', false, function () { doRepush(wrap, state); });
            }, false, 'Klicken für Neu-Übertragung'));
        } else if (state.mode === 'busy') {
            wrap.appendChild(chip('sd-btn sd-busy', state.label || '…', null, true));
        } else if (state.mode === 'error') {
            wrap.appendChild(chip('sd-btn sd-err', '✗ ' + (state.msg || 'Fehler'), function () {
                setState(wrap, state.prev || { mode: 'unpushed' });
            }, false, state.msg || ''));
        }
    }

    function stateFromStatus(s) {
        if (s && s.canceled) return { mode: 'canceled', storno_no: s.storno_no, invoice_no: s.invoice_no };
        if (s && s.pushed)   return { mode: 'pushed', invoice_no: s.invoice_no };
        return { mode: 'unpushed' };
    }

    // --- actions -----------------------------------------------------------

    function doPush(wrap, state) {
        setState(wrap, { mode: 'busy', label: '… anlegen' });
        api('orders/' + wrap.dataset.order + '/push', 'POST').then(function (res) {
            if (!res.ok) {
                console.error('[sevDesk Bridge] push', res.data);
                setState(wrap, { mode: 'error', msg: shortErr(res.data), prev: { mode: 'unpushed' } });
                return;
            }
            setState(wrap, stateFromStatus(res.data.state));
        }).catch(function (err) {
            console.error('[sevDesk Bridge]', err);
            setState(wrap, { mode: 'error', msg: 'Netzwerk', prev: { mode: 'unpushed' } });
        });
    }

    function doCancel(wrap, state) {
        setState(wrap, { mode: 'busy', label: '… stornieren' });
        api('orders/' + wrap.dataset.order + '/cancel', 'POST').then(function (res) {
            if (!res.ok) {
                console.error('[sevDesk Bridge] cancel', res.data);
                setState(wrap, { mode: 'error', msg: shortErr(res.data), prev: { mode: 'pushed', invoice_no: state.invoice_no } });
                return;
            }
            setState(wrap, stateFromStatus(res.data.state));
        }).catch(function (err) {
            console.error('[sevDesk Bridge]', err);
            setState(wrap, { mode: 'error', msg: 'Netzwerk', prev: { mode: 'pushed', invoice_no: state.invoice_no } });
        });
    }

    function doRepush(wrap, state) {
        setState(wrap, { mode: 'busy', label: '… neu übertragen' });
        api('orders/' + wrap.dataset.order + '/repush', 'POST').then(function (res) {
            if (!res.ok) {
                console.error('[sevDesk Bridge] repush', res.data);
                setState(wrap, { mode: 'error', msg: shortErr(res.data), prev: { mode: 'canceled', storno_no: state.storno_no } });
                return;
            }
            setState(wrap, stateFromStatus(res.data.state));
        }).catch(function (err) {
            console.error('[sevDesk Bridge]', err);
            setState(wrap, { mode: 'error', msg: 'Netzwerk', prev: { mode: 'canceled', storno_no: state.storno_no } });
        });
    }

    function shortErr(data) {
        var m = (data && data.error) ? String(data.error) : 'Fehler';
        return m.length > 50 ? m.slice(0, 50) + '…' : m;
    }

    // --- injection ---------------------------------------------------------

    function inject() {
        if (!cfg() || !/#\/orders/.test(location.hash)) return;
        var pending = [];

        document.querySelectorAll(SELECTOR_ORDER_LINK).forEach(function (a) {
            var id = getOrderId(a);
            if (!id) return;
            var row = a.closest(ROW_CONTAINERS);
            if (!row) return;
            // already has a live button in this row? skip
            if (row.querySelector('.' + WRAP_CLASS)) { injected.set(row, true); return; }

            var wrap = document.createElement('span');
            wrap.className = WRAP_CLASS;
            wrap.dataset.order = id;

            var cell = a.closest('td, .el-table__cell, .fct-list-item__cell');
            if (cell) {
                if (a.parentNode === cell) a.insertAdjacentElement('afterend', wrap);
                else cell.appendChild(wrap);
            } else {
                (row.querySelector('td:last-child, .el-table__cell:last-child') || row).appendChild(wrap);
            }
            injected.set(row, true);

            // restore from cache immediately (no flicker on re-render)
            if (stateCache[id]) {
                setState(wrap, stateCache[id]);
            } else {
                setState(wrap, { mode: 'loading' });
                pending.push({ id: id, wrap: wrap });
            }
        });

        if (pending.length) {
            var ids = pending.map(function (p) { return p.id; }).join(',');
            api('status?ids=' + ids).then(function (res) {
                var map = res.ok ? res.data : {};
                pending.forEach(function (p) { setState(p.wrap, stateFromStatus(map[p.id])); });
            }).catch(function () {
                pending.forEach(function (p) { setState(p.wrap, { mode: 'unpushed' }); });
            });
        }
    }

    var scheduled = false;
    function scheduleInject() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () { scheduled = false; inject(); });
    }

    var mo = new MutationObserver(scheduleInject);
    function start() {
        var root = document.getElementById('fluent-cart-app') || document.body;
        mo.observe(root, { childList: true, subtree: true });
        inject();
    }
    window.addEventListener('hashchange', function () { closePopover(); inject(); });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
