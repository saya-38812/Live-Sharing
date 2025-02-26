<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/classes/LiveRepository.php';

class HomeManager {
    private $pdo;
    private $userId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, ?int $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = null;
        $this->data = [
            'popularThreads' => [],
            'latestPosts' => [],
            'upcomingLives' => [],
            'popularTags' => []
        ];
    }
    
    public function loadPageData(): void {
        try {
            $this->data['popularThreads'] = $this->getPopularThreads();
            $this->data['latestPosts'] = $this->getLatestPosts();
            $this->data['upcomingLives'] = $this->getUpcomingLives();
            $this->data['popularTags'] = $this->getPopularTags();
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getPopularThreads(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, u.username,
                   COUNT(DISTINCT c.id) as comment_count
            FROM threads t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN comments c ON c.thread_id = t.id
            GROUP BY t.id
            ORDER BY comment_count DESC
            LIMIT 5
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getLatestPosts(): array {
        $stmt = $this->pdo->prepare("
            SELECT p.*, u.username, l.title as live_title
            FROM posts p
            JOIN users u ON p.user_id = u.id
            JOIN lives l ON p.live_id = l.id
            ORDER BY p.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getUpcomingLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, v.name as venue_name, l.image_url
            FROM lives l
            JOIN venues v ON l.venue_id = v.id
            WHERE l.date >= CURDATE()
            AND l.status = 'upcoming'
            ORDER BY l.date ASC
            LIMIT 5
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPopularTags(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.id, t.name, t.created_at
            FROM tags t
            ORDER BY t.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // use_countを仮の値として設定
        foreach ($tags as &$tag) {
            $tag['use_count'] = 0;
        }
        
        return $tags;
    }
    
    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        if ($e instanceof PDOException) {
            $this->error = 'データベースエラーが発生しました。';
            echo $e->getMessage();
        } else {
            $this->error = $e->getMessage();
        }
    }
    
    public function getData(): array {
        return $this->data;
    }
    
    public function getError(): ?string {
        return $this->error;
    }
}

// ページの初期化と処理
$userId = $_SESSION['user_id'] ?? null;
$homeManager = new HomeManager($pdo, $userId);
$homeManager->loadPageData();

if ($error = $homeManager->getError()) {
    exit($error);
}

extract($homeManager->getData());
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホーム - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        /* live-board.phpのスタイルを適用 */
        .sidebar {
            min-height: 100vh;
            background-color: var(--theme-bg-light);
            border-right: 1px solid #dee2e6;
        }
        .nav-link {
            color: #333;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .nav-link:hover {
            background-color: #cfe2ff;
        }
        .nav-link.active {
            background-color: #0d6efd;
            color: white;
        }

        /* カテゴリーカード */
        .category-card {
            padding: 1rem;
            border-radius: 8px;
            background-color: white;
            border: 1px solid rgba(var(--theme-color-rgb), 0.1);
            margin-bottom: 1rem;
            transition: transform 0.2s;
        }
        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(var(--theme-color-rgb), 0.1);
        }

        /* 掲示板カード */
        .board-card {
            border-left: 3px solid var(--theme-color);
            transition: transform 0.2s;
        }
        .board-card:hover {
            transform: translateX(5px);
            background-color: var(--theme-bg-light);
        }

        /* タグスタイル */
        .board-tag {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: var(--theme-color);
            border: 1px solid var(--theme-color);
            text-decoration: none;
            display: inline-block;
        }
        .board-tag:hover {
            background-color: var(--theme-color);
            color: white;
        }

        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem;
            }
            .col-md-7, .col-md-10, .main-content {
                padding-bottom: 5rem;
            }
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
            <!-- 注目のライブ -->
            <h4 class="mb-3 theme-color">注目のライブ</h4>
            <div class="row g-4 mb-4">
                <?php foreach ($upcomingLives as $live): ?>
                    <div class="col-md-6">
                        <a href="live-detail.php?id=<?= (int)$live['id'] ?>" class="text-decoration-none">
                            <div class="card h-100 board-card">
                                <img src="<?= $live['image_url'] ? 'uploads/lives/' . htmlspecialchars($live['image_url']) : 'img/nainoa-shizuru-NcdG9mK3PBY-unsplash.jpg' ?>" 
                                     class="card-img-top" alt="<?= htmlspecialchars($live['title']) ?>"
                                     style="height: 200px; object-fit: cover;">
                                <div class="card-body">
                                    <h5 class="card-title"><?= htmlspecialchars($live['title']) ?></h5>
                                    <div class="mb-2">
                                        <i class="bi bi-calendar3 me-2"></i><?= date('Y/m/d', strtotime($live['date'])) ?>
                                        <i class="bi bi-geo-alt ms-3 me-2"></i><?= htmlspecialchars($live['venue_name']) ?>
                                    </div>
                                    <span class="btn btn-outline-primary btn-sm">
                                        詳細を見る
                                    </span>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 最新の投稿
            <h4 class="mb-3 theme-color">最新の投稿</h4>
            <?php foreach ($latestPosts as $post): ?>
                <div class="card board-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <div>
                                <h6 class="mb-0"><?= htmlspecialchars($post['username']) ?></h6>
                                <small class="text-muted">
                                    <?= time_ago($post['created_at']) ?>
                                    <?php if ($post['live_title']): ?>
                                        · <?= htmlspecialchars($post['live_title']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <p class="card-text"><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                    </div>
                </div>
            <?php endforeach; ?> -->
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 近日開催のライブ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">近日開催のライブ</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($upcomingLives as $live): ?>
                            <a href="live-detail.php?id=<?= (int)$live['id'] ?>" 
                               class="list-group-item list-group-item-action">
                                <h6 class="mb-1"><?= htmlspecialchars($live['title']) ?></h6>
                                <small class="text-muted">
                                    <?= date('n月j日', strtotime($live['date'])) ?>
                                    <?= htmlspecialchars($live['venue_name']) ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 人気のタグ
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">人気のタグ</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($popularTags as $tag): ?>
                            <a href="?tag=<?= urlencode($tag['name']) ?>" class="board-tag">
                                #<?= htmlspecialchars($tag['name']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div> -->
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 