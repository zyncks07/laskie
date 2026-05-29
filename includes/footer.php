<?php // includes/footer.php ?>
</div><!-- .page-content -->
</div><!-- #main -->

<?= vendorJsTag($depth, 'bootstrap.bundle.min.js') ?>
<?= vendorJsTag($depth, 'jquery.min.js') ?>
<?= vendorJsTag($depth, 'jquery.dataTables.min.js') ?>
<?= vendorJsTag($depth, 'dataTables.bootstrap5.min.js') ?>
<?php if (!empty($needsChartJs)): ?>
<?= vendorJsTag($depth, 'chart.umd.min.js') ?>
<?php endif; ?>
<script src="<?= $depth ?>assets/js/app.js"></script>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
}

// Fallback toast — overridden by window.showToast in assets/js/app.js, which
// is loaded above. Kept for resilience if app.js fails to load. Both the
// global version and this one must avoid innerHTML interpolation of `msg`
// because server-returned strings can embed user data.
function showToast(msg, type = 'success') {
    if (window.showToast && window.showToast !== showToast) {
        return window.showToast(msg, type);
    }
    const icons  = { success: '✓', error: '✕', warning: '!' };
    const colors = { success: '#1D9E75', error: '#D85A30', warning: '#EF9F27' };
    const t = document.createElement('div');
    t.className = 'laskie-toast';
    const iconEl = document.createElement('span');
    iconEl.style.cssText = `color:${colors[type] || colors.success};font-weight:700;font-size:16px`;
    iconEl.textContent = icons[type] || icons.success;
    const textEl = document.createElement('span');
    textEl.textContent = ' ' + (msg == null ? '' : String(msg));
    t.appendChild(iconEl);
    t.appendChild(textEl);
    document.body.appendChild(t);
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3500);
}

// NOTE: apiPost is NOT redefined here. assets/js/app.js sets window.apiPost
// with built-in CSRF handling — page-specific `apiPost(...)` calls resolve
// to it via the window global. A previous duplicate inline definition that
// re-assigned window.apiPost to a wrapper calling itself caused infinite
// recursion (RangeError) and silent spinner hangs.

// Confirm delete helper
function confirmDelete(msg, cb) {
    if (confirm(msg || 'Are you sure you want to delete this record? This cannot be undone.')) cb();
}

// Number formatter
function fmt(n) { return '₱' + parseFloat(n||0).toLocaleString('en-PH', {minimumFractionDigits:2}); }

// ─── Notification bell (topbar) ───────────────────────────────
// Polls unread_count every 15s (cache-warm interval) and renders the dropdown
// on open. All text is set via textContent — never innerHTML — so notification
// messages (which embed user-entered names/purposes) can't inject markup.
(function () {
  var bell = document.getElementById('notifBell');
  if (!bell || typeof apiPost !== 'function') return;
  var API  = '<?= $depth ?>api/requests_api.php';
  var BASE = '<?= $depth ?>';
  var panel = document.getElementById('notifPanel');
  var badge = document.getElementById('notifBadge');
  var listEl = document.getElementById('notifList');

  function setBadge(n) {
    n = parseInt(n) || 0;
    if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
    else { badge.style.display = 'none'; }
  }
  function refreshCount() {
    apiPost(API, { action: 'unread_count' }, function (e, r) { if (r && r.success) setBadge(r.count); });
  }
  function loadNotifs() {
    apiPost(API, { action: 'list_notifications' }, function (e, r) {
      if (!r || !r.success) return;
      setBadge(r.unread);
      listEl.textContent = '';
      if (!r.notifications.length) {
        var empty = document.createElement('div');
        empty.style.cssText = 'padding:14px;color:#999;font-size:12.5px';
        empty.textContent = 'No notifications.';
        listEl.appendChild(empty);
        return;
      }
      r.notifications.forEach(function (n) {
        var a = document.createElement('a');
        a.href = '#';
        a.style.cssText = 'display:block;padding:10px 14px;border-bottom:1px solid #f1f1f1;text-decoration:none;color:inherit;' +
          (String(n.is_read) === '1' ? '' : 'background:rgba(239,159,39,.08)');
        var msg = document.createElement('div');
        msg.style.cssText = 'font-size:12.5px;line-height:1.35';
        msg.textContent = n.message;
        var t = document.createElement('div');
        t.style.cssText = 'font-size:10.5px;color:#999;margin-top:3px';
        t.textContent = n.created_at;
        a.appendChild(msg); a.appendChild(t);
        a.onclick = function (ev) {
          ev.preventDefault();
          apiPost(API, { action: 'mark_read', id: n.id }, function () { refreshCount(); });
          if (n.link) window.location.href = BASE + n.link;
        };
        listEl.appendChild(a);
      });
    });
  }
  window.toggleNotifPanel = function (ev) {
    ev.stopPropagation();
    var willOpen = panel.style.display === 'none';
    panel.style.display = willOpen ? 'block' : 'none';
    if (willOpen) loadNotifs();
  };
  window.markAllNotifs = function (ev) {
    ev.preventDefault(); ev.stopPropagation();
    apiPost(API, { action: 'mark_all_read' }, function () { setBadge(0); loadNotifs(); });
  };
  document.addEventListener('click', function () { panel.style.display = 'none'; });
  panel.addEventListener('click', function (ev) { ev.stopPropagation(); });
  refreshCount();
  setInterval(refreshCount, 15000);
})();
</script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
