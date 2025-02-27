<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class SetlistEditor {
    private $pdo;
    private $userId;
    private $error;
    private $success;
    private $setlistId;
    
    public function __construct(PDO $pdo, int $userId, int $setlistId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->setlistId = $setlistId;
        $this->error = null;
        $this->success = null;
    }
    
    public function getSetlistData(): ?array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT sp.*, l.title as live_title, l.date as live_date
                FROM setlist_predictions sp
                LEFT JOIN lives l ON sp.live_id = l.id
                WHERE sp.id = ? AND sp.user_id = ?
            ");
            $stmt->execute([$this->setlistId, $this->userId]);
            $setlist = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($setlist) {
                // 曲目情報を取得
                $stmt = $this->pdo->prepare("
                    SELECT * FROM setlist_prediction_songs
                    WHERE setlist_prediction_id = ?
                    ORDER BY position
                ");
                $stmt->execute([$this->setlistId]);
                $setlist['songs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $setlist;
            
        } catch (PDOException $e) {
            error_log('Database Error: ' . $e->getMessage());
            $this->error = 'データベースエラーが発生しました。';
            return null;
        }
    }
    
    public function updateSetlist(array $data): bool {
        try {
            $title = trim($data['title'] ?? '');
            $description = trim($data['description'] ?? '');
            $liveId = !empty($data['live_id']) ? (int)$data['live_id'] : null;
            $songs = array_filter(explode("\n", trim($data['songs'] ?? '')));
            
            if (empty($title)) {
                $this->error = 'タイトルを入力してください。';
                return false;
            }
            
            $this->pdo->beginTransaction();
            
            // セットリスト本体の更新
            $stmt = $this->pdo->prepare("
                UPDATE setlist_predictions 
                SET title = ?, description = ?, live_id = ?, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$title, $description, $liveId, $this->setlistId, $this->userId]);
            
            // 既存の曲を削除
            $stmt = $this->pdo->prepare("
                DELETE FROM setlist_prediction_songs 
                WHERE setlist_prediction_id = ?
            ");
            $stmt->execute([$this->setlistId]);
            
            // 新しい曲を追加
            if (!empty($songs)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO setlist_prediction_songs 
                    (setlist_prediction_id, title, position, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                
                foreach ($songs as $position => $song) {
                    if (!empty(trim($song))) {
                        $stmt->execute([$this->setlistId, trim($song), $position + 1]);
                    }
                }
            }
            
            $this->pdo->commit();
            $this->success = 'セットリストを更新しました。';
            return true;
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('Database Error: ' . $e->getMessage());
            $this->error = 'データベースエラーが発生しました。';
            return false;
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
    
    public function getError(): ?string {
        return $this->error;
    }
    
    public function getSuccess(): ?string {
        return $this->success;
    }
}

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

// エディターの初期化
$editor = new SetlistEditor($pdo, $_SESSION['user_id'], $setlist_id);

// POSTリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($editor->updateSetlist($_POST)) {
        header("Location: setlist-detail.php?id=" . $setlist_id);
        exit;
    }
}

// セットリストデータの取得
$setlist = $editor->getSetlistData();
if (!$setlist) {
    header('Location: index.php');
    exit;
}

// 利用可能なライブの取得
$availableLives = $editor->getAvailableLives();

// エラーと成功メッセージの取得
$error = $editor->getError();
$success = $editor->getSuccess();

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>セットリスト編集 - LiveShare</title>
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
                <h4 class="section-title mb-0">セットリスト編集</h4>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="title" class="form-label">タイトル</label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   value="<?= h($setlist['title']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="live_id" class="form-label">関連ライブ（任意）</label>
                            <select class="form-select" id="live_id" name="live_id">
                                <option value="">選択してください</option>
                                <?php foreach ($availableLives as $live): ?>
                                    <option value="<?= h($live['id']) ?>" 
                                            <?= $setlist['live_id'] == $live['id'] ? 'selected' : '' ?>>
                                        <?= h($live['title']) ?> 
                                        (<?= date('Y/m/d', strtotime($live['date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">説明</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="4"><?= h($setlist['description']) ?></textarea>
                        </div>

                        <!-- <div class="mb-3">
                            <label for="songs" class="form-label">曲目（1行に1曲）</label>
                            <textarea class="form-control" id="songs" name="songs" rows="10"><?php
                                if (!empty($setlist['songs'])) {
                                    foreach ($setlist['songs'] as $song) {
                                        echo h($song['title']) . "\n";
                                    }
                                }
                            ?></textarea>
                        </div> -->

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>更新する
                            </button>
                            <a href="setlist-detail.php?id=<?= $setlist_id ?>" class="btn btn-outline-secondary">
                                キャンセル
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 