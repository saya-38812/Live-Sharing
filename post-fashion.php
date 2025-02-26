<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$error = null;
$success = null;

// ライブIDがURLパラメータで渡された場合
$liveId = isset($_GET['live_id']) ? (int)$_GET['live_id'] : null;
$liveInfo = null;

// ライブ情報を取得
if ($liveId) {
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, a.name as artist_name, v.name as venue_name 
            FROM lives l
            LEFT JOIN artists a ON l.artist_id = a.id
            LEFT JOIN venues v ON l.venue_id = v.id
            WHERE l.id = ?
        ");
        $stmt->execute([$liveId]);
        $liveInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'データベースエラー: ' . $e->getMessage();
    }
}

// 前後1か月のライブ一覧を取得（ライブIDが指定されていない場合の選択肢用）
$recentLives = [];
if (!$liveId) {
    try {
        $stmt = $pdo->prepare("
            SELECT l.id, l.title, l.date, a.name as artist_name, v.name as venue_name
            FROM lives l
            LEFT JOIN artists a ON l.artist_id = a.id
            LEFT JOIN venues v ON l.venue_id = v.id
            WHERE l.date BETWEEN DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH) AND DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH)
            ORDER BY l.date DESC
            LIMIT 50
        ");
        $stmt->execute();
        $recentLives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'データベースエラー: ' . $e->getMessage();
    }
}

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $selectedLiveId = $_POST['live_id'] ?? null;
    $description = $_POST['description'] ?? '';
    $items = $_POST['items'] ?? '';
    
    // バリデーション
    if (empty($selectedLiveId)) {
        $error = 'ライブを選択してください。';
    } elseif (!isset($_FILES['fashion_image']) || $_FILES['fashion_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = '画像をアップロードしてください。';
    } else {
        try {
            // 画像のバリデーション
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedType = finfo_file($fileInfo, $_FILES['fashion_image']['tmp_name']);
            finfo_close($fileInfo);
            
            if (!in_array($detectedType, $allowedTypes)) {
                throw new Exception('アップロードできるのはJPEGまたはPNG画像のみです。');
            }
            
            // アップロードディレクトリの確認と作成
            $uploadDir = 'uploads/fashion/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // ファイル名の生成（一意のファイル名）
            $extension = ($detectedType === 'image/png') ? '.png' : '.jpg';
            $fileName = uniqid('fashion_') . $extension;
            $uploadFile = $uploadDir . $fileName;
            
            // 画像をアップロード（リサイズなし）
            if (!move_uploaded_file($_FILES['fashion_image']['tmp_name'], $uploadFile)) {
                throw new Exception('画像のアップロードに失敗しました。');
            }
            
            // データベースに保存
            $stmt = $pdo->prepare("
                INSERT INTO fashion_posts (user_id, live_id, description, items, image_url, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$userId, $selectedLiveId, $description, $items, $fileName]);
            
            $success = '参戦コーデを投稿しました！';
            
            // マイページにリダイレクト
            header('Location: live-mypage.php?success=' . urlencode($success));
            exit;
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ページタイトル
$pageTitle = '参戦コーデを投稿';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>
        
        <!-- メインコンテンツ -->
        <div class="col-md-9 py-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">参戦コーデを投稿</h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="" method="post" enctype="multipart/form-data">
                        <!-- ライブ選択 -->
                        <div class="mb-3">
                            <label for="live_id" class="form-label">ライブ選択</label>
                            <?php if ($liveInfo): ?>
                                <input type="hidden" name="live_id" value="<?= $liveInfo['id'] ?>">
                                <div class="form-control bg-light">
                                    <?= htmlspecialchars($liveInfo['title']) ?> 
                                    (<?= date('Y年m月d日', strtotime($liveInfo['date'])) ?>)
                                    <?php if ($liveInfo['artist_name']): ?>
                                        - <?= htmlspecialchars($liveInfo['artist_name']) ?>
                                    <?php endif; ?>
                                    <?php if ($liveInfo['venue_name']): ?>
                                        @ <?= htmlspecialchars($liveInfo['venue_name']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <select class="form-select" id="live_id" name="live_id" required>
                                    <option value="">ライブを選択してください</option>
                                    <?php foreach ($recentLives as $live): ?>
                                        <option value="<?= $live['id'] ?>">
                                            <?= htmlspecialchars($live['title']) ?> 
                                            (<?= date('Y/m/d', strtotime($live['date'])) ?>)
                                            <?php if ($live['artist_name']): ?>
                                                - <?= htmlspecialchars($live['artist_name']) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    リストにないライブは、先にライブ情報を登録してください。
                                    <a href="live-calendar.php" class="text-decoration-none">ライブカレンダーを確認</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- 画像アップロード -->
                        <div class="mb-3">
                            <label for="fashion_image" class="form-label">コーデ画像</label>
                            <input type="file" class="form-control" id="fashion_image" name="fashion_image" accept="image/jpeg,image/png" required>
                            <div class="form-text">JPEGまたはPNG形式の画像をアップロードしてください。</div>
                        </div>
                        
                        <!-- 説明 -->
                        <div class="mb-3">
                            <label for="description" class="form-label">コーデの説明</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="このコーデについて教えてください。テーマや工夫したポイントなど。"></textarea>
                        </div>
                        
                        <!-- アイテム情報 -->
                        <div class="mb-3">
                            <label for="items" class="form-label">着用アイテム</label>
                            <textarea class="form-control" id="items" name="items" rows="3" placeholder="着用しているアイテムの情報を記入してください。ブランド名や商品名など。"></textarea>
                            <div class="form-text">例: トップス: ZARA、ボトムス: Levi's 501、シューズ: Dr.Martens 1460</div>
                        </div>
                        
                        <!-- 送信ボタン -->
                        <div class="d-flex justify-content-between">
                            <a href="<?= $liveId ? 'live-detail.php?id=' . $liveId : 'live-mypage.php' ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> 戻る
                            </a>
                            <button type="submit" name="submit" class="btn btn-primary">
                                <i class="bi bi-upload"></i> 投稿する
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- 参考：人気の参戦コーデ -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">人気の参戦コーデ</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php
                        // 人気の参戦コーデを取得
                        try {
                            $stmt = $pdo->prepare("
                                SELECT fp.*, l.title as live_title, u.username,
                                       COUNT(DISTINCT lk.id) as like_count
                                FROM fashion_posts fp
                                JOIN lives l ON fp.live_id = l.id
                                JOIN users u ON fp.user_id = u.id
                                LEFT JOIN likes lk ON lk.likeable_id = fp.id AND lk.likeable_type = 'fashion_post'
                                GROUP BY fp.id
                                ORDER BY like_count DESC, fp.created_at DESC
                                LIMIT 4
                            ");
                            $stmt->execute();
                            $popularFashionPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($popularFashionPosts as $post):
                        ?>
                            <div class="col-md-3 mb-3">
                                <div class="card h-100">
                                    <img src="uploads/fashion/<?= $post['image_url'] ?>" class="card-img-top" alt="参戦コーデ" style="height: 200px; object-fit: cover;">
                                    <div class="card-body">
                                        <h6 class="card-title"><?= htmlspecialchars($post['live_title']) ?></h6>
                                        <p class="card-text small text-muted">
                                            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($post['username']) ?>
                                        </p>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="bi bi-heart-fill text-danger"></i> <?= $post['like_count'] ?>
                                            </small>
                                            <a href="fashion-detail.php?id=<?= $post['id'] ?>" class="btn btn-sm btn-outline-primary">詳細</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php
                            endforeach;
                        } catch (PDOException $e) {
                            // エラー処理
                        }
                        ?>
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
// 画像プレビュー機能
document.getElementById('fashion_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            // プレビュー要素がなければ作成
            let preview = document.getElementById('image-preview');
            if (!preview) {
                preview = document.createElement('div');
                preview.id = 'image-preview';
                preview.className = 'mt-3';
                document.getElementById('fashion_image').parentNode.appendChild(preview);
            }
            
            // プレビュー画像を表示
            preview.innerHTML = `
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">プレビュー</h6>
                        <img src="${e.target.result}" class="img-fluid rounded" style="max-height: 300px;">
                    </div>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    }
});
</script>
</body>
</html> 