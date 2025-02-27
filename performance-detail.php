<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';
require_once 'includes/functions.php';

class PerformanceDetailManager {
    private $pdo;
    private $userId;
    private $performanceId;
    private $error;
    private $data;

    public function __construct(PDO $pdo, ?int $userId, int $performanceId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->performanceId = $performanceId;
        $this->error = null;
        $this->data = [];
    }

    public function loadPerformanceData(): void {
        try {
            // パフォーマンスの詳細情報を取得
            $stmt = $this->pdo->prepare("
                SELECT p.*,
                       a.name as artist_name,
                       l.title as event_title,
                       l.date as event_date,
                       v.name as venue_name,
                       s.name as stage_name
                FROM performances p
                JOIN artists a ON p.artist_id = a.id
                JOIN lives l ON p.event_id = l.id
                JOIN venues v ON l.venue_id = v.id
                LEFT JOIN stages s ON p.stage_id = s.id
                WHERE p.id = ?
            ");
            $stmt->execute([$this->performanceId]);
            $this->data['performance'] = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$this->data['performance']) {
                throw new Exception('パフォーマンスが見つかりませんでした');
            }

            // アーティストの他の出演情報を取得
            $stmt = $this->pdo->prepare("
                SELECT p.id, p.event_id, p.start_time,
                       l.title as event_title, l.date as event_date
                FROM performances p
                JOIN lives l ON p.event_id = l.id
                WHERE p.artist_id = ? AND p.id != ?
                ORDER BY l.date DESC
                LIMIT 5
            ");
            $stmt->execute([
                $this->data['performance']['artist_id'],
                $this->performanceId
            ]);
            $this->data['other_performances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 実際のセットリストを取得
            $stmt = $this->pdo->prepare("
                SELECT s.*, u.username
                FROM setlists s
                LEFT JOIN users u ON s.created_by = u.id
                WHERE s.performance_id = ? AND s.is_actual = 1
                ORDER BY s.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$this->performanceId]);
            $this->data['actual_setlist'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // レビューを取得
            $stmt = $this->pdo->prepare("
                SELECT r.*, u.username,
                       GROUP_CONCAT(rt.tag) as tags
                FROM reviews r
                LEFT JOIN users u ON r.created_by = u.id
                LEFT JOIN review_tags rt ON r.id = rt.review_id
                WHERE r.performance_id = ?
                GROUP BY r.id
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$this->performanceId]);
            $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // タグを配列に変換
            foreach ($reviews as &$review) {
                $review['tags'] = $review['tags'] ? explode(',', $review['tags']) : [];
            }
            $this->data['reviews'] = $reviews;

            // 利用可能なタグのリスト
            $this->data['available_tags'] = ['MC', '演出', 'パフォーマンス', '衣装', 'セトリ', '音響', '照明', '会場', 'ファンサ', 'その他'];

        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    public function getData(): array {
        return $this->data;
    }

    public function getError(): ?string {
        return $this->error;
    }

    private function handleError(Exception $e): void {
        error_log('=== Performance Detail Error ===');
        error_log('Error Message: ' . $e->getMessage());
        error_log('Error File: ' . $e->getFile());
        error_log('Error Line: ' . $e->getLine());
        error_log('Error Trace: ' . $e->getTraceAsString());
        
        if ($e instanceof PDOException) {
            error_log('SQL State: ' . $e->errorInfo[0]);
            error_log('Error Code: ' . $e->errorInfo[1]);
            error_log('Error Message: ' . $e->errorInfo[2]);
        }

        $this->error = 'データの取得に失敗しました。詳細: ' . $e->getMessage();
    }

    // セットリストの保存
    public function saveActualSetlist(string $description): bool {
        if (!$this->userId) return false;

        try {
            $this->pdo->beginTransaction();

            // 既存のセットリストを確認
            $stmt = $this->pdo->prepare("
                SELECT id FROM setlists 
                WHERE performance_id = ? AND is_actual = 1
            ");
            $stmt->execute([$this->performanceId]);
            $existingSetlist = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingSetlist) {
                // 更新
                $stmt = $this->pdo->prepare("
                    UPDATE setlists 
                    SET description = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$description, $existingSetlist['id']]);
            } else {
                // 新規作成
                $stmt = $this->pdo->prepare("
                    INSERT INTO setlists (
                        performance_id, description, is_actual, 
                        created_by, created_at, updated_at
                    ) VALUES (?, ?, 1, ?, NOW(), NOW())
                ");
                $stmt->execute([$this->performanceId, $description, $this->userId]);
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }

    // レビューの投稿
    public function postReview(string $content, array $tags = []): bool {
        if (!$this->userId) return false;

        try {
            $this->pdo->beginTransaction();

            // レビューを追加
            $stmt = $this->pdo->prepare("
                INSERT INTO reviews (
                    performance_id, content, created_by, created_at
                ) VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$this->performanceId, $content, $this->userId]);
            $reviewId = $this->pdo->lastInsertId();

            // タグを追加
            if (!empty($tags)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO review_tags (review_id, tag) 
                    VALUES (?, ?)
                ");
                foreach ($tags as $tag) {
                    $stmt->execute([$reviewId, $tag]);
                }
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }

    // レビューの削除
    public function deleteReview(int $reviewId): bool {
        if (!$this->userId) return false;

        try {
            $this->pdo->beginTransaction();

            // レビューの所有者を確認
            $stmt = $this->pdo->prepare("
                SELECT created_by FROM reviews 
                WHERE id = ? AND performance_id = ?
            ");
            $stmt->execute([$reviewId, $this->performanceId]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$review || $review['created_by'] != $this->userId) {
                throw new Exception('削除権限がありません');
            }

            // タグを削除
            $stmt = $this->pdo->prepare("DELETE FROM review_tags WHERE review_id = ?");
            $stmt->execute([$reviewId]);

            // レビューを削除
            $stmt = $this->pdo->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->execute([$reviewId]);

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }
}

// ページの初期化
$performanceId = $_GET['id'] ?? null;
if (!$performanceId) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$manager = new PerformanceDetailManager($pdo, $userId, (int)$performanceId);
$manager->loadPerformanceData();

if ($error = $manager->getError()) {
    exit($error);
}

$data = $manager->getData();
$performance = $data['performance'];
$actualSetlist = $data['actual_setlist'] ?? null;
$reviews = $data['reviews'] ?? [];
$availableTags = $data['available_tags'] ?? [];
$otherPerformances = $data['other_performances'] ?? [];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($performance['artist_name']) ?> @ <?= htmlspecialchars($performance['event_title']) ?> - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        /* コンテンツエリアの調整 */
        .content-wrapper {
            display: flex;
            gap: 2rem; /* メインコンテンツとサイドバーの間隔 */
        }
        
        .main-content {
            flex: 1;
            min-width: 0; /* flexboxでの縮小を許可 */
        }
        
        .sidebar-container {
            width: 300px;
            flex-shrink: 0; /* サイドバーの幅を固定 */
        }
        
        .performance-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .time-badge {
            font-size: 1.2rem;
            padding: 8px 15px;
            border-radius: 20px;
        }
        
        /* モバイル表示の調整 */
        @media (max-width: 767.98px) {
            .content-wrapper {
                flex-direction: column;
            }
            
            .sidebar-container {
                width: 100%;
                margin-top: 2rem;
            }
        }

        /* カテゴリーカードのスタイル */
        .category-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: none;
        }

        .category-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        /* 各カードの背景色 */
        .category-card.setlist {
            background-color: #e7f1ff;  /* 薄い青 */
        }

        .category-card.items {
            background-color: #e8f5e9;  /* 薄い緑 */
        }

        .category-card.fashion {
            background-color: #ffebee;  /* 薄い赤 */
        }

        /* カードのアイコンとタイトルのスタイル */
        .category-card .card-title {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .category-card .card-text {
            font-size: 0.9rem;
        }

        /* カード間のスペース */
        .row.g-3 {
            margin: 0;
        }

        /* レビューカードのスタイル */
        .review-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        /* セットリストのスタイル */
        .song-item {
            padding: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .song-item:last-child {
            border-bottom: none;
        }

        .song-number {
            color: #6c757d;
            font-weight: bold;
            margin-right: 1rem;
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
                    <div class="main-content">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h1 class="card-title h3"><?= htmlspecialchars($performance['artist_name']) ?></h1>
                                <h6 class="card-subtitle mb-3 text-muted">
                                    @ <?= htmlspecialchars($performance['event_title']) ?>
                                </h6>
                                
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <span class="badge bg-danger">フェス出演</span>
                                        <span class="text-muted ms-2">
                                            <?= date('Y年m月d日', strtotime($performance['event_date'])) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="bi bi-geo-alt-fill text-danger"></i>
                                            <strong>会場:</strong> <?= htmlspecialchars($performance['venue_name']) ?>
                                        </p>
                                        <?php if ($performance['stage_name']): ?>
                                            <p class="mb-1">
                                                <i class="bi bi-music-player-fill text-success"></i>
                                                <strong>ステージ:</strong> <?= htmlspecialchars($performance['stage_name']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if ($performance['start_time']): ?>
                                            <p class="mb-1">
                                                <i class="bi bi-clock-fill text-primary"></i>
                                                <strong>出演時間:</strong> <?= substr($performance['start_time'], 0, 5) ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($performance['end_time']): ?>
                                            <p class="mb-1">
                                                <i class="bi bi-clock-history text-info"></i>
                                                <strong>終了予定:</strong> <?= substr($performance['end_time'], 0, 5) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if ($performance['start_time']): ?>
                                    <div class="performance-info mb-4">
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-primary time-badge me-3">
                                                <?= substr($performance['start_time'], 0, 5) ?>
                                            </span>
                                            <div>
                                                <h5 class="mb-0">出演時間</h5>
                                                <?php if ($performance['end_time']): ?>
                                                    <small class="text-muted">
                                                        終了予定: <?= substr($performance['end_time'], 0, 5) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                                
                                <!-- セットリスト情報 -->
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
                                                <a href="live-board.php?category=setlist&performance_id=<?= $performance['id'] ?>" 
                                                   class="text-decoration-none">
                                                    <div class="card category-card setlist">
                                                        <div class="card-body text-center">
                                                            <i class="bi bi-music-note-list display-6 mb-2" style="color: #0d6efd;"></i>
                                                            <h5 class="card-title" style="color: #0d6efd;">セットリスト予想</h5>
                                                            <p class="card-text text-muted">みんなでセットリストを予想しよう！</p>
                                                        </div>
                                                    </div>
                                                </a>
                                            </div>

                                            <!-- 持ち物リスト -->
                                            <div class="col-md-4">
                                                <a href="live-board.php?category=items&performance_id=<?= $performance['id'] ?>" 
                                                   class="text-decoration-none">
                                                    <div class="card category-card items">
                                                        <div class="card-body text-center">
                                                            <i class="bi bi-bag-check display-6 mb-2" style="color: #198754;"></i>
                                                            <h5 class="card-title" style="color: #198754;">持ち物リスト</h5>
                                                            <p class="card-text text-muted">ライブの持ち物をシェアしよう！</p>
                                                        </div>
                                                    </div>
                                                </a>
                                            </div>

                                            <!-- 参戦コーデ -->
                                            <div class="col-md-4">
                                                <a href="live-board.php?category=fashion&performance_id=<?= $performance['id'] ?>" 
                                                   class="text-decoration-none">
                                                    <div class="card category-card fashion">
                                                        <div class="card-body text-center">
                                                            <i class="bi bi-person-square display-6 mb-2" style="color: #dc3545;"></i>
                                                            <h5 class="card-title" style="color: #dc3545;">参戦コーデ</h5>
                                                            <p class="card-text text-muted">ライブの服装を共有しよう！</p>
                                                        </div>
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
                                                                <?php 
                                                                $availableTags = ['MC', '演出', 'パフォーマンス', '衣装', 'セトリ', '音響', '照明', '会場', 'ファンサ', 'その他'];
                                                                foreach ($availableTags as $tag): 
                                                                ?>
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
                                    </div>
                                </div>

                        <!-- 他の出演情報 -->
                        <?php if (!empty($otherPerformances)): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="card-title mb-0"><?= htmlspecialchars($performance['artist_name']) ?>の他の出演</h5>
                                </div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($otherPerformances as $otherPerf): ?>
                                            <li class="list-group-item">
                                                <a href="performance-detail.php?id=<?= $otherPerf['id'] ?>" class="text-decoration-none">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <?= htmlspecialchars($otherPerf['event_title']) ?>
                                                            <small class="d-block text-muted">
                                                                <?= date('Y年m月d日', strtotime($otherPerf['event_date'])) ?>
                                                            </small>
                                                        </div>
                                                        <?php if ($otherPerf['start_time']): ?>
                                                            <span class="badge bg-primary rounded-pill">
                                                                <?= substr($otherPerf['start_time'], 0, 5) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 右サイドバー -->
                    <div class="sidebar-container">
                        <!-- アーティスト情報 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">アーティスト情報</h5>
                            </div>
                            <div class="card-body">
                                <h6><?= htmlspecialchars($performance['artist_name']) ?></h6>
                                <div class="d-grid gap-2 mt-3">
                                    <a href="artist-detail.php?id=<?= $performance['artist_id'] ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-person-fill"></i> アーティストページ
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- フェス情報 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">フェス情報</h5>
                            </div>
                            <div class="card-body">
                                <h6><?= htmlspecialchars($performance['event_title']) ?></h6>
                                <p class="text-muted">
                                    <?= date('Y年m月d日', strtotime($performance['event_date'])) ?>
                                </p>
                                <div class="d-grid gap-2">
                                    <a href="live-detail.php?id=<?= $performance['event_id'] ?>" class="btn btn-outline-danger">
                                        <i class="bi bi-arrow-left"></i> フェス詳細に戻る
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 関連情報 -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">関連情報</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="artist-detail.php?id=<?= $performance['artist_id'] ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-person-fill"></i> アーティスト情報
                                    </a>
                                    <a href="create-setlist.php?performance_id=<?= $performance['id'] ?>" class="btn btn-outline-success">
                                        <i class="bi bi-music-note-list"></i> セットリスト投稿
                                    </a>
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
    </script>
</body>
</html> 