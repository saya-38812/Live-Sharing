<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';



// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// セットリストIDの取得
$setlist_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$setlist_id) {
    header('Location: index.php');
    exit;
}

try {
    // セットリストの詳細を取得
    $stmt = $pdo->prepare('
        SELECT sp.*, u.username, l.title as live_title, l.date as live_date
        FROM setlist_predictions sp
        LEFT JOIN users u ON sp.user_id = u.id
        LEFT JOIN lives l ON sp.live_id = l.id
        WHERE sp.id = ?
    ');
    $stmt->execute([$setlist_id]);
    $setlist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$setlist) {
        header('Location: index.php');
        exit;
    }
    
    // 曲目をJSONから配列に変換
    $songs = json_decode($setlist['songs'], true) ?? [];
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error = 'データベースエラーが発生しました。';
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($setlist['title']); ?> - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="section-title mb-0 theme-color"><?php echo htmlspecialchars($setlist['title']); ?></h4>
                <div class="d-flex gap-2">
                    <a href="edit-setlist.php?id=<?php echo $setlist_id; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-pencil me-2"></i>編集
                    </a>
                    <a href="add-song.php?setlist_id=<?php echo $setlist_id; ?>" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-2"></i>曲を追加
                    </a>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <div class="mb-4">
                        <p class="text-muted mb-2">
                            <i class="bi bi-calendar3 me-2"></i>
                            作成日時: <?php echo date('Y年m月d日 H:i', strtotime($setlist['created_at'])); ?>
                        </p>
                        <?php if ($setlist['description']): ?>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($setlist['description'])); ?></p>
                        <?php endif; ?>
                    </div>

                    <h5 class="card-title mb-3">
                        <i class="bi bi-music-note-list me-2"></i>曲目一覧
                    </h5>
                    
                    <?php if (empty($songs)): ?>
                        <p class="text-muted">まだ曲が登録されていません。</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($songs as $index => $song): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="me-3 text-muted"><?php echo $index + 1; ?>.</span>
                                        <?php echo htmlspecialchars($song); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">関連ライブ</h5>
                    <?php if (isset($setlist['live_id'])): ?>
                        <div class="list-group list-group-flush">
                            <a href="live-detail.php?id=1" class="list-group-item list-group-item-action">
                                <h6 class="mb-1">ROCK FESTIVAL 2024</h6>
                                <small class="text-muted">4月1日 東京ドーム</small>
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">関連付けられたライブはありません</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 