<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// ログインユーザーIDの取得
$userId = $_SESSION['user_id'] ?? null;

// 検索パラメータの取得
$searchQuery = $_GET['q'] ?? '';
$sortBy = $_GET['sort'] ?? 'name_asc';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$genreFilter = $_GET['genre'] ?? '';

// アーティスト一覧を直接取得
try {
    // 総アーティスト数を取得
    $countSql = "SELECT COUNT(*) FROM artists";
    $countParams = [];
    
    // 検索条件がある場合のみWHERE句を追加
    if ($searchQuery) {
        $countSql .= " WHERE name LIKE ?";
        $countParams[] = '%' . $searchQuery . '%';
        
        if ($genreFilter) {
            $countSql .= " AND genre = ?";
            $countParams[] = $genreFilter;
        }
    } elseif ($genreFilter) {
        $countSql .= " WHERE genre = ?";
        $countParams[] = $genreFilter;
    }
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalCount = $countStmt->fetchColumn();
    
    // ページネーション情報
    $totalPages = ceil($totalCount / $perPage);
    $currentPage = $page;
    $offset = ($page - 1) * $perPage;
    
    // アーティスト一覧を取得
    $sql = "SELECT * FROM artists";
    $params = [];
    
    // 検索条件がある場合のみWHERE句を追加
    if ($searchQuery) {
        $sql .= " WHERE name LIKE ?";
        $params[] = '%' . $searchQuery . '%';
        
        if ($genreFilter) {
            $sql .= " AND genre = ?";
            $params[] = $genreFilter;
        }
    } elseif ($genreFilter) {
        $sql .= " WHERE genre = ?";
        $params[] = $genreFilter;
    }
    
    // ソート順の設定
    switch ($sortBy) {
        case 'name_desc':
            $sql .= " ORDER BY name DESC";
            break;
        case 'newest':
            $sql .= " ORDER BY created_at DESC";
            break;
        case 'name_asc':
        default:
            $sql .= " ORDER BY name ASC";
            break;
    }
    
    // LIMIT句とOFFSET句を追加（整数値として直接埋め込む）
    $sql .= " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $artists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 人気のアーティスト（サイドバー用）
    $popularSql = "SELECT * FROM artists ORDER BY id DESC LIMIT 5";
    $popularStmt = $pdo->query($popularSql);
    $popularArtists = $popularStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ジャンル一覧を取得
    $genreStmt = $pdo->query("SELECT DISTINCT genre FROM artists WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");
    $genres = $genreStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $error = null;
} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Error in artists.php: " . $error);
    $artists = [];
    $popularArtists = [];
    $genres = [];
    $totalCount = 0;
    $totalPages = 1;
}

// ヘッダーを含める
$pageTitle = 'アーティスト一覧';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>
        
        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <h1 class="mb-4">アーティスト一覧</h1>
            
            <!-- 検索フォーム -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="artists.php" method="get" class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" class="form-control" name="q" placeholder="アーティスト名で検索" value="<?= htmlspecialchars($searchQuery) ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-search"></i> 検索
                                </button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <label class="input-group-text" for="genreFilter">ジャンル</label>
                                <select class="form-select" id="genreFilter" name="genre" onchange="this.form.submit()">
                                    <option value="">すべて</option>
                                    <?php foreach ($genres as $genre): ?>
                                        <option value="<?= htmlspecialchars($genre) ?>" <?= $genreFilter === $genre ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($genre) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <label class="input-group-text" for="sortBy">並び順</label>
                                <select class="form-select" id="sortBy" name="sort" onchange="this.form.submit()">
                                    <option value="name_asc" <?= $sortBy === 'name_asc' ? 'selected' : '' ?>>名前（昇順）</option>
                                    <option value="name_desc" <?= $sortBy === 'name_desc' ? 'selected' : '' ?>>名前（降順）</option>
                                    <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>新着順</option>
                                </select>
                            </div>
                        </div>
                        <?php if ($searchQuery || $genreFilter): ?>
                            <div class="col-12">
                                <a href="artists.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-x-circle"></i> 検索条件をクリア
                                </a>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- 検索結果 -->
            <div class="mb-3">
                <p class="text-muted">
                    <?= number_format($totalCount) ?>件のアーティストが見つかりました
                    <?php if ($searchQuery): ?>
                        （検索キーワード: "<?= htmlspecialchars($searchQuery) ?>"）
                    <?php endif; ?>
                    <?php if ($genreFilter): ?>
                        （ジャンル: "<?= htmlspecialchars($genreFilter) ?>"）
                    <?php endif; ?>
                </p>
            </div>
            
            <!-- アーティスト一覧 -->
            <?php if (!empty($artists)): ?>
                <div class="row row-cols-1 row-cols-md-2 g-4">
                    <?php foreach ($artists as $artist): ?>
                        <div class="col">
                            <a href="artist-detail.php?id=<?= $artist['id'] ?>" class="text-decoration-none">
                                <div class="card h-100 hover-shadow">
                                    <div class="row g-0">
                                        <div class="col-4 d-flex align-items-center justify-content-center p-2">
                                            <?php
                                            $artistImage = 'uploads/artists/' . $artist['id'] . '.jpg';
                                            if (file_exists($artistImage)): 
                                            ?>
                                                <img src="<?= $artistImage ?>" alt="<?= htmlspecialchars($artist['name']) ?>" class="img-fluid rounded" style="max-height: 120px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="text-center">
                                                    <i class="bi bi-person-circle text-secondary" style="font-size: 3rem;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-8">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <h5 class="card-title mb-1 text-primary">
                                                        <?= htmlspecialchars($artist['name']) ?>
                                                    </h5>
                                                </div>
                                                
                                                <?php if (!empty($artist['genre']) || !empty($artist['debut_year'])): ?>
                                                    <div class="mt-2">
                                                        <?php if (!empty($artist['genre'])): ?>
                                                            <span class="badge bg-secondary me-1"><?= htmlspecialchars($artist['genre']) ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($artist['debut_year']) && $artist['debut_year'] != '0000'): ?>
                                                            <span class="badge bg-info"><?= htmlspecialchars($artist['debut_year']) ?>年デビュー</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($artist['description'])): ?>
                                                    <p class="card-text small mt-2 text-muted">
                                                        <?= nl2br(htmlspecialchars(mb_substr($artist['description'], 0, 100))) ?>
                                                        <?php if (mb_strlen($artist['description']) > 100): ?>
                                                            <span class="text-primary">...続きを読む</span>
                                                        <?php endif; ?>
                                                    </p>
                                                <?php else: ?>
                                                    <p class="card-text small mt-2 text-muted">
                                                        このアーティストの詳細情報はまだありません。
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    アーティストが見つかりませんでした。検索条件を変更してお試しください。
                </div>
            <?php endif; ?>
            
            <!-- ページネーション -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $currentPage - 1 ?>&q=<?= urlencode($searchQuery) ?>&sort=<?= $sortBy ?>&genre=<?= urlencode($genreFilter) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        if ($endPage - $startPage < 4) {
                            $startPage = max(1, $endPage - 4);
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($searchQuery) ?>&sort=<?= $sortBy ?>&genre=<?= urlencode($genreFilter) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $currentPage + 1 ?>&q=<?= urlencode($searchQuery) ?>&sort=<?= $sortBy ?>&genre=<?= urlencode($genreFilter) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
        
        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 人気のアーティスト -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">人気のアーティスト</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($popularArtists)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($popularArtists as $artist): ?>
                                <a href="artist-detail.php?id=<?= $artist['id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex align-items-center">
                                        <?php
                                        $artistImage = 'uploads/artists/' . $artist['id'] . '.jpg';
                                        if (file_exists($artistImage)): 
                                        ?>
                                            <img src="<?= $artistImage ?>" alt="<?= htmlspecialchars($artist['name']) ?>" class="rounded-circle me-3" style="width: 40px; height: 40px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                <i class="bi bi-person-fill text-secondary"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <h6 class="mb-0"><?= htmlspecialchars($artist['name']) ?></h6>
                                            <?php if (!empty($artist['genre'])): ?>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($artist['genre']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-3">
                            <p class="text-muted mb-0">人気のアーティストはまだありません。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ジャンル一覧 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">ジャンルから探す</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($genres)): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="artists.php" class="btn btn-sm <?= empty($genreFilter) ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                すべて
                            </a>
                            <?php foreach ($genres as $genre): ?>
                                <a href="artists.php?genre=<?= urlencode($genre) ?>" class="btn btn-sm <?= $genreFilter === $genre ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                    <?= htmlspecialchars($genre) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">ジャンル情報はまだありません。</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 最近追加されたアーティスト -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">最近追加されたアーティスト</h5>
                </div>
                <div class="card-body">
                    <a href="artists.php?sort=newest" class="btn btn-outline-primary w-100">
                        <i class="bi bi-arrow-right-circle"></i> 新着順で表示
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 