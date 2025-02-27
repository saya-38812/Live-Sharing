<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';



class SetlistBoardManager {
    private $pdo;
    private $userId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, ?int $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = null;
        $this->data = [
            'predictions' => [],
            'lives' => [],
            'likedPredictions' => []
        ];
    }
    
    public function loadPageData(): void {
        try {
            $search = $_GET['search'] ?? '';  // 検索キーワードを取得
            $this->data['predictions'] = $this->getPredictions($search);
            $this->data['lives'] = $this->getLives();
            if ($this->userId) {
                $this->data['likedPredictions'] = $this->getLikedPredictions();
            }
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getPredictions(string $search = ''): array {
        $query = "
            SELECT 
                sp.*,
                u.username,
                u.profile_image,
                l.title as live_title,
                l.date as live_date,
                l.open_time,
                l.start_time,
                v.name as venue_name,
                a.name as artist_name,
                (SELECT COUNT(*) FROM setlist_prediction_likes WHERE setlist_prediction_id = sp.id) as likes_count,
                (SELECT COUNT(*) FROM setlist_prediction_comments WHERE setlist_prediction_id = sp.id) as comments_count
            FROM setlist_predictions sp
            JOIN users u ON sp.user_id = u.id
            LEFT JOIN lives l ON sp.live_id = l.id
            LEFT JOIN venues v ON l.venue_id = v.id
            LEFT JOIN artists a ON l.artist_id = a.id
        ";

        $params = [];
        if (!empty($search)) {
            $query .= " WHERE (sp.title LIKE ? OR sp.description LIKE ? OR sp.songs LIKE ?)";
            $searchTerm = "%{$search}%";
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }

        $query .= " ORDER BY sp.created_at DESC LIMIT 10";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 各予測のセットリスト曲とコメントを取得
        foreach ($predictions as &$prediction) {
            if (is_string($prediction['songs'])) {
                $prediction['songs'] = json_decode($prediction['songs'], true) ?? [];
            }
            $prediction['comments'] = $this->getPredictionComments($prediction['id']);
        }

        return $predictions;
    }
    
    private function getPredictionSongs(int $predictionId): array {
        $stmt = $this->pdo->prepare("
            SELECT title, duration 
            FROM setlist_prediction_songs 
            WHERE setlist_prediction_id = ? 
            ORDER BY position
            LIMIT 5
        ");
        $stmt->execute([$predictionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPredictionComments(int $predictionId): array {
        // スレッドのコメントとセットリスト予想のコメントの両方を取得
        $stmt = $this->pdo->prepare("
            (SELECT 
                c.id,
                CONVERT(c.content USING utf8mb4) COLLATE utf8mb4_unicode_ci as comment,
                c.created_at,
                c.user_id,
                u.username,
                u.profile_image,
                'thread' as comment_type
            FROM posts c
            JOIN users u ON c.user_id = u.id
            WHERE c.thread_id = ?)
            UNION ALL
            (SELECT 
                c.id,
                CONVERT(c.comment USING utf8mb4) COLLATE utf8mb4_unicode_ci as comment,
                c.created_at,
                c.user_id,
                u.username,
                u.profile_image,
                'prediction' as comment_type
            FROM setlist_prediction_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.setlist_prediction_id = ?)
            ORDER BY created_at ASC
        ");
        $stmt->execute([$predictionId, $predictionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT id, title, artist_id, venue_id, date, open_time, start_time, 
                   doors_open, capacity, status, description, setlist, image_url
            FROM lives
            WHERE date >= CURDATE()
            AND status = 'upcoming'
            ORDER BY date ASC
            LIMIT 5
        ");
        $stmt->execute();
        $lives = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // アーティストと会場の情報を取得
        foreach ($lives as &$live) {
            // アーティスト情報を取得
            $stmt = $this->pdo->prepare("
                SELECT name FROM artists WHERE id = ?
            ");
            $stmt->execute([$live['artist_id']]);
            $artist = $stmt->fetch(PDO::FETCH_ASSOC);
            $live['artist_name'] = $artist['name'] ?? '';

            // 会場情報を取得
            $stmt = $this->pdo->prepare("
                SELECT name FROM venues WHERE id = ?
            ");
            $stmt->execute([$live['venue_id']]);
            $venue = $stmt->fetch(PDO::FETCH_ASSOC);
            $live['venue_name'] = $venue['name'] ?? '';
        }

        return $lives;
    }
    
    private function getLikedPredictions(): array {
        $stmt = $this->pdo->prepare("
            SELECT setlist_prediction_id 
            FROM setlist_prediction_likes 
            WHERE user_id = ?
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

// ページの初期化
$userId = $_SESSION['user_id'] ?? null;

// ページマネージャーの初期化とデータ読み込み
$pageManager = new SetlistBoardManager($pdo, $userId);
$pageManager->loadPageData();

// エラーチェック
if ($error = $pageManager->getError()) {
    exit($error);
}

// データの取得
extract($pageManager->getData());

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>セットリスト予想 - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        .sidebar {
            min-height: 100vh;
            background-color: #e8f0fe;
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

        /* 掲示板専用スタイル */
        .board-category {
            padding: 1rem;
            border-radius: 8px;
            background-color: white;
            border: 1px solid #dee2e6;
            margin-bottom: 1rem;
            transition: transform 0.2s;
        }
        .board-category:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .thread-card {
            border-left: 3px solid #0d6efd;
            transition: transform 0.2s;
        }
        .thread-card:hover {
            transform: translateX(5px);
        }
        .category-icon {
            font-size: 1.5rem;
            color: #0d6efd;
        }
        .thread-meta {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .popular-tag {
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
        .popular-tag:hover {
            background-color: var(--theme-color);
            color: white;
            text-decoration: none;
        }

        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem; /* ボトムナビゲーションの高さ分の余白 */
            }

            /* メインコンテンツの余白調整 */
            .col-md-7, .col-md-10, .main-content {
                padding-bottom: 5rem; /* ボトムナビゲーションの高さ + 追加の余白 */
            }
        }
        .section-title {
            color: #0d6efd;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #0d6efd;
        }
        .post-card {
            border-radius: 8px;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
            transition: transform 0.2s;
        }
        .post-card:hover {
            transform: translateY(-2px);
        }
        .like-button {
            transition: all 0.2s;
        }
        .like-button.liked {
            color: #dc3545;
            border-color: #dc3545;
        }
        .like-button.liked i {
            color: #dc3545;
        }

        /* 検索フォームのスタイル */
        .input-group .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .input-group .form-control:focus + .input-group-text {
            border-color: #0d6efd;
        }
        .input-group-text {
            border-right: 0;
        }

        /* セットリスト予想専用スタイル */
        .setlist-card {
            border-left: 3px solid #0d6efd;  /* 固定の青色を維持 */
            transition: transform 0.2s;
        }
        .setlist-card:hover {
            transform: translateX(5px);
            background-color: #f8f9fa;  /* 固定の背景色を維持 */
        }

        .song-item {
            padding: 0.5rem;
            background-color: white;
            border: 1px solid rgba(var(--theme-color-rgb), 0.1);
            margin-bottom: 0.5rem;
            border-radius: 4px;
            cursor: move;
        }

        .song-item:hover {
            border-color: var(--theme-color);
            background-color: var(--theme-bg-light);
        }

        .song-item.dragging {
            opacity: 0.5;
            border: 2px dashed var(--theme-color);
        }

        .comment-card {
            border-left: 3px solid var(--theme-color);
            margin-bottom: 1rem;
        }

        .comment-card:hover {
            transform: translateX(5px);
            background-color: var(--theme-bg-light);
        }

        /* カードホバー時の効果 */
        .post-card {
            transition: transform 0.2s ease-in-out;
        }
        
        .post-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        /* カード内のリンクスタイル */
        .post-card .card-body {
            cursor: pointer;
        }

        .post-card .card-body:hover {
            background-color: rgba(0,0,0,0.01);
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
            <!-- 検索フォーム -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="d-flex gap-2">
                        <div class="input-group">
                            <span class="input-group-text bg-white">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="search" 
                                   class="form-control border-start-0" 
                                   placeholder="セットリスト予想を検索..." 
                                   name="search"
                                   value="<?= h($_GET['search'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-primary text-nowrap">検索</button>
                        <?php if (!empty($_GET['search'])): ?>
                            <a href="<?= h($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary text-nowrap">
                                検索解除
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- 検索結果の表示 -->
            <?php if (!empty($_GET['search'])): ?>
                <div class="alert alert-info mb-4">
                    「<?= h($_GET['search']) ?>」の検索結果: <?= count($predictions) ?>件
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="section-title mb-0">セットリスト予想</h4>
                <a href="create-setlist.php<?= isset($live_id) ? '?live_id=' . $live_id : '' ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-2"></i>新規セットリスト作成
                </a>
            </div>

            <!-- 投稿一覧 -->
            <?php if (!empty($predictions)): ?>
                <?php foreach ($predictions as $prediction): ?>
                    <div class="post-card card mb-3">
                        <!-- カード全体をリンクに変更 -->
                        <a href="setlist-detail.php?id=<?= (int)$prediction['id'] ?>" 
                           class="card-body text-decoration-none text-dark">
                            <div class="d-flex align-items-center mb-3">
                                <img src="<?= htmlspecialchars($prediction['profile_image'] ?? 'img/default-avatar.png') ?>" 
                                     class="rounded-circle me-2" width="40" height="40" 
                                     style="object-fit: cover;" alt="ユーザー">
                                <div>
                                    <h6 class="mb-0"><?= htmlspecialchars($prediction['username']) ?></h6>
                                    <small class="text-muted">
                                        <?= time_ago($prediction['created_at']) ?>
                                        <?php if ($prediction['live_title']): ?>
                                            · <?= htmlspecialchars($prediction['live_title']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>

                            <h6><?= htmlspecialchars($prediction['title']) ?></h6>
                            
                            <?php if ($prediction['description']): ?>
                                <p class="mb-3"><?= nl2br(htmlspecialchars($prediction['description'])) ?></p>
                            <?php endif; ?>

                            <!-- 曲リストの表示 -->
                            <?php if (!empty($prediction['songs'])): ?>
                                <div class="mb-3">
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($prediction['songs'] as $index => $song): ?>
                                            <div class="list-group-item">
                                                <span class="song-number"><?= ($index + 1) ?>.</span>
                                                <?= htmlspecialchars($song) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </a>

                        <!-- いいねとコメントボタンは別のdivに -->
                        <div class="card-footer bg-light">
                            <div class="d-flex align-items-center">
                                <button class="btn btn-sm btn-outline-primary like-button <?= in_array($prediction['id'], $likedPredictions) ? 'liked' : '' ?>" 
                                        data-prediction-id="<?= (int)$prediction['id'] ?>">
                                    <i class="bi <?= in_array($prediction['id'], $likedPredictions) ? 'bi-heart-fill' : 'bi-heart' ?> me-1"></i>
                                    <span class="like-count"><?= (int)$prediction['comments_count'] ?></span>
                                </button>
                                <a href="setlist-detail.php?id=<?= (int)$prediction['id'] ?>" class="btn btn-sm btn-outline-secondary ms-2">
                                    <i class="bi bi-chat me-1"></i><?= (int)$prediction['comments_count'] ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <?php if (!empty($_GET['search'])): ?>
                        「<?= h($_GET['search']) ?>」に一致する予想は見つかりませんでした。
                    <?php else: ?>
                        まだセットリスト予想の投稿がありません。
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 近日開催のライブ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">近日開催のライブ</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($lives as $live): ?>
                            <a href="live-detail.php?id=<?= (int)$live['id'] ?>" class="list-group-item list-group-item-action">
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
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- いいね機能とスクロール用JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // いいねボタンの処理
    document.querySelectorAll('.like-button').forEach(button => {
        button.addEventListener('click', function() {
            const predictionId = this.dataset.predictionId;
            
            fetch('ajax/toggle-like.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ predictionId: predictionId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // いいねの状態を更新
                    this.classList.toggle('liked');
                    this.querySelector('.like-count').textContent = data.likes_count;
                    this.querySelector('i').className = `bi bi-heart${data.liked ? '-fill' : ''} me-1`;
                } else {
                    alert(data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        });
    });

    // コメントボタンの処理
    document.querySelectorAll('[id^="submitCommentButton-"]').forEach(button => {
        button.addEventListener('click', function() {
            const predictionId = this.id.split('-')[1];
            const commentInput = document.getElementById(`commentInput-${predictionId}`);
            const comment = commentInput.value;

            if (!comment.trim()) {
                alert('コメントを入力してください');
                return;
            }

            fetch('ajax/submit-comment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    predictionId: predictionId,
                    comment: comment
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 新しいコメントを表示
                    const commentsSection = document.getElementById(`commentsSection-${predictionId}`);
                    const newComment = document.createElement('div');
                    newComment.className = 'comment mb-2';
                    newComment.innerHTML = `
                        <div class="d-flex">
                            <img src="${data.comment.profile_image || 'img/default-avatar.png'}" 
                                 class="rounded-circle me-2" width="32" height="32">
                            <div class="flex-grow-1">
                                <div class="comment-meta small text-muted">
                                    <span class="fw-bold">${data.comment.username}</span>
                                    <span class="ms-2">たった今</span>
                                </div>
                                <div class="comment-content">
                                    ${data.comment.comment}
                                </div>
                            </div>
                        </div>
                    `;
                    commentsSection.appendChild(newComment);
                    commentInput.value = ''; // 入力フィールドをクリア
                } else {
                    alert(data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        });
    });
});

// 投稿フォームまでスクロールする関数
function scrollToPostForm() {
    const postForm = document.getElementById('postForm');
    postForm.scrollIntoView({ behavior: 'smooth' });
}
</script>
</body>
</html> 