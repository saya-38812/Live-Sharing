<div class="recent-posts mb-4">
    <h4 class="mb-3">新着投稿</h4>
    <?php if (!empty($posts)): ?>
        <?php foreach ($posts as $post): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <img src="<?= htmlspecialchars($post['profile_image'] ?? 'img/default-avatar.png') ?>" 
                             class="rounded-circle me-2" 
                             width="32" 
                             height="32" 
                             alt="プロフィール画像">
                        <h6 class="mb-0"><?= htmlspecialchars($post['username']) ?></h6>
                    </div>
                    <p class="card-text"><?= htmlspecialchars($post['content']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="text-muted">新着投稿はありません。</p>
    <?php endif; ?>
</div> 