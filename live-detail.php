<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

class LiveDetailManager {
    private $pdo;
    private $userId;
    private $liveId;
    private $error;
    private $data;

    public function __construct(PDO $pdo, ?int $userId, int $liveId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->liveId = $liveId;
        $this->error = null;
        $this->data = [];
    }

    public function loadLiveData(): void {
        try {
            // ライブの基本情報を取得
            $stmt = $this->pdo->prepare("
        SELECT l.*, 
               v.name as venue_name,
                       et.name as event_type,
                       CASE 
                           WHEN l.artist_id IS NOT NULL THEN a.name
                           ELSE NULL
                       END as main_artist_name,
                       u.username as created_by_username
        FROM lives l
                JOIN venues v ON l.venue_id = v.id
                JOIN event_types et ON l.event_type_id = et.id
        LEFT JOIN artists a ON l.artist_id = a.id
                LEFT JOIN users u ON l.created_by = u.id
        WHERE l.id = ?
    ");
            $stmt->execute([$this->liveId]);
            $this->data['live'] = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$this->data['live']) {
                throw new Exception('ライブが見つかりませんでした');
            }

            // フェスの場合は出演アーティスト情報を取得
            if ($this->data['live']['event_type'] === 'festival') {
                $stmt = $this->pdo->prepare("
                    SELECT p.*, a.name as artist_name
                    FROM performances p
                    JOIN artists a ON p.artist_id = a.id
                    WHERE p.event_id = ?  -- live_id から event_id に変更
                    ORDER BY p.start_time ASC, p.performance_order ASC
                ");
                $stmt->execute([$this->liveId]);
                $this->data['performances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // ブックマーク状態の確認
            if ($this->userId) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM bookmarks 
                    WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'live'
                ");
                $stmt->execute([$this->userId, $this->liveId]);
                $this->data['is_bookmarked'] = (bool)$stmt->fetchColumn();
            } else {
                $this->data['is_bookmarked'] = false;
            }

        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    public function toggleBookmark(): bool {
        if (!$this->userId) return false;

        try {
            $this->pdo->beginTransaction();

            if ($this->data['is_bookmarked']) {
                // ブックマーク解除
                $stmt = $this->pdo->prepare("
                    DELETE FROM bookmarks 
                    WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'live'
                ");
                $stmt->execute([$this->userId, $this->liveId]);
            } else {
                // ブックマーク追加
                $stmt = $this->pdo->prepare("
                    INSERT INTO bookmarks (user_id, bookmarkable_id, bookmarkable_type, created_at) 
                    VALUES (?, ?, 'live', NOW())
                ");
                $stmt->execute([$this->userId, $this->liveId]);
            }

            $this->pdo->commit();
            return true;
} catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }

    public function getData(): array {
        return $this->data;
    }

    public function getError(): ?string {
        return $this->error;
    }

    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        $this->error = 'データの取得に失敗しました。';
    }
}

// ページの初期化
$liveId = $_GET['id'] ?? null;
if (!$liveId) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$manager = new LiveDetailManager($pdo, $userId, (int)$liveId);
$manager->loadLiveData();

// ブックマーク処理
if (isset($_POST['toggle_bookmark']) && $userId) {
    $manager->toggleBookmark();
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// アーティスト追加処理をAjax対応に修正
if (isset($_POST['add_artist']) && $userId) {
    try {
        $pdo->beginTransaction();
        
        $artistId = null;
        // アーティストが存在するか確認
        $stmt = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
        $stmt->execute([$_POST['artist_name']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $artistId = $result['id'];
        } else {
            // 新しいアーティストを作成
            $stmt = $pdo->prepare("INSERT INTO artists (name, created_at) VALUES (?, NOW())");
            $stmt->execute([$_POST['artist_name']]);
            $artistId = $pdo->lastInsertId();
        }
        
        // 現在の最大performance_orderを取得
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(performance_order), 0) + 1 AS next_order
            FROM performances 
            WHERE event_id = ?
        ");
        $stmt->execute([$liveId]);
        $nextOrder = $stmt->fetchColumn();
        
        // パフォーマンスを追加
        $stmt = $pdo->prepare("
            INSERT INTO performances (
                event_id, artist_id, start_time, performance_order, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $liveId,
            $artistId,
            !empty($_POST['start_time']) ? $_POST['start_time'] : null,
            $nextOrder,
            $userId
        ]);
        
        $performanceId = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // Ajaxリクエストの場合は新しいパフォーマンス情報をJSONで返す
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            $newPerformance = [
                'id' => $performanceId,
                'artist_id' => $artistId,
                'artist_name' => $_POST['artist_name'],
                'start_time' => !empty($_POST['start_time']) ? $_POST['start_time'] : null,
                'created_by' => $userId
            ];
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'performance' => $newPerformance]);
            exit;
        }
        
        // 通常のリクエストの場合はページをリロード
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'アーティストの追加に失敗しました: ' . $e->getMessage();
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    }
}

// アーティスト削除処理をAjax対応に修正
if (isset($_POST['remove_artist']) && $userId) {
    try {
        $performanceId = (int)$_POST['performance_id'];
        
        // 削除権限チェック
        $stmt = $pdo->prepare("
            SELECT 1 FROM performances p
            JOIN lives l ON p.event_id = l.id
            WHERE p.id = ? AND (l.created_by = ? OR p.created_by = ?)
        ");
        $stmt->execute([$performanceId, $userId, $userId]);
        
        if ($stmt->fetchColumn()) {
            $stmt = $pdo->prepare("DELETE FROM performances WHERE id = ?");
            $stmt->execute([$performanceId]);
            
            // Ajaxリクエストの場合はJSON応答
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            
            // 通常のリクエストの場合はページをリロード
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $error = '削除権限がありません';
            
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $error]);
                exit;
            }
        }
    } catch (Exception $e) {
        $error = 'アーティストの削除に失敗しました: ' . $e->getMessage();
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    }
}

// レビュー投稿処理を修正
if (isset($_POST['post_review']) && $userId) {
    try {
        $content = trim($_POST['review_content']);
        $tags = isset($_POST['review_tags']) ? $_POST['review_tags'] : [];
        
        if (empty($content)) {
            throw new Exception('感想を入力してください');
        }
        
        $pdo->beginTransaction();
        
        // レビューを追加（ratingカラムがある場合は0を設定）
        $stmt = $pdo->prepare("
            INSERT INTO reviews (live_id, created_by, content, created_at" . 
            (columnExists($pdo, 'reviews', 'rating') ? ", rating" : "") . ") 
            VALUES (?, ?, ?, NOW()" . 
            (columnExists($pdo, 'reviews', 'rating') ? ", 0" : "") . ")
        ");
        $stmt->execute([$liveId, $userId, $content]);
        
        $reviewId = $pdo->lastInsertId();
        
        // タグを追加
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $stmt = $pdo->prepare("
                    INSERT INTO review_tags (review_id, tag_name)
                    VALUES (?, ?)
                ");
                $stmt->execute([$reviewId, $tag]);
            }
        }
        
        $pdo->commit();
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'レビューの投稿に失敗しました: ' . $e->getMessage();
    }
}

// レビュー削除処理
if (isset($_POST['delete_review_id']) && $userId) {
    try {
        $reviewId = (int)$_POST['delete_review_id'];
        
        // 削除権限チェック
        $stmt = $pdo->prepare("
            SELECT 1 FROM reviews
            WHERE id = ? AND created_by = ?
        ");
        $stmt->execute([$reviewId, $userId]);
        
        if ($stmt->fetchColumn()) {
            $pdo->beginTransaction();
            
            // タグを削除
            $stmt = $pdo->prepare("DELETE FROM review_tags WHERE review_id = ?");
            $stmt->execute([$reviewId]);
            
            // レビューを削除
            $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$reviewId]);
            
            $pdo->commit();
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $error = '削除権限がありません';
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'レビューの削除に失敗しました: ' . $e->getMessage();
    }
}

// カラムが存在するかチェックする関数
function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

if ($error = $manager->getError()) {
    exit($error);
}

$data = $manager->getData();
$live = $data['live'];
$performances = $data['performances'] ?? [];
$isBookmarked = $data['is_bookmarked'];

// ライブタイプを判定
$isFestival = ($live['event_type'] === 'festival');

// 実際のセットリストデータを取得
$actualSetlist = null;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM actual_setlists
        WHERE live_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$liveId]);
    $actualSetlist = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // エラー処理
    error_log('セットリスト取得エラー: ' . $e->getMessage());
}

// 実際のセットリスト追加/編集処理
if (isset($_POST['save_actual_setlist']) && $userId) {
    try {
        $pdo->beginTransaction();
        
        $description = trim($_POST['setlist_description']);
        
        if (empty($description)) {
            throw new Exception('セットリスト内容を入力してください');
        }
        
        if (isset($_POST['setlist_id'])) {
            // 既存のセットリストを更新
            $setlistId = (int)$_POST['setlist_id'];
            
            $stmt = $pdo->prepare("
                UPDATE actual_setlists 
                SET description = ?, updated_at = NOW()
                WHERE id = ? AND live_id = ?
            ");
            $stmt->execute([$description, $setlistId, $liveId]);
        } else {
            // 新しいセットリストを作成
            $stmt = $pdo->prepare("
                INSERT INTO actual_setlists (live_id, title, description, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$liveId, 'セットリスト', $description]);
        }
        
        $pdo->commit();
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'セットリストの保存に失敗しました: ' . $e->getMessage();
    }
}

// レビューデータを取得（タグ情報も含む）
$reviews = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, u.username 
        FROM reviews r
        JOIN users u ON r.created_by = u.id
        WHERE r.live_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$liveId]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 各レビューのタグを取得
    foreach ($reviews as &$review) {
        $stmt = $pdo->prepare("
            SELECT tag_name FROM review_tags
            WHERE review_id = ?
        ");
        $stmt->execute([$review['id']]);
        $review['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    // エラー処理
}

// 利用可能なタグのリスト
$availableTags = ['MC', '演出', 'パフォーマンス', '衣装', 'セトリ', '音響', '照明', '会場', 'ファンサ', 'その他'];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($live['title']) ?> - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        .performance-card {
            transition: transform 0.2s;
        }
        .performance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        /* コンテンツエリアの調整 */
        .content-wrapper {
                display: flex;
                flex-wrap: wrap;
            }
            
        .main-content {
                flex: 1;
            min-width: 0; /* flexboxでの縮小を許可 */
        }
        
        /* モバイル表示の調整 */
        @media (max-width: 767.98px) {
            .content-wrapper {
                flex-direction: column;
            }
            
            .sidebar-container {
                order: 2;
                margin-top: 2rem;
            }
        }
        
        /* セットリスト表示用 */
        .setlist-item {
            border-left: 3px solid #0d6efd;
            padding-left: 15px;
            margin-bottom: 10px;
        }
        
        /* レビュー表示用 */
        .review-card {
            margin-bottom: 15px;
            border-left: 3px solid #198754;
            background-color: #f8f9fa;
        }
        
        .review-rating {
            color: #ffc107;
            font-size: 1.2rem;
        }
        
        .badge {
            font-weight: normal;
        }
        
        /* アーティスト情報用 */
        .artist-image {
            width: 100%;
            max-width: 150px;
            height: auto;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 15px;
            display: block;
        }
        
        /* カテゴリーカードのスタイル */
        .category-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0,0,0,0.125);
        }
        
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .category-card.setlist:hover {
            border-color: #0d6efd;
        }
        
        .category-card.items:hover {
            border-color: #198754;
        }
        
        .category-card.fashion:hover {
            border-color: #dc3545;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

            <!-- メインコンテンツエリア -->
            <div class="col-md-10 px-4 py-3">
                <div class="content-wrapper">
        <!-- メインコンテンツ -->
                    <div class="main-content me-md-4">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h1 class="card-title h3"><?= htmlspecialchars($live['title']) ?></h1>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <span class="badge bg-<?= $isFestival ? 'danger' : 'primary' ?>">
                                            <?= htmlspecialchars($live['event_type']) ?>
                                        </span>
                                        <span class="text-muted ms-2">
                                            <?= date('Y年m月d日', strtotime($live['date'])) ?>
                                        </span>
                </div>
                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="bi bi-geo-alt-fill text-danger"></i>
                                            <strong>会場:</strong> <?= htmlspecialchars($live['venue_name']) ?>
                                        </p>
                                        
                                        <?php if (!$isFestival && $live['main_artist_name']): ?>
                                            <p class="mb-1">
                                                <i class="bi bi-person-fill text-primary"></i>
                                                <strong>アーティスト:</strong> <?= htmlspecialchars($live['main_artist_name']) ?>
                                            </p>
            <?php endif; ?>
                    </div>
                                    
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="bi bi-clock-fill text-warning"></i>
                                            <strong>開場:</strong> <?= substr($live['open_time'], 0, 5) ?>
                                        </p>
                                        
                                        <p class="mb-1">
                                            <i class="bi bi-clock-history text-info"></i>
                                            <strong>開演:</strong> <?= substr($live['start_time'], 0, 5) ?>
                        </p>
                    </div>
                </div>
                                
                                <?php if ($live['description']): ?>
                                    <div class="mb-4">
                                        <h5>イベント詳細</h5>
                                        <p class="card-text"><?= nl2br(htmlspecialchars($live['description'])) ?></p>
            </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        投稿者: <?= htmlspecialchars($live['created_by_username']) ?>
                                    </small>
                                </div>
                                    </div>
                                </div>

                        <?php if ($isFestival): ?>
                            <!-- フェスの場合は出演アーティスト一覧 -->
                            <div class="mb-4">
                                <h5>出演アーティスト</h5>
                                <div class="row row-cols-1 row-cols-md-3 g-3" id="performances-container">
                                    <?php foreach ($performances as $performance): ?>
                                        <div class="col performance-item" data-id="<?= $performance['id'] ?>">
                                            <div class="card h-100 performance-card">
                            <div class="card-body">
                                                    <h6 class="card-title">
                                                        <a href="artist-detail.php?id=<?= $performance['artist_id'] ?>" class="text-decoration-none">
                                                            <?= htmlspecialchars($performance['artist_name']) ?>
                                                        </a>
                                                    </h6>
                                                    <?php if ($performance['start_time']): ?>
                                                        <p class="card-text text-muted">
                                                            <i class="bi bi-clock"></i> 
                                                            <?= substr($performance['start_time'], 0, 5) ?>
                                                        </p>
                                        <?php endif; ?>
                                                    
                                                    <?php if (isset($_SESSION['user_id']) && 
                                                            ($_SESSION['user_id'] == $live['created_by'] || 
                                                             $_SESSION['user_id'] == $performance['created_by'])): ?>
                                                        <form method="post" class="mt-2">
                                                            <input type="hidden" name="performance_id" value="<?= $performance['id'] ?>">
                                                            <button type="submit" name="remove_artist" class="btn btn-sm btn-outline-danger remove-artist-btn">
                                                                <i class="bi bi-trash"></i> 削除
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <a href="performance-detail.php?id=<?= $performance['id'] ?>" class="stretched-link"></a>
                                    </div>
                                </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                        <?php if (isset($_SESSION['user_id'])): ?>
                                    <div class="mt-3">
                                        <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#addArtistForm">
                                            <i class="bi bi-plus-circle"></i> アーティストを追加
                            </button>
                                        
                                        <div class="collapse mt-3" id="addArtistForm">
                                            <div class="card card-body">
                                                <form id="add-artist-form" method="post">
                                                    <div class="mb-3">
                                                        <label for="artist_name" class="form-label">アーティスト名</label>
                                                        <input type="text" class="form-control" id="artist_name" name="artist_name" required>
                                            </div>
                                                    <div class="mb-3">
                                                        <label for="start_time" class="form-label">出演時間</label>
                                                        <input type="time" class="form-control" id="start_time" name="start_time">
                                        </div>
                                                    <button type="submit" name="add_artist" class="btn btn-primary">追加する</button>
                                                </form>
                                    </div>
                        </div>
                        </div>
                    <?php endif; ?>
                </div>
                        <?php else: ?>
                            <!-- ワンマンライブの場合は実際のセットリストとレビューを表示 -->
                            
                            <!-- 実際のセットリスト -->
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">実際のセットリスト</h5>
                        <?php if (isset($_SESSION['user_id'])): ?>
                                        <?php if ($actualSetlist): ?>
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#editActualSetlistForm">
                                                <i class="bi bi-pencil"></i> 編集する
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#editActualSetlistForm">
                                                <i class="bi bi-plus-circle"></i> 投稿する
                        </button>
                                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                                <div class="card-body">
                                    <?php if ($actualSetlist): ?>
                                        <div class="setlist-item">
                                            <?php if ($actualSetlist['description']): ?>
                                                <div class="bg-light p-3 rounded mb-3">
                                                    <?php 
                                                    $songs = explode("\n", $actualSetlist['description']);
                                                    foreach ($songs as $index => $song):
                                                        $song = trim($song);
                                                        if (empty($song)) continue;
                                                    ?>
                                                        <div class="song-item mb-2">
                                                            <span class="song-number me-2"><?= $index + 1 ?>.</span>
                                                            <span class="song-title"><?= htmlspecialchars($song) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                            
                                            <div class="d-flex justify-content-between align-items-center mt-3">
                                            <small class="text-muted">
                                                    投稿日: <?= date('Y/m/d H:i', strtotime($actualSetlist['created_at'])) ?>
                                            </small>
                                                
                                                <?php if ($actualSetlist['updated_at'] && $actualSetlist['updated_at'] != $actualSetlist['created_at']): ?>
                                                    <small class="text-muted">
                                                        更新日: <?= date('Y/m/d H:i', strtotime($actualSetlist['updated_at'])) ?>
                                                    </small>
                                                <?php endif; ?>
                                        </div>
                                    </div>
                                        
                                        <?php if (isset($_SESSION['user_id'])): ?>
                                            <div class="collapse mt-3" id="editActualSetlistForm">
                                                <div class="card card-body bg-light">
                                                    <form method="post">
                                                        <input type="hidden" name="setlist_id" value="<?= $actualSetlist['id'] ?>">
                                                        <div class="mb-3">
                                                            <label for="setlist_description" class="form-label">セットリスト内容（実際に演奏された曲）</label>
                                                            <textarea class="form-control" id="setlist_description" name="setlist_description" rows="10"><?= htmlspecialchars($actualSetlist['description'] ?? '') ?></textarea>
                                                            <div class="form-text">1曲ずつ改行して入力してください。例:<br>曲名1<br>曲名2<br>...</div>
                                        </div>
                                                        <button type="submit" name="save_actual_setlist" class="btn btn-primary">保存する</button>
                                                    </form>
                                    </div>
                                </div>
                                        <?php endif; ?>
                    <?php else: ?>
                                        <p class="text-muted">まだ実際のセットリストが投稿されていません。</p>
                                        
                            <?php if (isset($_SESSION['user_id'])): ?>
                                            <div class="collapse" id="editActualSetlistForm">
                                                <div class="card card-body bg-light">
                                                    <form method="post">
                                                        <div class="mb-3">
                                                            <label for="setlist_description" class="form-label">セットリスト内容（実際に演奏された曲）</label>
                                                            <textarea class="form-control" id="setlist_description" name="setlist_description" rows="10" placeholder="曲名1&#10;曲名2&#10;曲名3"></textarea>
                            </div>
                                                        <button type="submit" name="save_actual_setlist" class="btn btn-primary">投稿する</button>
                                                    </form>
                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
            </div>
        </div>

                            <!-- 掲示板カテゴリーカード -->
            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">ライブ掲示板</h5>
                                </div>
                <div class="card-body">
                                    <div class="row g-3">
                                        <!-- セットリスト予想 -->
                                        <div class="col-md-4">
                                            <a href="live-board.php?category=setlist&live_id=<?= $liveId ?>" class="card h-100 text-decoration-none category-card setlist">
                                                <div class="card-body">
                                                    <h5 class="card-title" style="color: #0d6efd;">
                                                        <i class="bi bi-music-note-list me-2" style="color: #0d6efd;"></i>セットリスト予想
                                                    </h5>
                                                    <p class="card-text text-muted small">みんなでセットリストを予想しよう！</p>
                    </div>
                                            </a>
                    </div>

                                        <!-- 持ち物リスト -->
                                        <div class="col-md-4">
                                            <a href="live-board.php?category=items&live_id=<?= $liveId ?>" class="card h-100 text-decoration-none category-card items">
                <div class="card-body">
                                                    <h5 class="card-title text-success">
                                                        <i class="bi bi-bag-check me-2" style="color: #198754;"></i>持ち物リスト
                                                    </h5>
                                                    <p class="card-text text-muted small">ライブの持ち物をシェアしよう！</p>
                </div>
                                            </a>
            </div>

                                        <!-- 参戦コーデ -->
                                        <div class="col-md-4">
                                            <a href="live-board.php?category=fashion&live_id=<?= $liveId ?>" class="card h-100 text-decoration-none category-card fashion">
                <div class="card-body">
                                                    <h5 class="card-title text-danger">
                                                        <i class="bi bi-person-square me-2" style="color: #dc3545;"></i>参戦コーデ
                                                    </h5>
                                                    <p class="card-text text-muted small">ライブの服装を共有しよう！</p>
                    </div>
                                            </a>
                                </div>
                            </div>
                        </div>
                        </div>

                            <!-- レビュー・感想セクション -->
                            <div class="card mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">レビュー・感想</h5>
                                    <?php if (isset($_SESSION['user_id'])): ?>
                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#reviewForm">
                                            <i class="bi bi-plus-circle"></i> 投稿する
                                        </button>
                            <?php endif; ?>
                                        </div>
                                <div class="card-body">
                                    <?php if (isset($_SESSION['user_id'])): ?>
                                        <div class="collapse mb-4" id="reviewForm">
                                            <div class="card card-body bg-light">
                                                <form method="post">
                                                    <div class="mb-3">
                                                        <label class="form-label">タグ（複数選択可）</label>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php foreach ($availableTags as $tag): ?>
                                                                <div class="form-check form-check-inline">
                                                                    <input class="form-check-input" type="checkbox" name="review_tags[]" id="tag_<?= $tag ?>" value="<?= $tag ?>">
                                                                    <label class="form-check-label" for="tag_<?= $tag ?>"><?= $tag ?></label>
                                    </div>
                                                            <?php endforeach; ?>
                                </div>
                            </div>
                                                    <div class="mb-3">
                                                        <label for="review_content" class="form-label">感想</label>
                                                        <textarea class="form-control" id="review_content" name="review_content" rows="3" required></textarea>
                        </div>
                                                    <button type="submit" name="post_review" class="btn btn-primary">投稿する</button>
                                                </form>
        </div>
    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($reviews)): ?>
                                        <?php foreach ($reviews as $review): ?>
                                            <div class="review-card p-3 mb-3">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div>
                                                        <span class="fw-bold"><?= htmlspecialchars($review['username']) ?></span>
                                                        <?php if (!empty($review['tags'])): ?>
                                                            <div class="mt-1">
                                                                <?php foreach ($review['tags'] as $tag): ?>
                                                                    <span class="badge bg-info text-dark me-1"><?= htmlspecialchars($tag) ?></span>
                                                                <?php endforeach; ?>
            </div>
                            <?php endif; ?>
                        </div>
                                                    <small class="text-muted">
                                                        <?= date('Y/m/d', strtotime($review['created_at'])) ?>
                                                    </small>
                </div>
                                                <p class="mb-0"><?= nl2br(htmlspecialchars($review['content'])) ?></p>
                                                
                                                <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $review['created_by']): ?>
                                                    <div class="mt-2 text-end">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="delete_review_id" value="<?= $review['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('このレビューを削除しますか？')">
                                                                <i class="bi bi-trash"></i> 削除
                                                            </button>
            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted">まだレビューが投稿されていません。</p>
                                    <?php endif; ?>
                </div>
            </div>
                        <?php endif; ?>
        </div>
                    
                    <!-- 右サイドバー（関連情報） -->
                    <div class="sidebar-container" style="width: 300px;">
                        <!-- ブックマークボタン -->
                        <div class="card mb-4">
                            <div class="card-body">
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <form method="post" class="d-grid">
                                        <button type="submit" name="toggle_bookmark" class="btn <?= $isBookmarked ? 'btn-danger' : 'btn-outline-danger' ?>">
                                            <i class="bi <?= $isBookmarked ? 'bi-bookmark-fill' : 'bi-bookmark' ?>"></i>
                                            <?= $isBookmarked ? 'ブックマーク中' : 'ブックマークする' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="login.php" class="btn btn-outline-secondary d-block">
                                        <i class="bi bi-bookmark"></i> ログインしてブックマーク
                                    </a>
                        <?php endif; ?>
    </div>
</div>

                        <!-- アーティスト情報 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">アーティスト情報</h5>
            </div>
                            <div class="card-body">
                                <?php if (!$isFestival && $live['artist_id']): ?>
                                    <h6><?= htmlspecialchars($live['main_artist_name']) ?></h6>
                                    <div class="d-grid gap-2 mt-3">
                                        <a href="artist-detail.php?id=<?= $live['artist_id'] ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-person-fill"></i> アーティストページ
                                        </a>
                    </div>
                                <?php elseif ($isFestival): ?>
                                    <p class="text-muted mb-0">このイベントは複数のアーティストが出演するフェスティバルです。</p>
                                    <div class="d-grid gap-2 mt-3">
                                        <a href="#performances" class="btn btn-outline-primary">
                                            <i class="bi bi-list-ul"></i> 出演アーティスト一覧を見る
                                        </a>
                </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">アーティスト情報はありません。</p>
                                <?php endif; ?>
    </div>
</div>

                        <!-- 関連情報
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">関連情報</h5>
            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="live-board.php?live_id=<?= $live['id'] ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-chat-dots-fill"></i> ライブ掲示板
                                    </a>
                                    <?php if ($isFestival): ?>
                                        <a href="festival-schedule.php?id=<?= $live['id'] ?>" class="btn btn-outline-danger">
                                            <i class="bi bi-calendar-event"></i> タイムテーブル
                                        </a>
                        <?php endif; ?>
                                    <a href="create-setlist.php?live_id=<?= $live['id'] ?>" class="btn btn-outline-success">
                                        <i class="bi bi-music-note-list"></i> セットリスト投稿
                                    </a>
                    </div>
                    </div>
                </div> -->
                        
                        <!-- チケット情報（ワンマンライブの場合） -->
                        <?php if (!$isFestival && isset($live['ticket_price'])): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">チケット情報</h5>
                    </div>
                                <div class="card-body">
                                    <p class="mb-2">
                                        <i class="bi bi-ticket-perforated-fill text-success"></i>
                                        <strong>料金:</strong> <?= number_format($live['ticket_price']) ?>円
                                    </p>
                                    
                                    <?php if (isset($live['ticket_url']) && $live['ticket_url']): ?>
                                        <div class="d-grid">
                                            <a href="<?= htmlspecialchars($live['ticket_url']) ?>" class="btn btn-outline-success" target="_blank">
                                                <i class="bi bi-cart"></i> チケットを購入
                                            </a>
                    </div>
                                    <?php endif; ?>
                    </div>
                    </div>
                        <?php endif; ?>
                        
                        <!-- 会場情報 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">会場情報</h5>
                    </div>
                            <div class="card-body">
                                <h6><?= htmlspecialchars($live['venue_name']) ?></h6>
                                <?php if (isset($live['venue_address']) && $live['venue_address']): ?>
                                    <p class="mb-2">
                                        <i class="bi bi-geo-alt text-danger"></i>
                                        <?= htmlspecialchars($live['venue_address']) ?>
                                    </p>
                                <?php endif; ?>
                                <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($live['venue_name']) ?>" 
                                   class="btn btn-sm btn-outline-secondary" target="_blank">
                                    <i class="bi bi-map"></i> 地図で見る
                                </a>
    </div>
</div>

                        <!-- 共有ボタン -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">共有する</h5>
            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-around">
                                    <a href="https://twitter.com/intent/tweet?text=<?= urlencode($live['title']) ?>&url=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                                       class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-twitter"></i>
                                    </a>
                                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                                       class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-facebook"></i>
                                    </a>
                                    <a href="https://line.me/R/msg/text/?<?= urlencode($live['title'] . ' https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                                       class="btn btn-outline-success" target="_blank">
                                        <i class="bi bi-line"></i>
                                    </a>
                                    <button class="btn btn-outline-secondary" onclick="copyToClipboard()">
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                    </div>
                    </div>
                </div>
                </div>
                </div>
        </div>
    </div>
</div>

    <?php include 'footer.php'; ?>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // URLをクリップボードにコピーする関数
    function copyToClipboard() {
        const url = window.location.href;
        navigator.clipboard.writeText(url).then(() => {
            alert('URLをコピーしました');
        }).catch(err => {
            console.error('コピーに失敗しました:', err);
        });
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // アーティスト追加フォームの送信をAjax化
        const addArtistForm = document.getElementById('add-artist-form');
        if (addArtistForm) {
            addArtistForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
                const formData = new FormData(this);
                formData.append('add_artist', '1');
                
                fetch('<?= $_SERVER['REQUEST_URI'] ?>', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 新しいアーティストカードを追加
                        addPerformanceCard(data.performance);
                        
                        // フォームをリセット
                        addArtistForm.reset();
                        
                        // フォームを閉じる
                        const bsCollapse = new bootstrap.Collapse(document.getElementById('addArtistForm'));
                        bsCollapse.hide();
                    } else {
                        alert(data.error || 'エラーが発生しました');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('通信エラーが発生しました');
        });
    });
        }
        
        // 削除ボタンのイベント処理
        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-artist-btn')) {
                e.preventDefault();
                
                if (!confirm('このアーティストを削除してもよろしいですか？')) {
                return;
            }

                const form = e.target.closest('form');
                const performanceId = form.querySelector('input[name="performance_id"]').value;
                const formData = new FormData(form);
                formData.append('remove_artist', '1');
                
                fetch('<?= $_SERVER['REQUEST_URI'] ?>', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                if (data.success) {
                        // 削除されたカードを非表示
                        const card = document.querySelector(`.performance-item[data-id="${performanceId}"]`);
                        if (card) {
                            card.remove();
                        }
                    } else {
                        alert(data.error || 'エラーが発生しました');
                    }
                })
                .catch(error => {
                console.error('Error:', error);
                    alert('通信エラーが発生しました');
        });
    }
});
        
        // 新しいパフォーマンスカードを追加する関数
        function addPerformanceCard(performance) {
            const container = document.getElementById('performances-container');
            const col = document.createElement('div');
            col.className = 'col performance-item';
            col.dataset.id = performance.id;
            
            const startTimeHtml = performance.start_time 
                ? `<p class="card-text text-muted"><i class="bi bi-clock"></i> ${performance.start_time.substr(0, 5)}</p>`