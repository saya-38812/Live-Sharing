<?php
session_start();
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
        error_log($e->getMessage());
        $this->error = 'データの取得に失敗しました。';
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
            flex-wrap: wrap;
        }
        
        .main-content {
            flex: 1;
            min-width: 0; /* flexboxでの縮小を許可 */
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
                order: 2;
                margin-top: 2rem;
            }
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
                                
                                <!-- セットリスト情報があれば表示 -->
                                <?php if (isset($performance['setlist']) && $performance['setlist']): ?>
                                    <div class="mb-4">
                                        <h5>セットリスト</h5>
                                        <pre class="card-text bg-light p-3 rounded"><?= htmlspecialchars($performance['setlist']) ?></pre>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light">
                                        <p class="mb-0">セットリスト情報はまだありません。</p>
                                        <?php if (isset($_SESSION['user_id'])): ?>
                                            <a href="create-setlist.php?performance_id=<?= $performance['id'] ?>" class="btn btn-sm btn-outline-success mt-2">
                                                <i class="bi bi-music-note-list"></i> セットリストを投稿する
                                            </a>
                                        <?php endif; ?>
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
                    
                    <!-- 右サイドバー（関連情報） -->
                    <div class="sidebar-container" style="width: 300px;">
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