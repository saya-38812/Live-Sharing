<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class UserPostsManager {
    private $pdo;
    private $userId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, int $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = null;
        $this->data = [
            'user' => null,
            'posts' => [],
            'totalPosts' => 0
        ];
    }
    
    public function loadPageData(int $page = 1, int $perPage = 10): void {
        try {
            $this->data['user'] = $this->getUserInfo();
            if (!$this->data['user']) {
                throw new Exception('ユーザーが見つかりません。');
            }
            
            $this->data['totalPosts'] = $this->getTotalPosts();
            $this->data['posts'] = $this->getPosts($page, $perPage);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getUserInfo(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT id, username, profile_image
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getTotalPosts(): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM posts 
            WHERE user_id = ?
        ");
        $stmt->execute([$this->userId]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getPosts(int $page, int $perPage): array {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("
            SELECT p.*, l.title as live_title, l.date as live_date,
                   COUNT(DISTINCT lk.id) as like_count,
                   COUNT(DISTINCT c.id) as comment_count
            FROM posts p
            LEFT JOIN lives l ON p.live_id = l.id
            LEFT JOIN likes lk ON lk.likeable_id = p.id AND lk.likeable_type = 'post'
            LEFT JOIN comments c ON c.post_id = p.id
            WHERE p.user_id = ?
            GROUP BY p.id
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$this->userId, $perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

// ユーザーIDの取得
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$userId) {
    header('Location: live-home.php');
    exit;
}

// ページ番号の取得
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// ページマネージャーの初期化
$postsManager = new UserPostsManager($pdo, $userId);
$postsManager->loadPageData($page, $perPage);

if ($error = $postsManager->getError()) {
    exit($error);
}

extract($postsManager->getData());

// ページネーションの計算
$totalPages = ceil($totalPosts / $perPage);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($user['username']) ?>さんの投稿 - LiveShare</title>
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
            transition: transform 0.2s;
            margin-bottom: 1rem;
        }
        
        .post-card:hover {
            transform: translateX(5px);
            background-color: var(--theme-bg-light);
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
            <!-- ヘッダー -->
            <div class="d-flex align-items-center mb-4">
                <img src="<?= $user['profile_image'] ? 'uploads/profiles/' . htmlspecialchars($user['profile_image']) : 'img/default-profile.jpg' ?>" 
                     class="rounded-circle me-3" 
                     style="width: 50px; height: 50px; object-fit: cover;" 
                     alt="プロフィール画像">
                <div>
                    <h4 class="mb-0"><?= htmlspecialchars($user['username']) ?>さんの投稿</h4>
                    <small class="text-muted">全<?= $totalPosts ?>件</small>
                </div>
            </div>

            <!-- 投稿一覧 -->
            <?php foreach ($posts as $post): ?>
                <div class="post-card" onclick="location.href='live-post-detail.php?id=<?= $post['id'] ?>'" style="cursor: pointer;">
                    <div class="d-flex align-items-center mb-2">
                        <div>
                            <h6 class="mb-0">
                                <?php if ($post['live_title']): ?>
                                    <a href="live-detail.php?id=<?= $post['live_id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($post['live_title']) ?>
                                    </a>
                                <?php endif; ?>
                            </h6>
                            <small class="text-muted">
                                <?= time_ago($post['created_at']) ?>
                                <?php if ($post['live_date']): ?>
                                    · <?= date('Y/m/d', strtotime($post['live_date'])) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <p class="card-text"><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                    <div class="d-flex gap-3">
                        <small class="text-muted">
                            <i class="bi bi-heart"></i> <?= number_format($post['like_count']) ?>
                        </small>
                        <small class="text-muted">
                            <i class="bi bi-chat"></i> <?= number_format($post['comment_count']) ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- ページネーション -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?user_id=<?= $userId ?>&page=<?= $i ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 