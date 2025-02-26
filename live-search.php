<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class SearchManager {
    private $pdo;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->error = null;
        $this->data = [
            'searchResults' => [],
            'recentLives' => []
        ];
    }
    
    public function search(string $keyword = '', array $filters = []): void {
        try {
            if (!empty($keyword)) {
                $this->data['searchResults'] = $this->getSearchResults($keyword, $filters);
            }
            $this->data['recentLives'] = $this->getRecentLives();
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getSearchResults(string $keyword, array $filters): array {
        $params = [];
        $conditions = [];
        
        // キーワード検索条件（小文字、大文字を区別しない、部分一致）
        if (!empty($keyword)) {
            // キーワードを小文字に変換し、空白で分割
            $keywords = explode(' ', mb_strtolower(trim($keyword)));
            $keywordConditions = [];
            
            foreach ($keywords as $word) {
                if (empty($word)) continue;
                
                $searchWord = '%' . $word . '%';
                $keywordConditions[] = "(
                    LOWER(l.title) LIKE LOWER(?) OR 
                    LOWER(a.name) LIKE LOWER(?) OR 
                    LOWER(v.name) LIKE LOWER(?)
                )";
                $params = array_merge($params, [$searchWord, $searchWord, $searchWord]);
            }
            
            if (!empty($keywordConditions)) {
                // すべてのキーワードにマッチ（AND検索）
                $conditions[] = '(' . implode(' AND ', $keywordConditions) . ')';
            }
        }
        
        // 開催時期フィルター
        if (!empty($filters['period'])) {
            switch ($filters['period']) {
                case 'past_month':
                    $conditions[] = "l.date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
                    break;
                case 'past_3months':
                    $conditions[] = "l.date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
                    break;
                case 'past_year':
                    $conditions[] = "l.date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
                    break;
            }
        }
        
        // 会場エリアフィルター
        if (!empty($filters['area'])) {
            $conditions[] = "LOWER(v.area) = LOWER(?)";
            $params[] = $filters['area'];
        }
        
        // イベントタイプフィルター
        if (!empty($filters['event_type'])) {
            $conditions[] = "LOWER(l.event_type) = LOWER(?)";
            $params[] = $filters['event_type'];
        }
        
        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $sql = "
            SELECT l.*, 
                   a.name as artist_name,
                   v.name as venue_name,
                   v.capacity
            FROM lives l
            JOIN artists a ON l.artist_id = a.id
            JOIN venues v ON l.venue_id = v.id
            {$whereClause}
            ORDER BY l.date ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getRecentLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, 
                   a.name as artist_name,
                   v.name as venue_name
            FROM lives l
            JOIN artists a ON l.artist_id = a.id
            JOIN venues v ON l.venue_id = v.id
            WHERE l.date >= CURDATE()
            ORDER BY l.date ASC
            LIMIT 5
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
}

// 検索パラメータの取得
$keyword = $_GET['keyword'] ?? '';
$filters = [
    'period' => $_GET['period'] ?? '',
    'area' => $_GET['area'] ?? '',
    'event_type' => $_GET['event_type'] ?? ''
];

// 検索マネージャーの初期化と検索実行
$searchManager = new SearchManager($pdo);
$searchManager->search($keyword, $filters);

// エラーチェック
if ($error = $searchManager->getError()) {
    exit($error);
}

// データの取得
extract($searchManager->getData());

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>検索 - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        /* 共通スタイル */
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

        /* 検索ページ専用スタイル */
        .search-header {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .search-filters {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #dee2e6;
        }
        .search-result-card {
            border-left: 3px solid var(--theme-color);
            transition: transform 0.2s;
        }
        .search-result-card:hover {
            transform: translateX(5px);
            background-color: var(--theme-bg-light);
        }
        .result-meta {
            font-size: 0.8rem;
            color: #6c757d;
        }

        /* フィルターボタン */
        .filter-btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: 1px solid var(--theme-color);
            background: white;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-btn:hover {
            background-color: var(--theme-bg-light);
            color: var(--theme-color);
        }
        .filter-btn.active {
            background-color: var(--theme-color);
            color: white;
            border-color: var(--theme-color);
        }

        /* 検索フィルターのセレクトボックス */
        .search-filter-select:focus {
            border-color: var(--theme-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--theme-color-rgb), 0.25);
        }

        .search-filter-select option:checked {
            background-color: var(--theme-color);
            color: white;
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
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <!-- 検索ヘッダー -->
            <div class="search-header rounded">
                <form action="" method="GET" class="mb-3">
                    <div class="input-group input-group-lg">
                        <input type="text" name="keyword" class="form-control" 
                               placeholder="アーティスト名、ライブ名、会場名で検索"
                               value="<?= htmlspecialchars($keyword) ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-2"></i>検索
                        </button>
                    </div>
                </form>
            </div>

            <!-- 検索フィルター -->
            <div class="search-filters mb-4">
                <form action="" method="GET">
                    <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword) ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <select name="period" class="form-select search-filter-select">
                                <option value="">開催時期</option>
                                <option value="past_month" <?= $filters['period'] === 'past_month' ? 'selected' : '' ?>>過去1ヶ月</option>
                                <option value="past_3months" <?= $filters['period'] === 'past_3months' ? 'selected' : '' ?>>過去3ヶ月</option>
                                <option value="past_year" <?= $filters['period'] === 'past_year' ? 'selected' : '' ?>>過去1年</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="area" class="form-select search-filter-select">
                                <option value="">会場エリア</option>
                                <option value="関東" <?= $filters['area'] === '関東' ? 'selected' : '' ?>>関東</option>
                                <option value="関西" <?= $filters['area'] === '関西' ? 'selected' : '' ?>>関西</option>
                                <option value="その他" <?= $filters['area'] === 'その他' ? 'selected' : '' ?>>その他</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="event_type" class="form-select search-filter-select">
                                <option value="">イベントタイプ</option>
                                <option value="フェス" <?= $filters['event_type'] === 'フェス' ? 'selected' : '' ?>>フェス</option>
                                <option value="ワンマン" <?= $filters['event_type'] === 'ワンマン' ? 'selected' : '' ?>>ワンマン</option>
                                <option value="その他" <?= $filters['event_type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <!-- 検索結果 -->
            <h4 class="mb-3 theme-color">検索結果</h4>
            <?php if (!empty($searchResults)): ?>
                <?php foreach ($searchResults as $result): ?>
                    <a href="live-detail.php?id=<?= (int)$result['id'] ?>" class="text-decoration-none">
                        <div class="card search-result-card mb-3">
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($result['title']) ?></h5>
                                <div class="result-meta mb-2">
                                    <i class="bi bi-calendar3 me-2"></i><?= date('Y/m/d', strtotime($result['date'])) ?>
                                    <i class="bi bi-geo-alt ms-3 me-2"></i><?= htmlspecialchars($result['venue_name']) ?>
                                    <?php if ($result['capacity']): ?>
                                        <i class="bi bi-people-fill ms-3 me-2"></i><?= number_format($result['capacity']) ?>人
                                    <?php endif; ?>
                                </div>
                                <div class="mb-2">
                                    <span class="badge bg-primary"><?= htmlspecialchars($result['event_type'] ?? '') ?></span>
                                    <span class="badge bg-success"><?= htmlspecialchars($result['status']) ?></span>
                                </div>
                                <span class="btn btn-outline-primary btn-sm">
                                    詳細を見る
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    検索結果が見つかりませんでした。
                </div>
            <?php endif; ?>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 近日開催のライブ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">近日開催のライブ</h5>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentLives as $live): ?>
                            <a href="live-detail.php?id=<?= (int)$live['id'] ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($live['title']) ?></h6>
                                    <small><?= date('Y/m/d', strtotime($live['date'])) ?></small>
                                </div>
                                <small class="text-muted">
                                    <?= htmlspecialchars($live['artist_name']) ?> @ <?= htmlspecialchars($live['venue_name']) ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 検索のヒント -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">検索のヒント</h5>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                            アーティスト名で検索
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                            ライブ名で検索
                        </li>
                        <li>
                            <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                            会場名で検索
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 