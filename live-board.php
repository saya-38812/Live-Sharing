<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class BoardManager {
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
            'popularTags' => [],
            'upcomingLives' => []
        ];
    }
    
    public function loadPageData(int $liveId = null, string $category = null): void {
        try {
            // カテゴリーとライブIDでフィルタリングしたスレッドを取得
            $this->data['threads'] = $this->getFilteredThreads($liveId, $category);
            $this->data['popularThreads'] = $this->getPopularThreads($liveId);
            $this->data['latestPosts'] = $this->getLatestPosts($liveId);
            $this->data['popularTags'] = $this->getPopularTags();
            $this->data['upcomingLives'] = $this->getUpcomingLives();
            
            // 選択されたライブの情報を取得
            if ($liveId) {
                $this->data['selectedLive'] = $this->getLiveInfo($liveId);
            }
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getFilteredThreads(int $liveId = null, string $category = null): array {
        $sql = "
            SELECT t.id, t.board_id, t.live_id, t.user_id, 
                   t.title, t.content, t.status, t.created_at,
                   u.username,
                   b.name as board_name,
                   b.icon as board_icon,
                   b.color_code as board_color,
                   l.title as live_title,
                   COUNT(DISTINCT c.id) as comment_count
            FROM threads t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN boards b ON t.board_id = b.id
            LEFT JOIN lives l ON t.live_id = l.id
            LEFT JOIN comments c ON c.thread_id = t.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // ライブIDでフィルタリング
        if ($liveId) {
            $sql .= " AND t.live_id = ?";
            $params[] = $liveId;
        }
        
        // カテゴリーでフィルタリング
        if ($category) {
            // b.slugの代わりにb.nameを使用
            $sql .= " AND LOWER(b.name) LIKE ?";
            
            // カテゴリー名をマッピング
            $categoryMap = [
                'setlist' => '%セットリスト%',
                'items' => '%持ち物%',
                'fashion' => '%コーデ%'
            ];
            
            $params[] = $categoryMap[$category] ?? '%' . $category . '%';
        }
        
        $sql .= "
            GROUP BY t.id, u.id, b.id, l.id
            ORDER BY t.created_at DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getLiveInfo(int $liveId): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, v.name as venue_name, a.name as artist_name
            FROM lives l
            JOIN venues v ON l.venue_id = v.id
            LEFT JOIN artists a ON l.artist_id = a.id
            WHERE l.id = ?
        ");
        $stmt->execute([$liveId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    private function getPopularThreads(int $liveId = null): array {
        $sql = "
            SELECT t.id, t.board_id, t.live_id, t.user_id, 
                   t.title, t.content, t.status, t.created_at,
                   u.username,
                   b.name as board_name,
                   b.icon as board_icon,
                   b.color_code as board_color,
                   l.title as live_title,
                   COUNT(DISTINCT c.id) as comment_count
            FROM threads t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN boards b ON t.board_id = b.id
            LEFT JOIN lives l ON t.live_id = l.id
            LEFT JOIN comments c ON c.thread_id = t.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($liveId) {
            $sql .= " AND t.live_id = ?";
            $params[] = $liveId;
        }
        
        $sql .= "
            GROUP BY t.id, u.id, b.id, l.id
            ORDER BY comment_count DESC
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getLatestPosts(int $liveId = null): array {
        $sql = "
            SELECT p.*, u.username, t.title as thread_title
            FROM posts p
            JOIN users u ON p.user_id = u.id
            JOIN threads t ON p.thread_id = t.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($liveId) {
            $sql .= " AND t.live_id = ?";
            $params[] = $liveId;
        }
        
        $sql .= "
            ORDER BY p.created_at DESC
            LIMIT 5
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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
    
    private function getUpcomingLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, 
                   v.name as venue_name,
                   a.name as artist_name,
                   et.name as event_type_name
            FROM lives l
            LEFT JOIN venues v ON l.venue_id = v.id
            LEFT JOIN artists a ON l.artist_id = a.id
            LEFT JOIN event_types et ON l.event_type_id = et.id
            WHERE l.date >= CURDATE()
            AND l.status = 'upcoming'
            ORDER BY l.date ASC
            LIMIT 10
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    public function searchThreads(string $query = ''): array {
        try {
            if (empty($query)) {
                return $this->getPopularThreads();
            }

            $searchQuery = '%' . strtolower($query) . '%';
            
            $stmt = $this->pdo->prepare("
                SELECT t.id, t.board_id, t.live_id, t.user_id, 
                       t.title, t.content, t.status, t.created_at,
                       u.username,
                       b.name as board_name,
                       b.icon as board_icon,
                       b.color_code as board_color,
                       l.title as live_title,
                       COUNT(DISTINCT c.id) as comment_count
                FROM threads t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN boards b ON t.board_id = b.id
                LEFT JOIN lives l ON t.live_id = l.id
                LEFT JOIN comments c ON c.thread_id = t.id
                WHERE LOWER(t.title) LIKE ?
                   OR LOWER(t.content) LIKE ?
                   OR LOWER(u.username) LIKE ?
                GROUP BY t.id, u.id, b.id, l.id
                ORDER BY t.created_at DESC
            ");
            
            $stmt->execute([$searchQuery, $searchQuery, $searchQuery]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return [];
        }
    }

    public function createThread(array $data): ?int {
        try {
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');
            $boardId = !empty($data['board_id']) ? (int)$data['board_id'] : null;
            $liveId = !empty($data['live_id']) ? (int)$data['live_id'] : null;
            
            if (empty($title)) {
                $this->error = 'タイトルを入力してください。';
                return null;
            }

            if (empty($content)) {
                $this->error = '本文を入力してください。';
                return null;
            }

            // トランザクション開始
            $this->pdo->beginTransaction();

            try {
                // スレッドの作成
                $stmt = $this->pdo->prepare("
                    INSERT INTO threads (
                        title,
                        content,
                        user_id,
                        board_id,
                        live_id,
                        status,
                        created_at,
                        updated_at
                    ) VALUES (
                        :title,
                        :content,
                        :user_id,
                        :board_id,
                        :live_id,
                        'active',
                        NOW(),
                        NOW()
                    )
                ");
                
                $stmt->execute([
                    ':title' => $title,
                    ':content' => $content,
                    ':user_id' => $this->userId,
                    ':board_id' => $boardId,
                    ':live_id' => $liveId
                ]);
                
                $threadId = $this->pdo->lastInsertId();

                // 最初の投稿を作成
                $stmt = $this->pdo->prepare("
                    INSERT INTO posts (
                        thread_id,
                        user_id,
                        content,
                        created_at
                    ) VALUES (
                        :thread_id,
                        :user_id,
                        :content,
                        NOW()
                    )
                ");
                
                $stmt->execute([
                    ':thread_id' => $threadId,
                    ':user_id' => $this->userId,
                    ':content' => $content
                ]);

                $this->pdo->commit();
                return $threadId;
                
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log('Thread Creation Error: ' . $e->getMessage());
            echo $e->getMessage(); // デバッグ用
            $this->error = 'スレッドの作成に失敗しました。';
            return null;
        }
    }

    public function getAvailableLives(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT l.id, l.title, l.date, l.venue_id, l.artist_id,
                       v.name as venue_name, a.name as artist_name
                FROM lives l
                LEFT JOIN venues v ON l.venue_id = v.id
                LEFT JOIN artists a ON l.artist_id = a.id
                WHERE l.date >= CURDATE()
                AND l.status = 'upcoming'
                ORDER BY l.date ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return [];
        }
    }

    public function getBoards(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, name, description, icon, color_code 
                FROM boards 
                ORDER BY id
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return [];
        }
    }
}

// ページの初期化
$userId = $_SESSION['user_id'] ?? null;
$manager = new BoardManager($pdo, $userId);

// URLパラメータからライブIDとカテゴリーを取得
$liveId = isset($_GET['live_id']) ? (int)$_GET['live_id'] : null;
$category = isset($_GET['category']) ? $_GET['category'] : null;

// データを読み込む
$manager->loadPageData($liveId, $category);

// データを取得
$data = $manager->getData();
$threads = $data['threads'] ?? [];
$popularThreads = $data['popularThreads'] ?? [];
$latestPosts = $data['latestPosts'] ?? [];
$popularTags = $data['popularTags'] ?? [];
$upcomingLives = $data['upcomingLives'] ?? [];
$selectedLive = $data['selectedLive'] ?? null;

// エラーチェック
if ($error = $manager->getError()) {
    exit($error);
}

// データの取得
extract($data);

// 検索クエリの取得
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';

// 検索結果または通常のスレッドを取得
$threads = $search_query ? $manager->searchThreads($search_query) : $popularThreads;

// POSTリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_thread'])) {
    error_log('Received POST request for thread creation');
    error_log('POST data: ' . print_r($_POST, true));
    
    if (!isset($_SESSION['user_id'])) {
        error_log('User not logged in');
        header('Location: login.php');
        exit;
    }
    
    $threadId = $manager->createThread($_POST);
    if ($threadId) {
        error_log("Thread created successfully with ID: $threadId");
        header("Location: thread-detail.php?id=" . $threadId);
        exit;
    } else {
        error_log('Thread creation failed: ' . $manager->getError());
    }
}

// ページの初期化部分に追加
$boards = $manager->getBoards();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>掲示板 - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <!-- Select2のCSSとJSを追加（headタグ内） -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        /* 共通スタイル */
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

        /* 掲示板専用スタイル */
        .board-category {
            padding: 1rem;
            border-radius: 8px;
            background-color: white;
            border: 1px solid rgba(var(--theme-color-rgb), 0.1);
            margin-bottom: 1rem;
            transition: transform 0.2s;
        }
        .board-category:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(var(--theme-color-rgb), 0.1);
        }
        .thread-card {
            transition: all 0.3s ease;
        }
        .thread-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .thread-card a {
            color: inherit;
        }
        .thread-card a:hover {
            text-decoration: none;
            background-color: var(--theme-bg-light);
        }
        .category-icon {
            font-size: 1.5rem;
            color: var(--theme-color);
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

        /* スレッドカードのホバーエフェクト */
        .thread-card.hover-effect {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .thread-card.hover-effect:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* リンクのスタイルをリセット */
        .threads-list a:hover {
            text-decoration: none;
        }

        /* スレッドカードのリンクスタイル */
        .thread-link {
            display: block;
            color: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .thread-link:hover {
            text-decoration: none;
            color: inherit;
        }

        /* カードのアクティブ状態 */
        .thread-card:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        /* スレッドカードのスタイル */
        .threads-list .card {
            transition: all 0.3s ease;
        }

        .threads-list .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .threads-list .card a {
            display: block;
        }

        .threads-list .card a:hover {
            text-decoration: none;
        }

        /* Select2のカスタマイズ */
        .select2-container--bootstrap-5 {
            width: 100% !important;
        }

        .select2-container--bootstrap-5 .select2-selection {
            min-height: 38px;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }

        .select2-container--bootstrap-5 .select2-selection--single {
            padding: 0.375rem 0.75rem;
        }

        .select2-container--bootstrap-5 .select2-selection__rendered {
            line-height: 1.5;
            color: #212529;
        }

        .select2-container--bootstrap-5 .select2-search__field {
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }

        .select2-container--bootstrap-5 .select2-results__option {
            padding: 0.5rem;
        }

        .select2-container--bootstrap-5 .select2-results__option--highlighted {
            background-color: var(--theme-color);
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
                    <form class="d-flex gap-2" method="GET" action="">
                        <div class="input-group">
                            <span class="input-group-text bg-white">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="search" 
                                   class="form-control border-start-0" 
                                   name="q" 
                                   value="<?= h($search_query) ?>" 
                                   placeholder="投稿を検索...">
                        </div>
                        <button type="submit" class="btn btn-primary text-nowrap">検索</button>
                        <?php if (!empty($search_query)): ?>
                            <a href="<?= h($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary text-nowrap">
                                クリア
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- 検索結果の表示 -->
            <?php if (!empty($search_query)): ?>
                <div class="alert alert-info mb-4">
                    <i class="bi bi-search me-2"></i>
                    "<?= h($search_query) ?>" の検索結果: <?= count($threads) ?>件
                </div>
            <?php endif; ?>

            <!-- 選択されたライブの情報 -->
            <?php if ($selectedLive): ?>
            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-music-note-beamed fs-4 me-2"></i>
                    <div>
                        <h5 class="mb-1"><?= htmlspecialchars($selectedLive['title']) ?></h5>
                        <p class="mb-0">
                            <?= date('Y年m月d日', strtotime($selectedLive['date'])) ?> @ 
                            <?= htmlspecialchars($selectedLive['venue_name']) ?>
                        </p>
                    </div>
                    <a href="live-detail.php?id=<?= $selectedLive['id'] ?>" class="btn btn-sm btn-outline-primary ms-auto">
                        ライブ詳細へ
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- カテゴリータイトル -->
            <?php if ($category): ?>
            <div class="mb-4">
                <h4>
                    <?php if ($category === 'setlist'): ?>
                        <i class="bi bi-music-note-list me-2 text-primary"></i>セットリスト予想
                    <?php elseif ($category === 'items'): ?>
                        <i class="bi bi-bag-check me-2 text-success"></i>持ち物リスト
                    <?php elseif ($category === 'fashion'): ?>
                        <i class="bi bi-person-square me-2 text-danger"></i>参戦コーデ
                    <?php endif; ?>
                </h4>
                <p class="text-muted">
                    <?php if ($category === 'setlist'): ?>
                        みんなでセットリストを予想しよう！
                    <?php elseif ($category === 'items'): ?>
                        ライブの持ち物をシェアしよう！
                    <?php elseif ($category === 'fashion'): ?>
                        ライブの服装を共有しよう！
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- 新規スレッド作成ボタン -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="section-title theme-color mb-0">掲示板</h4>
                <button class="btn btn-primary" onclick="scrollToThreadForm()">
                    <i class="bi bi-plus-lg me-2"></i>新規スレッドを作成
                </button>
            </div>

            <!-- カテゴリーカード -->
            <div class="row g-4">
                <!-- セットリスト予想 -->
                <div class="col-md-4">
                    <a href="live-board-setlist.php" class="card h-100 text-decoration-none category-card setlist">
                        <div class="card-body">
                            <h5 class="card-title" style="color: #0d6efd;">
                                <i class="bi bi-music-note-list me-2" style="color: #0d6efd;"></i>セットリスト予想
                            </h5>
                            <p class="card-text text-muted">みんなでセットリストを予想しよう！</p>
                        </div>
                    </a>
                </div>

                <!-- 持ち物リスト -->
                <div class="col-md-4">
                    <a href="live-board-items.php" class="card h-100 text-decoration-none category-card items">
                        <div class="card-body">
                            <h5 class="card-title text-success">
                                <i class="bi bi-bag-check me-2" style="color: #198754;"></i>持ち物リスト
                            </h5>
                            <p class="card-text text-muted">ライブの持ち物をシェアしよう！</p>
                        </div>
                    </a>
                </div>

                <!-- 参戦コーデ -->
                <div class="col-md-4">
                    <a href="live-board-fashion.php" class="card h-100 text-decoration-none category-card fashion">
                        <div class="card-body">
                            <h5 class="card-title text-danger">
                                <i class="bi bi-person-square me-2" style="color: #dc3545;"></i>参戦コーデ
                            </h5>
                            <p class="card-text text-muted">ライブの服装を共有しよう！</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- スレッド一覧 -->
            <div class="threads-list mt-4">
                <?php if (!empty($threads)): ?>
                    <?php foreach ($threads as $thread): ?>
                        <?php
                            // デバッグ情報の出力
                            error_log('Thread ID: ' . $thread['id']);
                        ?>
                        <!-- シンプルなリンクに変更 -->
                        <div class="card thread-card mb-3">
                        <a href="thread-detail.php?id=<?= (int)$thread['id'] ?>" 
                               class="card-body d-block text-decoration-none">
                                <h5 class="card-title text-dark"><?= h($thread['title']) ?></h5>
                                <div class="thread-meta mb-2 text-muted">
                                    <i class="bi bi-person-fill me-1"></i>投稿者：<?= h($thread['username']) ?>
                                    <i class="bi bi-clock-fill ms-3 me-1"></i><?= time_ago($thread['created_at']) ?>
                                    <i class="bi bi-chat-fill ms-3 me-1"></i><?= (int)$thread['comment_count'] ?>件
                                </div>
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
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (!empty($search_query)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-circle me-2"></i>
                            "<?= h($search_query) ?>" に一致するスレッドは見つかりませんでした。
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info my-4">
                            <i class="bi bi-info-circle me-2"></i>
                            スレッドはまだありません。
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- 新規スレッド作成フォーム -->
            <div class="card mt-4 mb-4" id="threadForm" style="display: none;">
                <div class="card-body">
                    <h5 class="card-title mb-3">新規スレッドを作成</h5>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">カテゴリーを選択</label>
                            <select class="form-select mb-3" name="board_id" required>
                                <option value="">カテゴリーを選択してください</option>
                                <?php foreach ($boards as $board): ?>
                                    <option value="<?= h($board['id']) ?>">
                                        <?= h($board['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ライブを検索・選択</label>
                            <select class="form-select select2-live mb-3" name="live_id">
                                <option value="">ライブを検索...</option>
                                <?php foreach ($upcomingLives as $live): ?>
                                    <option value="<?= h($live['id']) ?>">
                                        <?= h($live['title']) ?> 
                                        <?php if ($live['event_type_name'] === 'フェス'): ?>
                                            (<?= h($live['venue_name']) ?>)
                                        <?php else: ?>
                                            (<?= h($live['artist_name'] ?? '未定') ?> @ <?= h($live['venue_name']) ?>)
                                        <?php endif; ?>
                                        <?= date('Y/m/d', strtotime($live['date'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">スレッドタイトル</label>
                            <input type="text" class="form-control" name="title" 
                                   placeholder="タイトルを入力..." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">本文</label>
                            <textarea class="form-control" name="content" rows="5" 
                                      placeholder="スレッドの内容を入力..." required></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="create_thread" class="btn btn-primary">作成する</button>
                            <button type="button" class="btn btn-outline-secondary" 
                                    onclick="hideThreadForm()">キャンセル</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 検索 -->

            <!-- 人気のタグ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">人気のタグ</h5>
                    <div class="d-flex flex-wrap">
                        <?php foreach ($popularTags as $tag): ?>
                            <a href="?tag=<?= urlencode($tag['name']) ?>" class="popular-tag">
                                #<?= htmlspecialchars($tag['name']) ?>
                                <span class="ms-1"><?= (int)$tag['use_count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 最新の投稿 -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">最新の投稿</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($latestPosts as $post): ?>
                            <a href="thread-detail.php?id=<?= (int)$post['thread_id'] ?>" 
                               class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($post['thread_title']) ?></h6>
                                    <small><?= time_ago($post['created_at']) ?></small>
                                </div>
                                <small class="text-muted">by <?= htmlspecialchars($post['username']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- jQueryを追加（Select2の依存ライブラリ） -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select2の初期化（ページ読み込み時）
    $('.select2-live').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'ライブ名、アーティスト名、会場名で検索...',
        allowClear: true,
        matcher: function(params, data) {
            if ($.trim(params.term) === '') {
                return data;
            }

            // 検索文字列を小文字に変換
            var term = params.term.toLowerCase();
            var text = data.text.toLowerCase();

            // 検索文字列が含まれているかチェック（複数のキーワードに対応）
            var keywords = term.split(/\s+/);
            for (var i = 0; i < keywords.length; i++) {
                if (text.indexOf(keywords[i]) === -1) {
                    return null;
                }
            }
            return data;
        }
    });

    // スレッド作成フォームの表示・非表示を制御
    const threadForm = document.getElementById('threadForm');
    
    window.scrollToThreadForm = function() {
        threadForm.style.display = 'block';
        threadForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    
    window.hideThreadForm = function() {
        threadForm.style.display = 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const threadLinks = document.querySelectorAll('.thread-link');
    threadLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            console.log('Clicked link:', this.href);
        });
    });
});
</script>
</body>
</html> 