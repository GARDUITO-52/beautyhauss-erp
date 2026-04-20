function fmt(n, forceDecimals) {
    var v = parseFloat(n || 0);
    var isNeg = v < 0;
    var abs = Math.abs(v);
    var decimals = forceDecimals !== undefined ? forceDecimals : (abs % 1 > 0.004 ? 2 : 0);
    var formatted = abs.toLocaleString('es-MX', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    return (isNeg ? '-$' : '$') + formatted;
}
function fmt2(n) { return fmt(n, 2); }
function fmtN(n) { return parseFloat(n || 0).toLocaleString('es-MX'); }
function fmtPct(n, d) { return parseFloat(n || 0).toFixed(d !== undefined ? d : 1) + '%'; }
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function toast(msg, type) {
    type = type || 'success';
    var c = document.getElementById('tc');
    if (!c) {
        c = document.createElement('div');
        c.id = 'tc';
        c.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px';
        document.body.appendChild(c);
    }
    var color = type === 'error' ? '#E74C3C' : '#1D9E75';
    var icon  = type === 'error' ? 'exclamation-circle-fill' : 'check-circle-fill';
    var t = document.createElement('div');
    t.style.cssText = 'background:#1A202C;color:#fff;padding:11px 16px;border-radius:10px;font-size:13px;display:flex;align-items:center;gap:10px;min-width:220px;box-shadow:0 4px 20px rgba(0,0,0,.2);border-left:4px solid ' + color;
    t.innerHTML = '<i class="bi bi-' + icon + '" style="color:' + color + '"></i> ' + msg;
    c.appendChild(t);
    setTimeout(function() { t.remove(); }, 4000);
}
function apiFetch(url, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'X-CSRF-Token': window.CSRF_TOKEN || '' }, opts.headers || {});
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
        opts.method = opts.method || 'POST';
    }
    return fetch(url, opts).then(function(r) { return r.json(); });
}
