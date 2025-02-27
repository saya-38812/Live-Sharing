<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// エラーログの表示（開発時のみ）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// セッションのエラーメッセージがある場合、表示して削除
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger">';
    echo htmlspecialchars($_SESSION['error']);
    echo '<br>Debug Information:<br>';
    echo 'Request Method: ' . $_SERVER['REQUEST_METHOD'] . '<br>';
    echo 'Previous Page: ' . ($_SERVER['HTTP_REFERER'] ?? 'none') . '<br>';
    echo 'Session Data: <pre>' . print_r($_SESSION, true) . '</pre>';
    echo '</div>';
    unset($_SESSION['error']);
}

// エラーログファイルの内容を表示（開発時のみ）
$error_log_path = __DIR__ . '/error.log'; // エラーログファイルのパスを指定
if (file_exists($error_log_path)) {
    echo '<div class="alert alert-info">';
    echo '<h4>Error Log:</h4>';
    echo '<pre>';
    echo htmlspecialchars(file_get_contents($error_log_path));
    echo '</pre>';
    echo '</div>';
}

// ログイン状態の確認
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : null;

// ページネーション設定
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 12; // 1ページあたりの表示数
$offset = ($page - 1) * $perPage;

// 検索条件
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$artistId = isset($_GET['artist_id']) ? (int)$_GET['artist_id'] : 0;
$venueId = isset($_GET['venue_id']) ? (int)$_GET['venue_id'] : 0;
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'latest';

// 参戦コーデの取得
try {
    // 基本クエリ
    $query = "
        SELECT fp.*, l.title as live_title, l.date as live_date, 
               a.name as artist_name, v.name as venue_name,
               u.username, u.profile_image as user_image,
               COUNT(DISTINCT lk.id) as like_count,
               EXISTS (
                   SELECT 1 FROM likes 
                   WHERE likeable_id = fp.id 
                   AND likeable_type = 'fashion_post' 
                   AND user_id = ?
               ) as is_liked
            FROM fashion_posts fp
        JOIN lives l ON fp.live_id = l.id
        LEFT JOIN artists a ON l.artist_id = a.id
        LEFT JOIN venues v ON l.venue_id = v.id
            JOIN users u ON fp.user_id = u.id
        LEFT JOIN likes lk ON lk.likeable_id = fp.id AND lk.likeable_type = 'fashion_post'
    ";
    
    // パラメータの配列を初期化（最初にuser_idを追加）
    $params = [$userId ?? 0];  // EXISTS句のuser_id用
    
    // 検索条件の追加
    $conditions = [];
    
    if (!empty($search)) {
        $conditions[] = "(l.title LIKE ? OR a.name LIKE ? OR v.name LIKE ? OR fp.description LIKE ? OR fp.items LIKE ?)";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    }
    
    if ($artistId) {
        $conditions[] = "l.artist_id = ?";
        $params[] = $artistId;
    }
    
    if ($venueId) {
        $conditions[] = "l.venue_id = ?";
        $params[] = $venueId;
    }
    
    // WHERE句の構築
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }
    
    // グループ化
    $query .= " GROUP BY fp.id";
    
    // ソート順
    switch ($sortBy) {
        case 'popular':
            $query .= " ORDER BY like_count DESC, fp.created_at DESC";
            break;
        case 'oldest':
            $query .= " ORDER BY fp.created_at ASC";
            break;
        default: // latest
            $query .= " ORDER BY fp.created_at DESC";
    }
    
    // 総件数の取得（パラメータを正しく渡す）
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($query) as counted");
    $countStmt->execute($params);
    $totalPosts = $countStmt->fetchColumn();
    $totalPages = ceil($totalPosts / $perPage);
    
    // ページネーション適用
    $query .= " LIMIT $perPage OFFSET $offset";
    
    // 最終的なクエリ実行
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $fashionPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // アーティスト一覧（フィルター用）
    $artistStmt = $pdo->query("
        SELECT DISTINCT a.id, a.name
        FROM artists a
        JOIN lives l ON a.id = l.artist_id
        JOIN fashion_posts fp ON l.id = fp.live_id
        ORDER BY a.name
    ");
    $artists = $artistStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 会場一覧（フィルター用）
    $venueStmt = $pdo->query("
        SELECT DISTINCT v.id, v.name
        FROM venues v
        JOIN lives l ON v.id = l.venue_id
        JOIN fashion_posts fp ON l.id = fp.live_id
        ORDER BY v.name
    ");
    $venues = $venueStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = 'データベースエラー: ' . $e->getMessage();
    error_log($error);
    $fashionPosts = [];
    $totalPages = 1;
    $artists = [];
    $venues = [];
}

// ページタイトル
$pageTitle = '参戦コーデ一覧';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-9">
            <div class="py-4"> <!-- メインコンテンツのラッパー -->
                <!-- ヘッダー部分 -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0">参戦コーデ一覧</h2>
                    <?php if ($isLoggedIn): ?>
                        <a href="post-fashion.php" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> 新規投稿
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-outline-primary">
                            <i class="bi bi-box-arrow-in-right"></i> ログインして投稿
                        </a>
                    <?php endif; ?>
                </div>

                <!-- 検索・フィルター部分 -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form action="" method="get">
                            <div class="row g-3">
                                <!-- 検索ボックス -->
                                <div class="col-md-3">
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="search" 
                                               placeholder="キーワード検索" value="<?= htmlspecialchars($search) ?>">
                                        <button class="btn btn-outline-secondary" type="submit">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- アーティスト選択 -->
                                <div class="col-md-3">
                                    <select class="form-select" name="artist_id" onchange="this.form.submit()">
                                        <option value="">アーティスト選択</option>
                                        <?php foreach ($artists as $artist): ?>
                                            <option value="<?= $artist['id'] ?>" <?= $artistId == $artist['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($artist['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- 会場選択 -->
                                <div class="col-md-3">
                                    <select class="form-select" name="venue_id" onchange="this.form.submit()">
                                        <option value="">会場選択</option>
                                        <?php foreach ($venues as $venue): ?>
                                            <option value="<?= $venue['id'] ?>" <?= $venueId == $venue['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($venue['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- ソート順 -->
                                <div class="col-md-3">
                                    <select class="form-select" name="sort" onchange="this.form.submit()">
                                        <option value="latest" <?= $sortBy == 'latest' ? 'selected' : '' ?>>新着順</option>
                                        <option value="popular" <?= $sortBy == 'popular' ? 'selected' : '' ?>>人気順</option>
                                        <option value="oldest" <?= $sortBy == 'oldest' ? 'selected' : '' ?>>古い順</option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 参戦コーデ一覧 -->
                <?php if (!empty($fashionPosts)): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
                        <?php foreach ($fashionPosts as $post): ?>
                            <div class="col">
                                <div class="card h-100">
                                    <a href="fashion-detail.php?id=<?= (int)$post['id'] ?>" class="text-decoration-none">
                                        <img src="uploads/fashion/<?= $post['image_url'] ?>" class="card-img-top" alt="参戦コーデ" style="height: 250px; object-fit: cover;">
                                    </a>
                                    <div class="card-body">
                                        <h5 class="card-title">
                                            <a href="live-detail.php?id=<?= $post['live_id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($post['live_title']) ?>
                                            </a>
                                        </h5>
                                        <p class="card-text text-muted">
                                            <?= date('Y年m月d日', strtotime($post['live_date'])) ?>
                                            <?php if ($post['artist_name']): ?>
                                                <br><i class="bi bi-music-note-beamed"></i> <?= htmlspecialchars($post['artist_name']) ?>
                                            <?php endif; ?>
                                            <?php if ($post['venue_name']): ?>
                                                <br><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($post['venue_name']) ?>
                                            <?php endif; ?>
                                        </p>
                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <div class="d-flex align-items-center">
                                                <img src="<?= !empty($post['user_image']) ? 'uploads/profiles/' . $post['user_image'] : 'img/default-profile.png' ?>" 
                                                     class="rounded-circle me-2" width="30" height="30" alt="ユーザー画像">
                                                <span class="text-muted"><?= htmlspecialchars($post['username']) ?></span>
                                            </div>
                                            <div>
                                                <button class="btn btn-link text-decoration-none like-button <?= $post['is_liked'] ? 'liked' : '' ?>"
                                                        data-id="<?= $post['id'] ?>" data-type="fashion_post">
                                                    <i class="bi bi-heart<?= $post['is_liked'] ? '-fill' : '' ?>"></i>
                                                    <span class="like-count"><?= $post['like_count'] ?></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        参戦コーデの投稿がありません。最初の投稿をしてみませんか？
                    </div>

                    <?php if ($isLoggedIn): ?>
                        <div class="text-center my-5">
                            <a href="post-fashion.php" class="btn btn-lg btn-primary">
                                <i class="bi bi-plus-lg"></i> 参戦コーデを投稿する
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- ページネーション -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&artist_id=<?= $artistId ?>&venue_id=<?= $venueId ?>&sort=<?= $sortBy ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&artist_id=<?= $artistId ?>&venue_id=<?= $venueId ?>&sort=<?= $sortBy ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&artist_id=<?= $artistId ?>&venue_id=<?= $venueId ?>&sort=<?= $sortBy ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- スタイルセクションに追加 -->
<style>
    .like-button {
        transition: all 0.2s;
        padding: 0;
        color: #6c757d;
    }
    .like-button.liked {
        color: #dc3545;
    }
    .like-button:hover {
        color: #dc3545;
    }
    .like-button i {
        font-size: 1.2rem;
    }

    /* レイアウト調整用のスタイル */
    @media (min-width: 992px) {
        .pe-lg-4 {
            padding-right: 2rem !important;
        }
    }

    /* カード間の余白調整 */
    .card {
        margin-bottom: 1.5rem;
    }

    /* 検索フィルターの余白調整 */
    .card .card-body {
        padding: 1.25rem;
    }

    /* カードのレイアウト調整 */
    .card {
        margin-bottom: 1.5rem;
    }
    
    /* カードの画像サイズ調整 */
    .card-img-top {
        height: 250px;
        object-fit: cover;
    }

    /* レスポンシブ対応 */
    @media (min-width: 768px) {
        .row-cols-md-2 > * {
            flex: 0 0 auto;
            width: 50%;
        }
    }
    
    @media (min-width: 1200px) {
        .row-cols-xl-3 > * {
            flex: 0 0 auto;
            width: 33.333333%;
        }
    }
</style>

<!-- JavaScriptセクションに追加（ページ末尾） -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.like-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            if (!<?= $isLoggedIn ? 'true' : 'false' ?>) {
                window.location.href = 'login.php';
                return;
            }

            const id = this.dataset.id;
            const type = this.dataset.type;
            const icon = this.querySelector('i');
            const likeCount = this.querySelector('.like-count');
            
            fetch('ajax/toggle-like.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: id,
                    type: type
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
                    alert('いいねの処理に失敗しました: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('いいねの処理中にエラーが発生しました。');
            });
        });
    });
});
</script>
</body>
</html> 