<!-- 近日開催のライブ -->
<div class="upcoming-lives">
    <h4 class="mb-3">近日開催のライブ</h4>
    <?php if (!empty($upcomingLives)): ?>
        <?php foreach ($upcomingLives as $live): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title"><?= htmlspecialchars($live['title']) ?></h6>
                    <p class="card-text">
                        <small class="text-muted">
                            <i class="bi bi-calendar-event me-1"></i>
                            <?= htmlspecialchars(date('Y/m/d', strtotime($live['start_date']))) ?>
                        </small>
                    </p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-muted">近日開催のライブはありません。</p>
    <?php endif; ?>
</div> 