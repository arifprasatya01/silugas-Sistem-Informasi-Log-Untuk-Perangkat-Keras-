<?php // includes/footer.php ?>
</div><!-- end container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>

<!-- PWA Service Worker -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('<?= APP_URL ?>/sw.js')
            .then(reg => console.log('SW registered'))
            .catch(err => console.log('SW error:', err));
    });
}
</script>
</body>
</html>
