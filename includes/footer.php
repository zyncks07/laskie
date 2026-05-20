<?php // includes/footer.php ?>
</div><!-- .page-content -->
</div><!-- #main -->

<script src="<?= $depth ?>assets/vendor/bootstrap.bundle.min.js"></script>
<script src="<?= $depth ?>assets/vendor/jquery.min.js"></script>
<script src="<?= $depth ?>assets/vendor/jquery.dataTables.min.js"></script>
<script src="<?= $depth ?>assets/vendor/dataTables.bootstrap5.min.js"></script>
<script src="<?= $depth ?>assets/vendor/chart.umd.min.js"></script>
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

// AJAX helper
function apiPost(url, data, cb) {
    const fd = data instanceof FormData ? data : (() => {
        const f = new FormData();
        Object.entries(data).forEach(([k,v]) => v !== undefined && f.append(k, v));
        return f;
    })();
    fetch(url, { method:'POST', body: fd, credentials:'same-origin' })
        .then(r => r.json())
        .then(d => cb(null, d))
        .catch(e => cb(e.message, null));
}

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
