<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class BoardManager {
    private $pdo;
    private $boardId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, int $boardId) {
        $this->pdo = $pdo;
        $this->boardId = $boardId;
        $this->error = null;
        $this->data = [
            'board' => null,
            'threads' => [],
            'popularThreads' => [],
            'popularTags' => []
        ];
    }
    
    public function loadPageData(): void {
        try {
            $this->data['board'] = $this->getBoardDetails();
            $this->data['threads'] = $this->getBoardThreads();
            $this->data['popularThreads'] = $this->getPopularThreads();
            $this->data['popularTags'] = $this->getPopularTags();
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getBoardDetails(): array {
        $stmt = $this->pdo->prepare("
            SELECT b.*, 
                   COUNT(DISTINCT t.id) as thread_count,
                   COUNT(DISTINCT p.id) as post_count
            FROM boards b
            LEFT JOIN threads t ON t.board_id = b.id
            LEFT JOIN posts p ON p.thread_id = t.id
            WHERE b.id = ?
            GROUP BY b.id
        ");
        $stmt->execute([$this->boardId]);
        $board = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$board) {
            throw new Exception('掲示板が見つかりません。');
        }
        
        return $board;
    }
    
    private function getBoardThreads(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, 
                   u.username,
                   COUNT(p.id) as reply_count,
                   MAX(p.created_at) as last_post_at
            FROM threads t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN posts p ON p.thread_id = t.id
            WHERE t.board_id = ?
            GROUP BY t.id
            ORDER BY last_post_at DESC
        ");
        $stmt->execute([$this->boardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPopularThreads(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, 
                   COUNT(p.id) as reply_count
            FROM threads t
            LEFT JOIN posts p ON p.thread_id = t.id
            WHERE t.board_id = ?
            GROUP BY t.id
            ORDER BY reply_count DESC
            LIMIT 5
        ");
        $stmt->execute([$this->boardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPopularTags(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, COUNT(tt.thread_id) as use_count
            FROM tags t
            JOIN thread_tags tt ON t.id = tt.tag_id
            JOIN threads th ON tt.thread_id = th.id
            WHERE th.board_id = ?
            GROUP BY t.id
            ORDER BY use_count DESC
            LIMIT 10
        ");
        $stmt->execute([$this->boardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        if ($e instanceof PDOException) {
            $this->error = 'データベースエラーが発生しました。';
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

// ページの初期化
session_start();

// 掲示板IDの取得と検証
$boardId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$boardId) {
    header('Location: 404.php');
    exit;
}

// ボードマネージャーの初期化とデータ読み込み
$boardManager = new BoardManager($pdo, $boardId);
$boardManager->loadPageData();

// エラーチェック
if ($error = $boardManager->getError()) {
    header('Location: error.php?message=' . urlencode($error));
    exit;
}

// データの取得
extract($boardManager->getData());

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($board['title']) ?> - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        /* 掲示板詳細専用スタイル */
        .board-header {
            padding: 1.5rem;
            margin-bottom: 2rem;
            background-color: var(--theme-bg-light);
            border-bottom: 2px solid var(--theme-color);
            border-radius: 8px;
        }

        .board-title {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--theme-color);
        }

        .board-stats {
            font-size: 0.9rem;
            color: var(--theme-color);
            margin-top: 0.5rem;
        }

        .thread-card {
            border-left: 3px solid var(--theme-color);
            transition: transform 0.2s;
        }

        .thread-card:hover {
            transform: translateX(5px);
            background-color: var(--theme-bg-light);
        }

        .board-tag {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: var(--theme-color);
            border: 1px solid var(--theme-color);
            cursor: pointer;
            display: inline-block;
        }

        .board-tag:hover {
            background-color: var(--theme-color);
            color: white;
            text-decoration: none;
        }

        .filter-button {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: 1px solid var(--theme-color);
            background: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-button:hover {
            background-color: var(--theme-bg-light);
            color: var(--theme-color);
        }

        .filter-button.active {
            background-color: var(--theme-color);
            color: white;
            border-color: var(--theme-color);
        }
    </style>
</head>
<body>
    <!-- メインコンテンツ -->
    <div class="col-md-7 py-4">
        <!-- 掲示板ヘッダー -->
        <div class="board-header">
            <h4 class="mb-3 theme-color"><?= htmlspecialchars($board['title']) ?></h4>
            <div class="board-stats">
                <i class="bi bi-chat-dots me-2"></i>スレッド数: <?= (int)$board['thread_count'] ?>
                <i class="bi bi-reply ms-3 me-2"></i>投稿数: <?= (int)$board['post_count'] ?>
            </div>
            <p class="mt-3"><?= nl2br(htmlspecialchars($board['description'])) ?></p>
        </div>

        <!-- スレッド一覧 -->
        <?php foreach ($threads as $thread): ?>
            <div class="card thread-card mb-3">
                <div class="card-body">
                    <h5 class="card-title">
                        <a href="thread-detail.php?id=<?= (int)$thread['id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($thread['title']) ?>
                        </a>
                    </h5>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            作成者: <?= htmlspecialchars($thread['username']) ?> |
                            返信: <?= (int)$thread['reply_count'] ?> |
                            最終投稿: <?= date('Y/m/d H:i', strtotime($thread['last_post_at'])) ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 人気のスレッド -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">人気のスレッド</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($popularThreads as $thread): ?>
                            <a href="thread-detail.php?id=<?= (int)$thread['id'] ?>" 
                               class="list-group-item list-group-item-action">
                                <?= htmlspecialchars($thread['title']) ?>
                                <span class="badge bg-primary rounded-pill"><?= (int)$thread['reply_count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 人気のタグ -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">人気のタグ</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($popularTags as $tag): ?>
                            <a href="?tag=<?= urlencode($tag['name']) ?>" class="board-tag">
                                #<?= htmlspecialchars($tag['name']) ?>
                                <span class="ms-1"><?= (int)$tag['use_count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 