<?php // includes/footer.php ?>
</div><!-- .page-content -->
</div><!-- #main -->

<?= vendorJsTag($depth, 'bootstrap.bundle.min.js') ?>
<?= vendorJsTag($depth, 'jquery.min.js') ?>
<?= vendorJsTag($depth, 'jquery.dataTables.min.js') ?>
<?= vendorJsTag($depth, 'dataTables.bootstrap5.min.js') ?>
<?= vendorJsTag($depth, 'chart.umd.min.js') ?>
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

// Toast notifications
function showToast(msg, type = 'success') {
    const icons = { success: '✓', error: '✕', warning: '!' };
    const colors = { success: '#16a34a', error: '#dc2626', warning: '#d97706' };
    const t = document.createElement('div');
    t.className = 'laskie-toast';
    t.innerHTML = `<span style="color:${colors[type]};font-weight:700;font-size:16px">${icons[type]}</span> ${msg}`;
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
</script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
