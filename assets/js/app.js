/**
 * Laskie Rental Property Management System
 * Shared Application JavaScript
 */

'use strict';

// ─── Global Toast Notifications ──────────────────────────────
window.showToast = function(msg, type = 'success') {
    const icons  = { success: '✓', error: '✕', warning: '⚠' };
    const colors = { success: '#16a34a', error: '#dc2626', warning: '#d97706' };
    const existing = document.querySelectorAll('.laskie-toast');
    existing.forEach(t => t.remove());

    const t = document.createElement('div');
    t.className = 'laskie-toast';
    t.innerHTML = `<span style="color:${colors[type] || colors.success};font-weight:700;font-size:16px;line-height:1">${icons[type] || icons.success}</span><span>${msg}</span>`;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 320);
    }, 3600);
};

// ─── AJAX POST helper ─────────────────────────────────────────
window.apiPost = function(url, data, cb) {
    let fd;
    if (data instanceof FormData) {
        fd = data;
    } else {
        fd = new FormData();
        Object.entries(data).forEach(([k, v]) => {
            if (v !== undefined && v !== null) fd.append(k, v);
        });
    }
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => {
            if (!r.ok) throw new Error('Server error: ' + r.status);
            return r.json();
        })
        .then(d => cb(null, d))
        .catch(e => cb(e.message, { success: false, error: e.message }));
};

// ─── Confirm Delete / Action ──────────────────────────────────
window.confirmDelete = function(msg, cb) {
    if (window.confirm(msg || 'Are you sure you want to delete this record? This cannot be undone.')) {
        cb();
    }
};

// ─── Currency formatter ───────────────────────────────────────
window.fmt = function(n, symbol = '₱') {
    const num = parseFloat(n || 0);
    return symbol + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// ─── Date formatter ───────────────────────────────────────────
window.fmtDate = function(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (isNaN(d)) return dateStr;
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
};

// ─── Form serializer to plain object ─────────────────────────
window.serializeForm = function(formId) {
    const form = document.getElementById(formId);
    if (!form) return {};
    const data = {};
    new FormData(form).forEach((v, k) => data[k] = v);
    return data;
};

// ─── Print helper ─────────────────────────────────────────────
window.printSection = function(sectionId) {
    const el = document.getElementById(sectionId);
    if (!el) { window.print(); return; }
    const orig = document.body.innerHTML;
    document.body.innerHTML = el.outerHTML;
    window.print();
    document.body.innerHTML = orig;
    location.reload();
};

// ─── Status dot helper ────────────────────────────────────────
window.statusDot = function(status) {
    const map = { green: 'green', red: 'red', amber: 'amber', gray: 'gray' };
    const c   = map[status] || 'gray';
    return `<span class="status-dot ${c}" title="${status}"></span>`;
};

// ─── URL query helper ─────────────────────────────────────────
window.updateQueryParam = function(key, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, value);
    window.location.href = url.toString();
};

// ─── Copy to clipboard ────────────────────────────────────────
window.copyText = function(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard!', 'success');
    }).catch(() => {
        showToast('Copy failed.', 'error');
    });
};

// ─── Auto-dismiss alerts ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss success alerts after 4 seconds
    document.querySelectorAll('.alert-success[data-auto-dismiss]').forEach(el => {
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.4s';
            setTimeout(() => el.remove(), 420);
        }, 4000);
    });

    // Highlight active nav based on current URL
    const path = window.location.pathname;
    document.querySelectorAll('.sidebar-nav-item').forEach(link => {
        if (link.getAttribute('href') && path.includes(link.getAttribute('href').replace('../', ''))) {
            link.classList.add('active');
        }
    });

    // Initialize all tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
});

// ─── Number input: prevent negative ──────────────────────────
document.addEventListener('input', e => {
    if (e.target.type === 'number' && e.target.min === '0') {
        if (parseFloat(e.target.value) < 0) e.target.value = 0;
    }
});

// ─── Confirm before page leave with unsaved form ─────────────
window.markFormDirty = function(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    let dirty = false;
    form.addEventListener('change', () => dirty = true);
    window.addEventListener('beforeunload', e => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    // Clear dirty on submit
    form.addEventListener('submit', () => dirty = false);
};

// ─── Loading overlay ──────────────────────────────────────────
window.showLoading = function(msg = 'Processing...') {
    let ov = document.getElementById('globalLoadingOverlay');
    if (!ov) {
        ov = document.createElement('div');
        ov.id = 'globalLoadingOverlay';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,.7);z-index:9998;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);';
        ov.innerHTML = `<div style="text-align:center;"><div class="spinner-border text-primary" role="status"></div><div style="margin-top:12px;font-size:13px;font-weight:600;color:#1a3a8f;">${msg}</div></div>`;
        document.body.appendChild(ov);
    }
    ov.style.display = 'flex';
};

window.hideLoading = function() {
    const ov = document.getElementById('globalLoadingOverlay');
    if (ov) ov.style.display = 'none';
};

// ─── Month/Year selector sync ─────────────────────────────────
window.syncPeriodSelectors = function(monthId, yearId, onChangeCb) {
    const mSel = document.getElementById(monthId);
    const ySel = document.getElementById(yearId);
    if (!mSel || !ySel) return;
    [mSel, ySel].forEach(el => el.addEventListener('change', onChangeCb));
};

// ─── DataTable default config ─────────────────────────────────
window.dtDefaults = {
    pageLength: 25,
    language: {
        search: 'Search:',
        lengthMenu: 'Show _MENU_',
        info: '_START_–_END_ of _TOTAL_',
        paginate: { previous: '‹', next: '›' }
    },
    dom: '<"d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"lf>rtip'
};

// ─── File size validator ──────────────────────────────────────
window.validateFileSize = function(inputEl, maxMb = 10) {
    const file = inputEl.files[0];
    if (!file) return true;
    if (file.size > maxMb * 1024 * 1024) {
        showToast(`File too large. Max ${maxMb}MB allowed.`, 'error');
        inputEl.value = '';
        return false;
    }
    return true;
};

// ─── Sidebar mobile toggle (backup) ──────────────────────────
window.openSidebar = function() {
    document.getElementById('sidebar')?.classList.add('show');
    document.getElementById('sidebarOverlay')?.classList.add('show');
};

window.closeSidebar = function() {
    document.getElementById('sidebar')?.classList.remove('show');
    document.getElementById('sidebarOverlay')?.classList.remove('show');
};
