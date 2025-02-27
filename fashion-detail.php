<?php
// デバッグ用
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 相対パスを絶対パスに変更
define('BASE_PATH', __DIR__);
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/functions.php';

// セッション開始（もし開始されていない場合）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// デバッグ情報の出力（エラーログに記録）
error_log('=== Debug Information ===');
error_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('GET params: ' . print_r($_GET, true));
error_log('Session: ' . print_r($_SESSION, true));

// 投稿IDの取得とバリデーション
$fashion_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
error_log('Fashion ID: ' . $fashion_id); // デバッグ出力

if (!$fashion_id) {
    $_SESSION['error'] = '投稿IDが指定されていません。';
    header('Location: live-board-fashion.php');
    exit;
}

// データベース接続確認
if (!isset($pdo)) {
    die('データベース接続エラー');
}

try {
    // データベースクエリの実行前にクエリをログに記録
    $sql = '
        SELECT 
            fp.*,
            u.username,
            u.profile_image,
            l.title as live_title,
            l.date as live_date,
            v.name as venue_name,
            a.name as artist_name
        FROM fashion_posts fp
        LEFT JOIN users u ON fp.user_id = u.id
        LEFT JOIN lives l ON fp.live_id = l.id
        LEFT JOIN venues v ON l.venue_id = v.id
        LEFT JOIN artists a ON l.artist_id = a.id
        WHERE fp.id = :id
    ';
    error_log('Executing SQL Query: ' . $sql);
    error_log('Fashion ID: ' . $fashion_id);
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $fashion_id, PDO::PARAM_INT);
    
    // クエリ実行前のデータベース接続状態を確認
    error_log('PDO Connection Status:');
    error_log('- ERRMODE: ' . $pdo->getAttribute(PDO::ATTR_ERRMODE));
    error_log('- CASE: ' . $pdo->getAttribute(PDO::ATTR_CASE));
    error_log('- DRIVER NAME: ' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    error_log('- SERVER VERSION: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    error_log('- CONNECTION STATUS: ' . $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS));
    
    $stmt->execute();
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // クエリ結果のデバッグ出力
    error_log('Query result: ' . print_r($post, true));
    
    // エラー情報の取得
    if ($stmt->errorCode() !== '00000') {
        error_log('SQL Error Info: ' . print_r($stmt->errorInfo(), true));
    }

    // 投稿が見つからない場合のエラーハンドリング
    if (!$post) {
        error_log('Post not found for ID: ' . $fashion_id);
        $_SESSION['error'] = '投稿が見つかりません。ID: ' . $fashion_id;
        header('Location: live-board-fashion.php');
        exit;
    }

    // 以降の処理は投稿が見つかった場合のみ実行される
    
    // いいね数を取得（別クエリで）
    $likes_stmt = $pdo->prepare('
        SELECT COUNT(*) 
        FROM likes 
        WHERE likeable_id = :id 
        AND likeable_type = "fashion_post"
    ');
    $likes_stmt->bindValue(':id', $fashion_id, PDO::PARAM_INT);
    $likes_stmt->execute();
    $likes_count = $likes_stmt->fetchColumn();
    error_log('Likes count: ' . $likes_count);
    
    // ユーザーのいいね状態を確認
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    if ($user_id) {
        $stmt = $pdo->prepare('
            SELECT EXISTS (
                SELECT 1 
                FROM likes 
                WHERE likeable_id = :post_id 
                AND likeable_type = "fashion_post"
                AND user_id = :user_id
            ) as is_liked
        ');
        $stmt->execute([
            ':post_id' => $fashion_id,
            ':user_id' => $user_id
        ]);
        $is_liked = $stmt->fetchColumn();
    } else {
        $is_liked = false;
    }
    
    // 投稿データにいいね情報を追加
    $post['likes_count'] = $likes_count;
    $post['is_liked'] = $is_liked;

    // 同じライブの他の投稿を取得（最大5件）
    if ($post['live_id']) {
        $stmt = $pdo->prepare('
            SELECT 
                fp.id, 
                fp.image_url, 
                u.username,
                fp.created_at
            FROM fashion_posts fp
            JOIN users u ON fp.user_id = u.id
            WHERE fp.live_id = ? 
            AND fp.id != ?
            ORDER BY fp.created_at DESC
            LIMIT 5
        ');
        $stmt->execute([$post['live_id'], $fashion_id]);
        $relatedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // コメントを取得
    $stmt = $pdo->prepare('
        SELECT 
            c.*,
            u.username,
            u.profile_image
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = :post_id
        ORDER BY c.created_at DESC
    ');
    $stmt->bindValue(':post_id', $fashion_id, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log('=== Database Error Details ===');
    error_log('Error Message: ' . $e->getMessage());
    error_log('Error Code: ' . $e->getCode());
    error_log('SQL State: ' . $e->errorInfo[0]);
    error_log('Driver Error Code: ' . $e->errorInfo[1]);
    error_log('Driver Error Message: ' . $e->errorInfo[2]);
    error_log('=== End Database Error Details ===');
    
    $_SESSION['error'] = 'データベースエラーが発生しました。詳細: ' . $e->getMessage();
    header('Location: live-board-fashion.php');
    exit;
}

// ページタイトルの設定（投稿が見つかった場合のみ）
$pageTitle = '参戦コーデ詳細 - LiveShare';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="section-title mb-0 theme-color">参戦コーデ詳細</h4>
                <div class="d-flex gap-2">
                    <?php if ($post['user_id'] === $_SESSION['user_id']): ?>
                        <a href="edit-fashion.php?id=<?= $fashion_id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-pencil me-2"></i>編集
                        </a>
                    <?php endif; ?>
                    <a href="live-board-fashion.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>一覧に戻る
                    </a>
                </div>
            </div>

            <!-- 投稿詳細 -->
            <div class="card mb-4">
                <div class="card-body">
                    <!-- ユーザー情報 -->
                    <div class="d-flex align-items-center mb-3">
                        <img src="<?= !empty($post['profile_image']) ? 'uploads/profiles/' . $post['profile_image'] : 'img/default-profile.png' ?>" 
                             class="rounded-circle me-2" width="40" height="40" alt="ユーザー画像">
                        <div>
                            <h6 class="mb-0"><?= htmlspecialchars($post['username']) ?></h6>
                            <small class="text-muted"><?= time_elapsed_string($post['created_at']) ?></small>
                        </div>
                    </div>

                    <!-- ライブ情報 -->
                    <div class="mb-3">
                        <h5 class="card-title">
                            <a href="live-detail.php?id=<?= $post['live_id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($post['live_title']) ?>
                            </a>
                        </h5>
                        <p class="text-muted mb-2">
                            <?= date('Y年m月d日', strtotime($post['live_date'])) ?>
                            <?php if ($post['artist_name']): ?>
                                <br><i class="bi bi-music-note-beamed"></i> <?= htmlspecialchars($post['artist_name']) ?>
                            <?php endif; ?>
                            <?php if ($post['venue_name']): ?>
                                <br><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($post['venue_name']) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- 投稿画像 -->
                    <div class="text-center mb-3">
                        <img src="uploads/fashion/<?= htmlspecialchars($post['image_url']) ?>" 
                             class="img-fluid rounded" alt="参戦コーデ">
                    </div>

                    <!-- 投稿説明文 -->
                    <?php if (!empty($post['description'])): ?>
                        <p class="card-text"><?= nl2br(htmlspecialchars($post['description'])) ?></p>
                    <?php endif; ?>

                    <!-- アクションボタン -->
                    <div class="d-flex justify-content-between align-items-center">
                        <button class="btn btn-link text-decoration-none like-button <?= $post['is_liked'] ? 'liked' : '' ?>"
                                data-id="<?= $post['id'] ?>" data-type="fashion_post">
                            <i class="bi bi-heart<?= $post['is_liked'] ? '-fill' : '' ?>"></i>
                            <span class="like-count"><?= $post['likes_count'] ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- コメントセクション -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">コメント</h5>
                    
                    <!-- コメント投稿フォーム -->
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <form id="commentForm" class="mb-4">
                            <div class="mb-3">
                                <textarea class="form-control" id="commentText" rows="3" 
                                          placeholder="コメントを入力..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                コメントを投稿
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- コメント一覧 -->
                    <div id="commentsList">
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item mb-3">
                                <div class="d-flex">
                                    <img src="<?= !empty($comment['profile_image']) ? 'uploads/profiles/' . $comment['profile_image'] : 'img/default-profile.png' ?>" 
                                         class="rounded-circle me-2" width="32" height="32" alt="ユーザー">
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0"><?= htmlspecialchars($comment['username']) ?></h6>
                                            <small class="text-muted">
                                                <?= time_elapsed_string($comment['created_at']) ?>
                                            </small>
                                        </div>
                                        <p class="mb-0"><?= nl2br(htmlspecialchars($comment['content'])) ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 関連投稿 -->
        <div class="col-md-3 py-4">
            <h5 class="mb-4">同じライブの投稿</h5>
            <?php if (!empty($relatedPosts)): ?>
                <?php foreach ($relatedPosts as $related): ?>
                    <div class="card mb-3">
                        <a href="fashion-detail.php?id=<?= $related['id'] ?>" class="text-decoration-none">
                            <img src="uploads/fashion/<?= htmlspecialchars($related['image_url']) ?>" 
                                 class="card-img-top" alt="関連投稿">
                        </a>
                        <div class="card-body">
                            <small class="text-muted">
                                <?= htmlspecialchars($related['username']) ?> - 
                                <?= time_elapsed_string($related['created_at']) ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">関連する投稿はありません。</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- いいね機能のJavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // いいねボタンのイベントリスナーを設定
    const likeButtons = document.querySelectorAll('.like-button');
    likeButtons.forEach(button => {
        button.addEventListener('click', function() {
            if (!this.classList.contains('processing')) {
                this.classList.add('processing');
                const postId = this.dataset.id;
                const postType = this.dataset.type;
                const icon = this.querySelector('i');
                const likeCount = this.querySelector('.like-count');

                // いいねの状態を切り替え
                fetch('ajax/toggle-like.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        likeable_id: postId,
                        likeable_type: postType,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // いいねの状態を更新
                        if (data.isLiked) {
                            this.classList.add('liked');
                            icon.classList.remove('bi-heart');
                            icon.classList.add('bi-heart-fill');
                        } else {
                            this.classList.remove('liked');
                            icon.classList.remove('bi-heart-fill');
                            icon.classList.add('bi-heart');
                        }
                        // いいね数を更新
                        likeCount.textContent = data.likes;
                    } else {
                        // エラーメッセージを表示
                        alert('いいねの処理に失敗しました: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('いいねの処理中にエラーが発生しました。');
                })
                .finally(() => {
                    this.classList.remove('processing');
                });
            }
        });
    });
});
</script>

<style>
/* いいねボタンのスタイル */
.like-button {
    color: #dc3545;
    padding: 0.375rem 0.75rem;
    transition: all 0.2s ease;
}

.like-button:hover {
    color: #c82333;
}

.like-button.liked {
    color: #dc3545;
}

.like-button.processing {
    pointer-events: none;
    opacity: 0.7;
}

.like-button i {
    margin-right: 0.25rem;
}
</style>

<?php include 'footer.php'; ?> 