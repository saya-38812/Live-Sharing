<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class SetlistManager {
    private $pdo;
    private $userId;
    private $error;
    private $success;
    
    public function __construct(PDO $pdo, int $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = '';
        $this->success = '';
    }
    
    public function createSetlist(array $data): ?int {
        try {
            $title = trim($data['title'] ?? '');
            $description = trim($data['description'] ?? '');
            $liveId = !empty($data['live_id']) ? (int)$data['live_id'] : null;
            $songs = json_decode($data['songs'] ?? '[]', true);
            
            if (empty($title)) {
                $this->error = 'タイトルを入力してください。';
                return null;
            }

            if (empty($songs)) {
                $this->error = '少なくとも1曲は追加してください。';
                return null;
            }

            // トランザクション開始
            $this->pdo->beginTransaction();
            
            // セットリスト予想を作成
            $stmt = $this->pdo->prepare('
                INSERT INTO setlist_predictions (
                    title, 
                    description, 
                    user_id, 
                    live_id,
                    songs,
                    likes_count,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
            ');
            
            $stmt->execute([
                $title, 
                $description, 
                $this->userId, 
                $liveId,
                json_encode($songs)
            ]);
            
            $setlistId = $this->pdo->lastInsertId();

            $this->pdo->commit();
            $this->success = 'セットリストを作成しました。';
            return $setlistId;
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('Database Error: ' . $e->getMessage());
            $this->error = 'データベースエラーが発生しました。';
            return null;
        }
    }
    
    public function getAvailableLives(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, title, date 
                FROM lives 
                WHERE date >= CURDATE() 
                ORDER BY date ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            return [];
        }
    }
    
    public function getError(): string {
        return $this->error;
    }
    
    public function getSuccess(): string {
        return $this->success;
    }
}



// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// セットリストマネージャーの初期化
$setlistManager = new SetlistManager($pdo, $_SESSION['user_id']);

// POSTリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setlistId = $setlistManager->createSetlist($_POST);
    if ($setlistId) {
        header("Location: setlist-detail.php?id=" . $setlistId);
        exit;
    }
}

// 利用可能なライブの取得
$availableLives = $setlistManager->getAvailableLives();

// エラーと成功メッセージの取得
$error = $setlistManager->getError();
$success = $setlistManager->getSuccess();

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>セットリスト作成 - LiveShare</title>
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
                <h4 class="section-title mb-0 theme-color">新規セットリスト作成</h4>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="title" class="form-label">タイトル</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>

                        <div class="mb-3">
                            <label for="live_id" class="form-label">関連ライブ（任意）</label>
                            <select class="form-select" id="live_id" name="live_id">
                                <option value="">選択してください</option>
                                <?php foreach ($availableLives as $live): ?>
                                    <option value="<?= htmlspecialchars($live['id']) ?>">
                                        <?= htmlspecialchars($live['title']) ?> 
                                        (<?= date('Y/m/d', strtotime($live['date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">説明</label>
                            <textarea class="form-control" id="description" name="description" rows="4"></textarea>
                        </div>

                        <!-- 曲リストセクションを追加 -->
                        <div class="mb-3">
                            <label class="form-label">セットリスト</label>
                            <ul class="song-list list-unstyled" id="songList">
                                <!-- 曲のリストがここに追加される -->
                            </ul>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="addSongButton">
                                <i class="bi bi-plus-lg me-1"></i>曲を追加
                            </button>
                        </div>

                        <!-- 隠しフィールドを追加 -->
                        <input type="hidden" name="songs" id="songsInput">

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="submitButton">
                                <i class="bi bi-plus-lg me-2"></i>作成する
                            </button>
                            <a href="live-board-setlist.php" class="btn btn-outline-secondary">キャンセル</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">セットリスト作成のヒント</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="bi bi-info-circle me-2"></i>タイトルは具体的に</li>
                        <li class="mb-2"><i class="bi bi-info-circle me-2"></i>説明には予想の根拠も</li>
                        <li class="mb-2"><i class="bi bi-info-circle me-2"></i>関連ライブを選択すると見つけやすく</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- スタイルを追加 -->
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

<!-- JavaScriptを追加 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const songList = document.getElementById('songList');
    const addSongButton = document.getElementById('addSongButton');
    const songsInput = document.getElementById('songsInput');
    const form = document.querySelector('form');

    // 曲を追加するボタンの処理
    addSongButton.addEventListener('click', function() {
        const newSongItem = document.createElement('li');
        newSongItem.className = 'song-item';
        newSongItem.innerHTML = `
            <span class="song-number">${songList.children.length + 1}.</span>
            <input type="text" class="form-control d-inline" placeholder="曲名を入力...">
            <i class="bi bi-x-lg song-remove"></i>
        `;

        // 削除ボタンの処理
        newSongItem.querySelector('.song-remove').addEventListener('click', function() {
            newSongItem.remove();
            updateSongNumbers();
        });

        songList.appendChild(newSongItem);
    });

    // 曲番号を更新する関数
    function updateSongNumbers() {
        songList.querySelectorAll('.song-item').forEach((item, index) => {
            item.querySelector('.song-number').textContent = `${index + 1}.`;
        });
    }

    // フォーム送信時の処理
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // 曲リストを配列に変換
        const songs = Array.from(songList.querySelectorAll('.song-item input'))
            .map(input => input.value.trim())
            .filter(value => value);

        // 曲が1つも入力されていない場合
        if (songs.length === 0) {
            alert('少なくとも1曲は追加してください');
            return;
        }

        // 隠しフィールドに曲リストをJSONとして設定
        songsInput.value = JSON.stringify(songs);
        
        // フォームを送信
        this.submit();
    });
});
</script>
</body>
</html> 