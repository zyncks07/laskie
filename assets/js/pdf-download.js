// assets/js/pdf-download.js — "Generating PDF…" dialog for server-rendered PDFs.
//
// Chromium takes a few seconds to render a PDF, and a plain link gives no sign
// that anything is happening, so people click again and queue a second render.
// This fetches the PDF behind a modal dialog (which also blocks further
// clicks), then hands the file to the browser as a normal download.
//
// Opt in declaratively — the link still works without JS:
//   <a href="contract_pdf_download.php?id=7" data-pdf-download="Generating contract PDF">
// or from script:  downloadPdf(url, 'Generating contract PDF')
//
// Dependency-free on purpose: it also runs on the standalone contract paper
// view (includes/contracts/document.php), which loads neither app.js nor
// Bootstrap. Colours come from laskie-tokens.css, falling back to the
// document's own --doc-* tokens there.
(function () {
    'use strict';
    var busy = false;
    var TIMEOUT_MS = 90000;

    function injectStyles() {
        if (document.getElementById('pdfDlgStyles')) return;
        var css =
            '.pdfdlg-back{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;padding:16px;' +
                // Last-resort literal: laskie-tokens.css is cached for 7 days without
                // a version query, so a stale copy may not define --laskie-overlay yet.
                'background:var(--laskie-overlay,var(--doc-overlay,rgba(0,0,0,.4)));}' +
            '.pdfdlg{width:min(360px,100%);background:var(--paper,var(--doc-paper));color:var(--ink,var(--doc-ink));' +
                'border:1px solid var(--gray-200,var(--doc-hair));border-radius:var(--laskie-radius-card,var(--doc-radius));' +
                'box-shadow:var(--laskie-shadow-hero,var(--doc-shadow));padding:22px 22px 18px;text-align:center;' +
                'font-family:"DM Sans","Liberation Sans",Arial,sans-serif;}' +
            '.pdfdlg-spin{width:34px;height:34px;margin:2px auto 14px;border-radius:50%;' +
                'border:3px solid var(--gray-200,var(--doc-hair));border-top-color:var(--ink,var(--doc-ink));' +
                'animation:pdfdlg-rot .8s linear infinite;}' +
            '.pdfdlg-t{font-weight:700;font-size:15px;margin:0 0 6px;}' +
            '.pdfdlg-m{font-size:13px;line-height:1.45;color:var(--gray-600,var(--doc-ink-2));margin:0;}' +
            '.pdfdlg-btn{margin-top:16px;min-height:44px;min-width:120px;padding:8px 18px;cursor:pointer;' +
                'border-radius:var(--laskie-radius-input,var(--doc-radius));border:1px solid var(--ink,var(--doc-ink));' +
                'background:var(--ink,var(--doc-ink));color:var(--paper,var(--doc-paper));font-family:inherit;font-weight:600;font-size:13px;}' +
            '.pdfdlg.is-error .pdfdlg-spin,.pdfdlg.is-done .pdfdlg-spin{display:none;}' +
            '@keyframes pdfdlg-rot{to{transform:rotate(360deg);}}' +
            '@media (prefers-reduced-motion:reduce){.pdfdlg-spin{animation-duration:2.4s;}}' +
            '@media print{.pdfdlg-back{display:none!important;}}';
        var st = document.createElement('style');
        st.id = 'pdfDlgStyles';
        st.textContent = css;
        document.head.appendChild(st);
    }

    function openDialog(title) {
        injectStyles();
        var back = document.createElement('div');
        back.className = 'pdfdlg-back';
        back.innerHTML =
            '<div class="pdfdlg" role="alertdialog" aria-modal="true" aria-labelledby="pdfDlgT" aria-describedby="pdfDlgM">' +
              '<div class="pdfdlg-spin" aria-hidden="true"></div>' +
              '<p class="pdfdlg-t" id="pdfDlgT"></p>' +
              '<p class="pdfdlg-m" id="pdfDlgM" aria-live="polite"></p>' +
            '</div>';
        document.body.appendChild(back);
        var box = back.firstChild;
        var api = {
            set: function (t, m) {
                box.querySelector('.pdfdlg-t').textContent = t;
                box.querySelector('.pdfdlg-m').textContent = m;
            },
            done: function (t, m) {
                box.classList.add('is-done');
                api.set(t, m);
                setTimeout(api.close, 1400);
            },
            error: function (m) {
                box.classList.add('is-error');
                api.set('Couldn’t generate the PDF', m);
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'pdfdlg-btn';
                b.textContent = 'Close';
                b.onclick = api.close;
                box.appendChild(b);
                b.focus();
            },
            close: function () {
                if (back.parentNode) back.parentNode.removeChild(back);
                busy = false;
            }
        };
        api.set((title || 'Generating PDF') + '…', 'This takes a few seconds. The download will start automatically.');
        return api;
    }

    function filenameFrom(res, fallback) {
        var cd = res.headers.get('Content-Disposition') || '';
        var m = /filename\*=UTF-8''([^;]+)/i.exec(cd) || /filename="?([^";]+)"?/i.exec(cd);
        if (m) {
            try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
        }
        return fallback;
    }

    window.downloadPdf = function (url, title) {
        if (busy) return;
        busy = true;
        var dlg = openDialog(title);
        var ctl = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctl) ctl.abort(); }, TIMEOUT_MS);

        fetch(url, { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
            .then(function (res) {
                var type = res.headers.get('Content-Type') || '';
                if (res.ok && type.indexOf('application/pdf') === 0) {
                    return res.blob().then(function (blob) { return { blob: blob, name: filenameFrom(res, 'document.pdf') }; });
                }
                // Not a PDF: a plain-text error from the handler, or the login
                // page after a lapsed session (fetch follows the redirect).
                return res.text().then(function (txt) {
                    var msg = type.indexOf('text/html') === 0 && /<form/i.test(txt)
                        ? 'Your session may have expired. Reload the page, sign in again, and retry.'
                        : (txt.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) || ('Server error (' + res.status + ').'));
                    throw new Error(msg);
                });
            })
            .then(function (file) {
                var href = URL.createObjectURL(file.blob);
                var a = document.createElement('a');
                a.href = href;
                a.download = file.name;
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(href); }, 60000);
                dlg.done('PDF ready', 'Your download has started.');
            })
            .catch(function (err) {
                dlg.error(err && err.name === 'AbortError'
                    ? 'The server took too long to respond. Please try again in a moment.'
                    : (err && err.message) || 'Network error. Check your connection and try again.');
            })
            .then(function () { clearTimeout(timer); });
    };

    // Delegated, so links rendered later (DataTables redraws) are covered too.
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-pdf-download]') : null;
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (!window.fetch || !window.URL || !URL.createObjectURL) return; // very old browser: plain link
        e.preventDefault();
        window.downloadPdf(a.href, a.getAttribute('data-pdf-download'));
    });
})();
