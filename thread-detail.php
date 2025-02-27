<?php
// エラー表示を有効にする
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// ThreadDetailManagerクラスの定義
class ThreadDetailManager {
    private $pdo;
    private $userId;
    private $threadId;
    private $error;
    
    public function __construct(PDO $pdo, ?int $userId, int $threadId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->threadId = $threadId;
        $this->error = null;
    }
    
    // コメントを取得するメソッドを追加
    private function getCommentsForPrediction(int $predictionId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT c.*, u.username 
                FROM setlist_prediction_comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.setlist_prediction_id = ?
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$predictionId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Comment Fetch Error: ' . $e->getMessage());
            return [];
        }
    }
    
    public function getThreadDetail(): ?array {
        try {
            // スレッドの基本情報を取得
            $stmt = $this->pdo->prepare("
                SELECT t.*,
                       u.username,
                       u.profile_image,
                       b.name as board_name,
                       b.icon as board_icon,
                       b.color_code as board_color,
                       l.title as live_title,
                       l.date as live_date
                FROM threads t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN boards b ON t.board_id = b.id
                LEFT JOIN lives l ON t.live_id = l.id
                WHERE t.id = ?
            ");
            $stmt->execute([$this->threadId]);
            $thread = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$thread) {
                $this->error = 'スレッドが見つかりませんでした。';
                return null;
            }

            // 閲覧数を更新
            $this->updateViewCount();
            
            // 投稿を取得
            $stmt = $this->pdo->prepare("
                SELECT p.*,
                       u.username,
                       u.profile_image,
                       (SELECT COUNT(*) FROM likes WHERE likeable_id = p.id AND likeable_type = 'post') as likes_count,
                       (SELECT COUNT(*) FROM likes WHERE user_id = ? AND likeable_id = p.id AND likeable_type = 'post') > 0 as is_liked
                FROM posts p
                LEFT JOIN users u ON p.user_id = u.id
                WHERE p.thread_id = ?
                ORDER BY p.created_at ASC
            ");
            $stmt->execute([$this->userId, $this->threadId]);
            $thread['posts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 予想を取得
            $stmt = $this->pdo->prepare("
                SELECT sp.*, u.username 
                FROM setlist_predictions sp
                LEFT JOIN users u ON sp.user_id = u.id
                WHERE sp.thread_id = ?
            ");
            $stmt->execute([$this->threadId]);
            $predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 各予想にコメントを追加
            foreach ($predictions as &$prediction) {
                $prediction['comments'] = $this->getCommentsForPrediction($prediction['id']);
            }
            
            $thread['predictions'] = $predictions;
            
            return $thread;
            
        } catch (PDOException $e) {
            // エラーメッセージをより詳細に
            $this->error = 'データベースエラーが発生しました: ' . $e->getMessage();
            error_log('Database Error in getThreadDetail: ' . $e->getMessage());
            error_log('SQL State: ' . $e->errorInfo[0]);
            error_log('Error Code: ' . $e->errorInfo[1]);
            error_log('Error Message: ' . $e->errorInfo[2]);
            return null;
        }
    }
    
    private function updateViewCount(): void {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE threads 
                SET views_count = views_count + 1 
                WHERE id = ?
            ");
            $stmt->execute([$this->threadId]);
        } catch (PDOException $e) {
            error_log('View Count Update Error: ' . $e->getMessage());
        }
    }
    
    public function getError(): ?string {
        return $this->error;
    }
}

// スレッドIDの取得とバリデーション
$thread_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// スレッドIDのチェック
if ($thread_id <= 0) {
    die('無効なスレッドIDです。スレッドIDを指定してください。');
}

// ページの初期化
$userId = $_SESSION['user_id'] ?? null;
$threadManager = new ThreadDetailManager($pdo, $userId, $thread_id);

// スレッド詳細の取得
$thread = $threadManager->getThreadDetail();

// スレッドが見つからない場合
if (!$thread) {
    $error = $threadManager->getError();
    echo '<div style="padding: 20px; margin: 20px; background-color: #ffebee; border: 1px solid #ef5350; border-radius: 4px;">';
    echo '<h4 style="color: #c62828; margin-bottom: 10px;">エラーが発生しました</h4>';
    echo '<p style="color: #333;">' . ($error ? h($error) : 'スレッドが見つかりませんでした。') . '</p>';
    echo '</div>';
    exit;
}

// ブックマーク状態の確認
$isBookmarked = false;
if ($userId) {
    try {
        $bookmarkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookmarks 
            WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'thread'
        ");
        $bookmarkStmt->execute([$userId, $thread_id]);
        $isBookmarked = (bool)$bookmarkStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Bookmark Check Error: ' . $e->getMessage());
    }
}

// ブックマーク処理
$bookmarkMessage = '';
if ($userId && isset($_POST['toggle_bookmark'])) {
    try {
        // 現在のブックマーク状態を確認
        $checkStmt = $pdo->prepare("
            SELECT id FROM bookmarks 
            WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'thread'
        ");
        $checkStmt->execute([$userId, $thread_id]);
        $isBookmarked = $checkStmt->fetchColumn();
        
        if ($isBookmarked) {
            // ブックマーク解除
            $deleteStmt = $pdo->prepare("
                DELETE FROM bookmarks 
                WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'thread'
            ");
            $deleteStmt->execute([$userId, $thread_id]);
            $bookmarkMessage = 'スレッドのブックマークを解除しました';
            $isBookmarked = false;
        } else {
            // ブックマーク追加
            $insertStmt = $pdo->prepare("
                INSERT INTO bookmarks (user_id, bookmarkable_id, bookmarkable_type, created_at)
                VALUES (?, ?, 'thread', NOW())
            ");
            $insertStmt->execute([$userId, $thread_id]);
            $bookmarkMessage = 'スレッドをブックマークしました';
            $isBookmarked = true;
        }
    } catch (PDOException $e) {
        $bookmarkMessage = 'エラーが発生しました: ' . $e->getMessage();
    }
}

// ページタイトル
$pageTitle = $thread['title'] . ' | スレッド';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー（共通） -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <!-- スレッドヘッダー -->
            <div class="thread-header rounded">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0"><?= h($thread['title']) ?></h2>
                    <?php if ($userId): ?>
                        <form method="post" class="d-inline">
                            <button type="submit" name="toggle_bookmark" class="btn btn-sm <?= $isBookmarked ? 'btn-success' : 'btn-outline-success' ?>">
                                <i class="bi <?= $isBookmarked ? 'bi-bookmark-fill' : 'bi-bookmark' ?>"></i>
                                <?= $isBookmarked ? 'ブックマーク中' : 'ブックマーク' ?>
                    </button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($bookmarkMessage): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= h($bookmarkMessage) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <div class="thread-meta mb-2">
                    <i class="bi bi-person-fill me-1"></i>作成者：<?= h($thread['username']) ?>
                    <i class="bi bi-clock-fill ms-3 me-1"></i><?= time_ago($thread['created_at']) ?>
                    <i class="bi bi-chat-fill ms-3 me-1"></i><?= (int)$thread['posts_count'] ?>件
                    <i class="bi bi-eye-fill ms-3 me-1"></i><?= number_format($thread['views_count']) ?>views
                </div>
                <div class="mb-3">
                    <?php if ($thread['board_name']): ?>
                        <span class="badge" style="background-color: <?= h($thread['board_color']) ?>">
                            <i class="bi <?= h($thread['board_icon']) ?> me-1"></i><?= h($thread['board_name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($thread['live_title']): ?>
                        <span class="badge bg-info">
                            <i class="bi bi-calendar-event me-1"></i><?= h($thread['live_title']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <p class="mb-0"><?= nl2br(h($thread['content'])) ?></p>
            </div>

            <!-- セットリスト予想フォーム -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">セットリスト予想を投稿</h5>
                    <div class="mb-3">
                        <input type="text" class="form-control mb-3" id="predictionTitle" placeholder="予想のタイトル">
                        <textarea class="form-control mb-3" id="predictionDescription" rows="3" placeholder="予想の説明（選曲の意図など）"></textarea>
                        <ul class="song-list" id="songList">
                            <!-- 曲のリストがここに追加される -->
                        </ul>
                        <button class="btn btn-outline-primary btn-sm" id="addSongButton">
                            <i class="bi bi-plus-lg me-1"></i>曲を追加
                        </button>
                    </div>
                    <button class="btn btn-primary" id="submitSetlistButton">予想を投稿</button>
                </div>
            </div>

            <!-- 予想一覧の表示 -->
            <div id="setlistSection" class="mt-4">
                <h5>セットリスト予想一覧</h5>
                <div class="predictions-list" id="predictionsDisplay">
                    <?php if (!empty($thread['predictions'])): ?>
                        <?php foreach ($thread['predictions'] as $prediction): ?>
                            <div class="card mb-4 prediction-card hover-effect">
                                <div class="card-header">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <a href="setlist-detail.php?id=<?= $prediction['id'] ?>" class="text-decoration-none">
                                                <h5 class="mb-0"><?= h($prediction['title']) ?></h5>
                                            </a>
                                            <small class="text-muted ms-2">by <?= h($prediction['username']) ?></small>
                                    </div>
                                        <small class="text-muted"><?= time_elapsed_string($prediction['created_at']) ?></small>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p class="card-text"><?= nl2br(h($prediction['description'])) ?></p>
                                    <ul class="list-group list-group-flush mb-3">
                                        <?php
                                        $songs = [];
                                        if (!empty($prediction['songs'])) {
                                            $songs = json_decode($prediction['songs'], true) ?? [];
                                        }
                                        if (!empty($songs)): 
                                            foreach ($songs as $index => $song): ?>
                                                <li class="list-group-item">
                                                    <span class="song-number"><?= $index + 1 ?>.</span>
                                                    <?= h($song) ?>
                                                </li>
                                            <?php endforeach; 
                                        else: ?>
                                            <li class="list-group-item">曲が登録されていません</li>
                                        <?php endif; ?>
                                    </ul>
                                    
                                    <!-- コメントセクション -->
                                    <div class="comments-section">
                                        <h6>コメント</h6>
                                        <div class="comments" id="commentsSection-<?= $prediction['id'] ?>">
                                            <?php foreach ($prediction['comments'] as $comment): ?>
                                                <div class="comment">
                                                    <div class="d-flex">
                                                        <div class="flex-grow-1">
                                                            <div class="comment-meta">
                                                                <i class="bi bi-person-fill me-1"></i><?= h($comment['username']) ?>
                                                                <span class="ms-2"><?= time_ago($comment['created_at']) ?></span>
                                                            </div>
                                                            <p class="mb-0"><?= nl2br(h($comment['comment'])) ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="comment-form mt-3">
                                            <textarea class="form-control" rows="2" placeholder="コメントを入力..." id="commentInput-<?= $prediction['id'] ?>"></textarea>
                                            <button class="btn btn-primary btn-sm mt-2" id="submitCommentButton-<?= $prediction['id'] ?>">
                                                コメントする
                                            </button>
                                        </div>
                                    </div>

                                    <!-- アクションボタン -->
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-primary like-button" 
                                                    data-prediction-id="<?= $prediction['id'] ?>">
                                                <i class="bi bi-heart me-1"></i>
                                                <span class="like-count"><?= (int)$prediction['likes_count'] ?></span>
                                            </button>
                                        </div>
                                        <a href="setlist-detail.php?id=<?= $prediction['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-arrow-right"></i> 詳細を見る
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>予想はまだありません。最初の予想を投稿してみましょう！</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 投稿一覧
            <?php foreach ($thread['posts'] as $post): ?>
                <?php
                // 投稿タイプに基づいてリンク先を決定
                $detailUrl = 'thread-detail.php'; // デフォルト
                $postType = $post['post_type'] ?? $thread['board_type'] ?? 'general';
                
                switch ($postType) {
                    case 'setlist':
                        $detailUrl = 'setlist-detail.php';
                        break;
                    case 'fashion':
                        $detailUrl = 'fashion-detail.php';
                        break;
                    case 'item_list':
                        $detailUrl = 'item-list-detail.php';
                        break;
                }
                ?>
                <a href="<?= $detailUrl ?>?id=<?= $post['id'] ?>" class="text-decoration-none">
                    <div class="card mb-4 post-card hover-effect">
                        <div class="card-body">
                            <!-- ユーザー情報 -->
                            <!-- <div class="d-flex align-items-center mb-3">
                                <img src="<?= !empty($post['profile_image']) ? 'uploads/profiles/' . h($post['profile_image']) : 'img/default-profile.png' ?>" 
                                     class="rounded-circle me-2" width="40" height="40" alt="ユーザー画像">
                                <div>
                                    <h6 class="mb-0"><?= h($post['username']) ?></h6>
                                    <small class="text-muted"><?= time_elapsed_string($post['created_at']) ?></small>
                                </div>
                            </div> -->

                            <!-- 投稿内容 -->
                            <!-- <div class="post-content">
                                <?php if (!empty($post['image_url'])): ?>
                                    <div class="post-image mb-3">
                                        <img src="uploads/<?= $postType ?>/<?= h($post['image_url']) ?>" 
                                             class="img-fluid rounded" alt="投稿画像">
                                    </div>
                                <?php endif; ?>
                                <p class="card-text"><?= nl2br(h($post['content'])) ?></p>
                            </div> -->

                            <!-- アクションボタン -->
                            <!-- <div class="d-flex justify-content-between align-items-center mt-3">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary like-button <?= $post['is_liked'] ? 'liked' : '' ?>"
                                            data-id="<?= $post['id'] ?>" data-type="post" 
                                            onclick="event.stopPropagation();">
                                        <i class="bi <?= $post['is_liked'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                        <span class="like-count"><?= $post['likes_count'] ?></span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                            onclick="event.stopPropagation();">
                                        <i class="bi bi-chat"></i> 
                                        コメント
                                    </button>
                                </div>
                                <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $post['user_id']): ?>
                                    <div class="dropdown" onclick="event.stopPropagation();">
                                        <button class="btn btn-sm btn-link text-muted" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="edit-<?= $postType ?>.php?id=<?= $post['id'] ?>">
                                                <i class="bi bi-pencil me-2"></i>編集</a></li>
                                            <li><a class="dropdown-item text-danger" href="#" 
                                                  onclick="deletePost(<?= $post['id'] ?>, '<?= $postType ?>')">
                                                <i class="bi bi-trash me-2"></i>削除</a></li>
                                        </ul>
                            </div>
                                <?php endif; ?>
                            </div> -->
                        <!-- </div>
                    </div>
                </a> -->
                <?php endforeach; ?>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 関連スレッド -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">関連スレッド</h5>
                    <div class="list-group list-group-flush">
                        <?php
                        // 関連スレッドを取得
                        try {
                            $stmt = $pdo->prepare("
                                SELECT t.*, 
                                       b.name as board_name,
                                       b.type as board_type,
                                       COUNT(p.id) as posts_count 
                                FROM threads t
                                LEFT JOIN boards b ON t.board_id = b.id
                                LEFT JOIN posts p ON t.id = p.thread_id
                                WHERE t.live_id = :live_id 
                                AND t.id != :current_thread_id
                                GROUP BY t.id
                                ORDER BY t.created_at DESC
                                LIMIT 5
                            ");
                            $stmt->execute([
                                ':live_id' => $thread['live_id'],
                                ':current_thread_id' => $thread_id
                            ]);
                            $relatedThreads = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            if (!empty($relatedThreads)): 
                                foreach ($relatedThreads as $relatedThread):
                                    // スレッドタイプに基づいてリンク先を決定
                                    $detailUrl = 'thread-detail.php'; // デフォルト
                                    switch ($relatedThread['board_type']) {
                                        case 'setlist':
                                            $detailUrl = 'setlist-detail.php';
                                            break;
                                        case 'fashion':
                                            $detailUrl = 'fashion-detail.php';
                                            break;
                                        case 'review':
                                            $detailUrl = 'review-detail.php';
                                            break;
                                        case 'ticket':
                                            $detailUrl = 'ticket-detail.php';
                                            break;
                                    }
                                    ?>
                                    <a href="<?= $detailUrl ?>?id=<?= $relatedThread['id'] ?>" 
                                       class="list-group-item list-group-item-action">
                                        <h6 class="mb-1"><?= h($relatedThread['title']) ?></h6>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="bi bi-chat-fill me-1"></i><?= $relatedThread['posts_count'] ?>件の投稿
                                            </small>
                                            <?php if ($relatedThread['board_name']): ?>
                                                <span class="badge bg-secondary"><?= h($relatedThread['board_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted mb-0">関連するスレッドはありません。</p>
                            <?php endif;
                        } catch (PDOException $e) {
                            error_log('Related Threads Error: ' . $e->getMessage());
                            echo '<p class="text-muted mb-0">関連スレッドの取得に失敗しました。</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- 人気のタグ -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">人気のタグ</h5>
                    <div class="d-flex flex-wrap">
                        <a href="#" class="popular-tag">#セトリ予想</a>
                        <a href="#" class="popular-tag">#ROCKFES</a>
                        <a href="#" class="popular-tag">#新曲</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- スマホ版ヘッダーを追加 -->
<div class="d-md-none d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0 text-primary">LiveShare</h3>
    <button class="btn btn-link" id="menuBtn">
        <i class="bi bi-list fs-4"></i>
    </button>
</div>

<!-- オーバーレイを追加 -->
<div class="overlay" id="overlay"></div>

<!-- スマホ版ボトムナビゲーションを追加 -->
<div class="d-md-none mobile-nav">
    <div class="row g-0">
        <div class="col">
            <a class="nav-link" href="live-home.php">
                <i class="bi bi-house-fill"></i>
                ホーム
            </a>
        </div>
        <div class="col">
            <a class="nav-link" href="live-calendar.php">
                <i class="bi bi-calendar-event"></i>
                ライブ
            </a>
        </div>
        <div class="col">
            <a class="nav-link active" href="live-board.php">
                <i class="bi bi-chat-square-text"></i>
                掲示板
            </a>
        </div>
        <div class="col">
            <a class="nav-link" href="live-search.php">
                <i class="bi bi-search"></i>
                検索
            </a>
        </div>
        <div class="col">
            <a class="nav-link" href="live-mypage.php">
                <i class="bi bi-person-circle"></i>
                マイページ
            </a>
        </div>
    </div>
</div>

<!-- カード用のホバーエフェクトのCSS -->
<style>
.post-card.hover-effect {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.post-card.hover-effect:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    cursor: pointer;
}

/* クリック時のエフェクトを防ぐ */
.btn-group button:active,
.dropdown button:active {
    transform: none !important;
}

.prediction-card.hover-effect {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.prediction-card.hover-effect:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    cursor: pointer;
}

/* ボタンのクリックエフェクトを防ぐ */
.btn-group button:active {
    transform: none !important;
    }
</style>

<!-- JavaScriptの追加 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn = document.getElementById('menuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');

    menuBtn.addEventListener('click', function() {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    });

    overlay.addEventListener('click', function() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    });

    // 予想を投稿する処理
    document.getElementById('submitSetlistButton').addEventListener('click', function() {
        const title = document.getElementById('predictionTitle').value;
        const description = document.getElementById('predictionDescription').value;
        const songs = Array.from(document.querySelectorAll('.song-item input'))
            .map(input => input.value)
            .filter(value => value);
        
        if (!title) {
            alert('タイトルを入力してください');
            return;
        }
        
        if (songs.length === 0) {
            alert('少なくとも1曲は追加してください');
            return;
        }
        
        // 予想をサーバーに送信
        fetch('ajax/submit-setlist.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                title: title,
                description: description,
                songs: songs,
                threadId: <?= $thread_id ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // フォームをクリア
                document.getElementById('predictionTitle').value = '';
                document.getElementById('predictionDescription').value = '';
                document.getElementById('songList').innerHTML = '';
                
                // 新しい予想を表示
                location.reload(); // ページをリロード
            } else {
                alert(data.message);
            }
        })
        .catch(error => console.error('Error:', error));
    });

    // 曲を追加するボタンの処理
    document.getElementById('addSongButton').addEventListener('click', function() {
        const songList = document.getElementById('songList');
        const newSongItem = document.createElement('li');
        newSongItem.className = 'song-item';
        newSongItem.innerHTML = `
            <span class="song-number">${songList.children.length + 1}.</span>
            <input type="text" class="form-control d-inline" placeholder="曲名を入力..." style="width: auto; display: inline-block;">
            <i class="bi bi-grip-vertical float-end"></i>
        `;
        songList.appendChild(newSongItem);
    });

    // いいねボタンのイベントリスナーを追加
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
                    this.classList.toggle('active', data.liked);
                    this.querySelector('.like-count').textContent = data.likes_count;
                    this.querySelector('i').className = `bi bi-heart${data.liked ? '-fill' : ''} me-1`;
                } else {
                    alert(data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        });
    });

    // コメントボタンのイベントリスナーを追加
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
                    newComment.className = 'comment';
                    newComment.innerHTML = `
                        <div class="d-flex">
                            <div class="flex-grow-1">
                                <div class="comment-meta">
                                    <i class="bi bi-person-fill me-1"></i>${data.comment.username}
                                    <span class="ms-2">${new Date(data.comment.created_at).toLocaleString()}</span>
                                </div>
                                <p class="mb-0">${data.comment.comment}</p>
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

    // いいねボタンのクリックイベントが親要素に伝播するのを防ぐ
    document.querySelectorAll('.like-button').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            // いいね処理を実行
        });
    });

    // 投稿削除用の関数
    function deletePost(postId, postType) {
        if (confirm('本当にこの投稿を削除しますか？')) {
            fetch('ajax/delete-post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    post_id: postId,
                    post_type: postType
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('削除に失敗しました: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('エラーが発生しました。');
            });
        }
    }
});
</script>

</body>
</html> 