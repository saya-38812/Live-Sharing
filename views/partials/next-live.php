<div class="next-live mb-4">
    <h4 class="mb-3">次回のライブ</h4>
    <?php if ($nextLive): ?>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title"><?= htmlspecialchars($nextLive['title']) ?></h5>
                <p class="card-text">
                    <i class="bi bi-calendar-event me-2"></i>
                    <?= htmlspecialchars(date('Y年m月d日', strtotime($nextLive['start_date']))) ?>
                </p>
            </div>
        </div>
    <?php else: ?>
        <p class="text-muted">予定されているライブはありません。</p>
    <?php endif; ?>
</div> 