<?php
require_once 'config/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class ItemBoardManager {
    private $pdo;
    private $userId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, int $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = null;
        $this->data = [
            'itemLists' => [],
            'popularTags' => [],
            'upcomingLives' => []
        ];
    }
    
    public function loadPageData(string $sort = 'latest', string $filter = 'all', string $search = ''): void {
        try {
            $this->data['itemLists'] = $this->getItemLists($sort, $filter, $search);
            $this->data['popularTags'] = $this->getPopularTags();
            $this->data['upcomingLives'] = $this->getUpcomingLives();
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getItemLists(string $sort, string $filter, string $search = ''): array {
        $sql = "
            SELECT il.*, u.username, u.profile_image,
                   l.title as live_title, l.date as live_date,
                   COUNT(DISTINCT i.id) as item_count,
                   COUNT(DISTINCT lk.id) as like_count,
                   EXISTS (
                       SELECT 1 FROM likes 
                       WHERE likeable_id = il.id 
                       AND likeable_type = 'item_list' 
                       AND user_id = ?
                   ) as is_liked
            FROM item_lists il
            JOIN users u ON il.user_id = u.id
            JOIN lives l ON il.live_id = l.id
            LEFT JOIN items i ON i.item_list_id = il.id
            LEFT JOIN likes lk ON lk.likeable_id = il.id AND lk.likeable_type = 'item_list'
            WHERE 1=1
        ";

        $params = [$this->userId];

        // 検索条件の追加
        if (!empty($search)) {
            $sql .= " AND (il.title LIKE ? OR il.description LIKE ? OR il.tags LIKE ? OR l.title LIKE ?)";
            $searchTerm = "%{$search}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        // フィルター条件の追加
        if ($filter === 'upcoming') {
            $sql .= " AND l.date >= CURDATE()";
        } elseif ($filter === 'past') {
            $sql .= " AND l.date < CURDATE()";
        }

        $sql .= " GROUP BY il.id";

        // ソート条件の追加
        $sql .= match($sort) {
            'popular' => " ORDER BY like_count DESC",
            'items' => " ORDER BY item_count DESC",
            default => " ORDER BY il.created_at DESC"
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPopularTags(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, COUNT(tg.id) as use_count
            FROM tags t
            JOIN taggables tg ON t.id = tg.tag_id
            WHERE tg.taggable_type = 'item_list'
            GROUP BY t.id
            ORDER BY use_count DESC
            LIMIT 10
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getUpcomingLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT id, title, date 
            FROM lives 
            WHERE date >= CURDATE()
            ORDER BY date ASC
        ");
        $stmt->execute();
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

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ソートとフィルターの取得
$sort = $_GET['sort'] ?? 'latest';
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

// ページマネージャーの初期化とデータ読み込み
$pageManager = new ItemBoardManager($pdo, $_SESSION['user_id']);
$pageManager->loadPageData($sort, $filter, $search);

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
    <title>持ち物リスト - LiveShare</title>
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

        /* 持ち物リスト専用スタイル */
        .items-card {
            border-left: 3px solid #198754;  /* 固定の緑色を維持 */
            transition: transform 0.2s;
        }
        .items-card:hover {
            transform: translateX(5px);
            background-color: #f8fff9;  /* 固定の背景色を維持 */
        }

        .item-list {
            list-style: none;
            padding: 0;
        }

        .item-list-item {
            padding: 0.5rem;
            background-color: white;
            border: 1px solid rgba(var(--theme-color-rgb), 0.1);
            margin-bottom: 0.5rem;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .item-list-item:hover {
            border-color: var(--theme-color);
            background-color: var(--theme-bg-light);
        }

        .item-category-badge {
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            background-color: var(--theme-bg-light);
            color: var(--theme-color);
        }

        .comment-card {
            border-left: 3px solid var(--theme-color);
            margin-bottom: 1rem;
        }

        .comment-card:hover {
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="theme-color">持ち物リスト</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newItemListModal">
                    <i class="bi bi-plus-lg me-2"></i>新規作成
                </button>
            </div>

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
                                   placeholder="持ち物リストを検索..." 
                                   name="search"
                                   value="<?= h($search) ?>">
                        </div>
                        <!-- 現在のソートとフィルターを維持 -->
                        <input type="hidden" name="sort" value="<?= h($sort) ?>">
                        <input type="hidden" name="filter" value="<?= h($filter) ?>">
                        <button type="submit" class="btn btn-primary text-nowrap">検索</button>
                        <?php if (!empty($search)): ?>
                            <a href="?sort=<?= h($sort) ?>&filter=<?= h($filter) ?>" 
                               class="btn btn-outline-secondary text-nowrap">
                                検索解除
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- 検索結果の表示 -->
            <?php if (!empty($search)): ?>
                <div class="alert alert-info mb-4">
                    「<?= h($search) ?>」の検索結果: <?= count($itemLists) ?>件
                </div>
            <?php endif; ?>

            <!-- フィルター -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="btn-group">
                            <a href="?filter=all" class="btn btn-outline-primary <?php echo $filter === 'all' ? 'active' : ''; ?>">
                                すべて
                            </a>
                            <a href="?filter=upcoming" class="btn btn-outline-primary <?php echo $filter === 'upcoming' ? 'active' : ''; ?>">
                                これから
                            </a>
                            <a href="?filter=past" class="btn btn-outline-primary <?php echo $filter === 'past' ? 'active' : ''; ?>">
                                過去
                            </a>
                        </div>
                        <div class="btn-group">
                            <a href="?sort=latest" class="btn btn-outline-secondary <?php echo $sort === 'latest' ? 'active' : ''; ?>">
                                新着順
                            </a>
                            <a href="?sort=popular" class="btn btn-outline-secondary <?php echo $sort === 'popular' ? 'active' : ''; ?>">
                                人気順
                            </a>
                            <a href="?sort=items" class="btn btn-outline-secondary <?php echo $sort === 'items' ? 'active' : ''; ?>">
                                アイテム数順
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 持ち物リスト一覧 -->
            <?php if (!empty($itemLists)): ?>
                <?php foreach ($itemLists as $list): ?>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <a href="item-list-detail.php?id=<?= (int)$list['id'] ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($list['title']) ?>
                                        </a>
                                    </h5>
                                    <p class="text-muted mb-0">
                                        <small>
                                            <a href="live-detail.php?id=<?= (int)$list['live_id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($list['live_title']) ?>
                                            </a>
                                            (<?= date('Y/m/d', strtotime($list['live_date'])) ?>)
                                        </small>
                                    </p>
                                </div>
                                <button class="btn btn-link text-decoration-none like-button <?= $list['is_liked'] ? 'liked' : '' ?>"
                                        data-id="<?= (int)$list['id'] ?>" data-type="item_list">
                                    <i class="bi bi-heart<?= $list['is_liked'] ? '-fill' : '' ?>"></i>
                                    <span class="like-count"><?= (int)$list['like_count'] ?></span>
                                </button>
                            </div>
                            <div class="d-flex align-items-center">
                                <img src="<?= htmlspecialchars($list['profile_image'] ?? 'img/default-profile.jpg') ?>" 
                                     class="rounded-circle me-2" width="24" height="24" alt="Profile">
                                <span class="text-muted">
                                    <?= htmlspecialchars($list['username']) ?>
                                </span>
                                <span class="mx-2">•</span>
                                <span class="text-muted">
                                    <i class="bi bi-list-check me-1"></i><?= (int)$list['item_count'] ?>アイテム
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <?php if (!empty($search)): ?>
                        「<?= h($search) ?>」に一致する持ち物リストは見つかりませんでした。
                    <?php else: ?>
                        まだ持ち物リストの投稿がありません。
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 人気のタグ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">人気のタグ</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($popularTags as $tag): ?>
                            <a href="?tag=<?= urlencode($tag['name']) ?>" 
                               class="badge rounded-pill text-bg-light text-decoration-none">
                                #<?= htmlspecialchars($tag['name']) ?>
                                <span class="ms-1"><?= (int)$tag['use_count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 投稿のヒント -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">リスト作成のヒント</h5>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                            カテゴリー分けをしよう
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                            必須アイテムを明記しよう
                        </li>
                        <li>
                            <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                            タグを活用しよう
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 新規リスト作成モーダル -->
<div class="modal fade" id="newItemListModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">持ち物リストを作成</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="create-item-list.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ライブを選択</label>
                        <select class="form-select" name="live_id" required>
                            <option value="">選択してください</option>
                            <?php foreach ($upcomingLives as $live): ?>
                                <option value="<?= (int)$live['id'] ?>">
                                    <?= htmlspecialchars($live['title']) ?> 
                                    (<?= date('Y/m/d', strtotime($live['date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">タイトル</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">説明</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">タグ（カンマ区切り）</label>
                        <input type="text" class="form-control" name="tags" 
                               placeholder="例: 夏フェス,初心者,雨対策">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">作成する</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- いいね機能用のJavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.like-button').forEach(button => {
        button.addEventListener('click', function() {
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
                    post_id: id,
                    type: type
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.classList.toggle('liked');
                    icon.classList.toggle('bi-heart');
                    icon.classList.toggle('bi-heart-fill');
                    likeCount.textContent = data.likes;
                }
            });
        });
    });
});
</script>
</body>
</html> 