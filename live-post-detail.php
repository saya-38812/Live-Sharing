<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class PostDetailManager {
    private $pdo;
    private $postId;
    private $userId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, int $postId, ?int $userId = null) {
        $this->pdo = $pdo;
        $this->postId = $postId;
        $this->userId = $userId;
        $this->error = null;
        $this->data = [
            'post' => null,
            'comments' => [],
            'user' => null
        ];
    }
    
    public function loadPageData(): void {
        try {
            $this->data['post'] = $this->getPostDetails();
            if (!$this->data['post']) {
                throw new Exception('投稿が見つかりません。');
            }
            $this->data['comments'] = $this->getComments();
            $this->data['user'] = $this->getPostUser();
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    public function addComment(string $content): bool {
        try {
            if (!$this->userId) {
                throw new Exception('ログインが必要です。');
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO comments (post_id, user_id, content, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            return $stmt->execute([$this->postId, $this->userId, $content]);
        } catch (Exception $e) {
            $this->handleError($e);
            return false;
        }
    }

    private function getPostDetails(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT p.*, l.title as live_title, l.date as live_date,
                   u.username, u.profile_image,
                   COUNT(DISTINCT lk.id) as like_count,
                   EXISTS (
                       SELECT 1 FROM likes 
                       WHERE likeable_id = p.id 
                       AND likeable_type = 'post' 
                       AND user_id = ?
                   ) as is_liked
            FROM posts p
            LEFT JOIN lives l ON p.live_id = l.id
            JOIN users u ON p.user_id = u.id
            LEFT JOIN likes lk ON lk.likeable_id = p.id AND lk.likeable_type = 'post'
            WHERE p.id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$this->userId ?? 0, $this->postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getComments(): array {
        $stmt = $this->pdo->prepare("
            SELECT c.*, u.username, u.profile_image,
                   COUNT(DISTINCT lk.id) as like_count,
                   EXISTS (
                       SELECT 1 FROM likes 
                       WHERE likeable_id = c.id 
                       AND likeable_type = 'comment' 
                       AND user_id = ?
                   ) as is_liked
            FROM comments c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN likes lk ON lk.likeable_id = c.id AND lk.likeable_type = 'comment'
            WHERE c.post_id = ?
            GROUP BY c.id
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$this->userId ?? 0, $this->postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPostUser(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT u.* FROM users u
            JOIN posts p ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$this->postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        $this->error = $e->getMessage();
    }
    
    public function getData(): array {
        return $this->data;
    }
    
    public function getError(): ?string {
        return $this->error;
    }
}

// 投稿IDの取得
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$postId) {
    header('Location: live-home.php');
    exit;
}

// ページマネージャーの初期化
$userId = $_SESSION['user_id'] ?? null;
$postManager = new PostDetailManager($pdo, $postId, $userId);

// コメント投稿の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    if ($postManager->addComment($_POST['content'])) {
        header("Location: live-post-detail.php?id=$postId");
        exit;
    }
}

$postManager->loadPageData();

if ($error = $postManager->getError()) {
    exit($error);
}

extract($postManager->getData());
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>投稿詳細 - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        .post-card {
            padding: 1rem;
            border-left: 3px solid var(--theme-color);
            background-color: white;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .comment-card {
            padding: 1rem;
            border-left: 2px solid #6c757d;
            background-color: white;
            border-radius: 4px;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>
        
        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <!-- 投稿 -->
            <div class="post-card">
                <div class="d-flex align-items-center mb-3">
                    <img src="<?= $post['profile_image'] ? 'uploads/profiles/' . htmlspecialchars($post['profile_image']) : 'img/default-profile.jpg' ?>" 
                         class="rounded-circle me-2" 
                         style="width: 40px; height: 40px; object-fit: cover;">
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($post['username']) ?></h6>
                        <small class="text-muted">
                            <?= time_ago($post['created_at']) ?>
                            <?php if ($post['live_title']): ?>
                                · <a href="live-detail.php?id=<?= $post['live_id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($post['live_title']) ?>
                                </a>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <p class="card-text"><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                <div class="d-flex gap-3">
                    <button class="btn btn-sm <?= $post['is_liked'] ? 'btn-danger' : 'btn-outline-danger' ?> like-button"
                            data-id="<?= $post['id'] ?>" data-type="post">
                        <i class="bi <?= $post['is_liked'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                        <span class="like-count"><?= $post['like_count'] ?></span>
                    </button>
                </div>
            </div>

            <!-- コメント投稿フォーム -->
            <?php if ($userId): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">コメントを投稿</label>
                                <textarea class="form-control" name="content" rows="2" required></textarea>
                            </div>
                            <button type="submit" name="comment" class="btn btn-primary">
                                投稿する
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- コメント一覧 -->
            <h5 class="mb-3">コメント</h5>
            <?php if (!empty($comments)): ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-card">
                        <div class="d-flex align-items-center mb-2">
                            <img src="<?= $comment['profile_image'] ? 'uploads/profiles/' . htmlspecialchars($comment['profile_image']) : 'img/default-profile.jpg' ?>" 
                                 class="rounded-circle me-2" 
                                 style="width: 32px; height: 32px; object-fit: cover;">
                            <div>
                                <h6 class="mb-0"><?= htmlspecialchars($comment['username']) ?></h6>
                                <small class="text-muted"><?= time_ago($comment['created_at']) ?></small>
                            </div>
                        </div>
                        <p class="card-text"><?= nl2br(htmlspecialchars($comment['content'])) ?></p>
                        <button class="btn btn-sm <?= $comment['is_liked'] ? 'btn-danger' : 'btn-outline-danger' ?> like-button"
                                data-id="<?= $comment['id'] ?>" data-type="comment">
                            <i class="bi <?= $comment['is_liked'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                            <span class="like-count"><?= $comment['like_count'] ?></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">まだコメントはありません。</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // いいねボタンのイベントリスナー
    document.querySelectorAll('.like-button').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            if (!<?= json_encode(isset($_SESSION['user_id'])) ?>) {
                alert('いいねするにはログインが必要です');
                return;
            }

            const id = this.dataset.id;
            const type = this.dataset.type;
            const icon = this.querySelector('i');
            const likeCount = this.querySelector('.like-count');

            try {
                const response = await fetch('ajax/toggle-like.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id, type })
                });

                const data = await response.json();
                
                if (data.success) {
                    // いいねの状態を更新
                    if (data.isLiked) {
                        this.classList.remove('btn-outline-danger');
                        this.classList.add('btn-danger');
                        icon.classList.remove('bi-heart');
                        icon.classList.add('bi-heart-fill');
                    } else {
                        this.classList.add('btn-outline-danger');
                        this.classList.remove('btn-danger');
                        icon.classList.add('bi-heart');
                        icon.classList.remove('bi-heart-fill');
                    }
                    
                    // いいね数を更新
                    likeCount.textContent = data.likes;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('エラーが発生しました');
            }
        });
    });
});
</script>
</body>
</html> 