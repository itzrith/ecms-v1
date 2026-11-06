</main>
<footer class="footer">ECMS · Education Complaint Management System</footer>
<?php if (!empty($_SESSION['user_id'])): ?></div></div><?php endif; ?>
<?php if (!empty($_SESSION['user_id'])): ?>
<script>
(() => {
    const toggle = document.getElementById('sidebar-toggle');
    const storageKey = 'ecms_v1_sidebar_collapsed';

    if (!toggle) return;

    const updateLabel = () => {
        const isCollapsed = document.body.classList.contains('sidebar-collapsed');
        toggle.setAttribute('aria-expanded', String(!isCollapsed));
        toggle.setAttribute('aria-label', isCollapsed ? 'Show sidebar' : 'Hide sidebar');
        toggle.title = isCollapsed ? 'Show sidebar' : 'Hide sidebar';
    };

    updateLabel();
    toggle.addEventListener('click', () => {
        const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem(storageKey, String(isCollapsed));
        } catch (error) {
            // The sidebar still works for this page if storage is unavailable.
        }
        updateLabel();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
