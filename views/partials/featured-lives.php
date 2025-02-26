<!-- 注目のライブ一覧 -->
<div class="featured-lives mb-4">
    <h4 class="mb-3">注目のライブ</h4>
    <?php if (!empty($featuredLives)): ?>
        <div class="row">
            <?php foreach ($featuredLives as $live): ?>
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($live['title']) ?></h5>
                            <p class="card-text"><?= htmlspecialchars($live['description']) ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-muted">注目のライブはありません。</p>
    <?php endif; ?>
</div> 