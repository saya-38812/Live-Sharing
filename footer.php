<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- ナビゲーション制御用JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn = document.getElementById('menuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');

    if (menuBtn && sidebar && overlay) {
        menuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });

        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }
});
</script>

</body>
</html> 