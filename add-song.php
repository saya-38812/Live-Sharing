<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// セットリストIDの取得
$setlist_id = isset($_GET['setlist_id']) ? (int)$_GET['setlist_id'] : 0;

if (!$setlist_id) {
    header('Location: live-board-setlist.php');
    exit;
}

try {
    // セットリストの情報を取得
    $stmt = $pdo->prepare('
        SELECT sp.*, u.username 
        FROM setlist_predictions sp
        JOIN users u ON sp.user_id = u.id
        WHERE sp.id = ? AND sp.user_id = ?
    ');
    $stmt->execute([$setlist_id, $_SESSION['user_id']]);
    $setlist = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$setlist) {
        $_SESSION['error'] = '編集権限がないか、セットリストが存在しません。';
        header('Location: live-board-setlist.php');
        exit;
    }

    // 既存の曲目を取得
    $songs = json_decode($setlist['songs'], true) ?? [];

    // POSTリクエストの処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newSongs = $_POST['songs'] ?? [];
        
        if (empty($newSongs)) {
            $error = '曲名を入力してください。';
        } else {
            // 曲目を更新
            $stmt = $pdo->prepare('
                UPDATE setlist_predictions 
                SET songs = ?, updated_at = NOW() 
                WHERE id = ?
            ');
            $stmt->execute([json_encode($newSongs), $setlist_id]);

            $_SESSION['success'] = '曲を追加しました。';
            header("Location: setlist-detail.php?id=$setlist_id");
            exit;
        }
    }

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
    <title>曲を追加 - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-9 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0">
                    <i class="bi bi-music-note-beamed me-2"></i>曲を追加
                </h4>
                <a href="setlist-detail.php?id=<?= $setlist_id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>戻る
                </a>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3"><?= htmlspecialchars($setlist['title']) ?></h5>
                    
                    <form method="POST" id="songForm">
                        <!-- 既存の曲リスト -->
                        <div class="mb-3">
                            <label class="form-label">セットリスト</label>
                            <ul class="song-list list-unstyled" id="songList">
                                <?php foreach ($songs as $index => $song): ?>
                                    <li class="song-item">
                                        <span class="song-number"><?= $index + 1 ?>.</span>
                                        <input type="text" class="form-control d-inline" name="songs[]" 
                                               value="<?= htmlspecialchars($song) ?>" required>
                                        <i class="bi bi-x-lg song-remove"></i>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addSongButton">
                                <i class="bi bi-plus-lg me-1"></i>曲を追加
                            </button>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>保存する
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- スタイル -->
<style>
.song-list {
    margin-bottom: 1rem;
}

.song-item {
    padding: 0.5rem;
    background-color: white;
    border: 1px solid #dee2e6;
    margin-bottom: 0.5rem;
    border-radius: 4px;
    display: flex;
    align-items: center;
}

.song-number {
    color: #0d6efd;
    font-weight: bold;
    margin-right: 1rem;
    min-width: 2rem;
}

.song-remove {
    cursor: pointer;
    color: #dc3545;
    margin-left: auto;
}

.song-item input {
    flex-grow: 1;
    margin-right: 1rem;
}
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const songList = document.getElementById('songList');
    const addSongButton = document.getElementById('addSongButton');
    const form = document.getElementById('songForm');

    // 曲を追加するボタンの処理
    addSongButton.addEventListener('click', function() {
        const newSongItem = document.createElement('li');
        newSongItem.className = 'song-item';
        newSongItem.innerHTML = `
            <span class="song-number">${songList.children.length + 1}.</span>
            <input type="text" class="form-control d-inline" name="songs[]" required>
            <i class="bi bi-x-lg song-remove"></i>
        `;

        // 削除ボタンの処理
        newSongItem.querySelector('.song-remove').addEventListener('click', function() {
            newSongItem.remove();
            updateSongNumbers();
        });

        songList.appendChild(newSongItem);
    });

    // 既存の削除ボタンにイベントリスナーを追加
    document.querySelectorAll('.song-remove').forEach(button => {
        button.addEventListener('click', function() {
            this.closest('.song-item').remove();
            updateSongNumbers();
        });
    });

    // 曲番号を更新する関数
    function updateSongNumbers() {
        songList.querySelectorAll('.song-item').forEach((item, index) => {
            item.querySelector('.song-number').textContent = `${index + 1}.`;
        });
    }

    // フォーム送信時の処理
    form.addEventListener('submit', function(e) {
        const inputs = this.querySelectorAll('input[name="songs[]"]');
        if (inputs.length === 0) {
            e.preventDefault();
            alert('少なくとも1曲は必要です。');
        }
    });
});
</script>
</body>
</html> 