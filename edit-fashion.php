<?php
require_once 'config/database.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

// セッションチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 投稿IDの取得
$fashion_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$fashion_id) {
    $_SESSION['error'] = '投稿IDが指定されていません。';
    header('Location: live-board-fashion.php');
    exit;
}

try {
    // 投稿データの取得
    $stmt = $pdo->prepare('
        SELECT fp.*, l.title as live_title
        FROM fashion_posts fp
        LEFT JOIN lives l ON fp.live_id = l.id
        WHERE fp.id = :id AND fp.user_id = :user_id
    ');
    $stmt->execute([
        ':id' => $fashion_id,
        ':user_id' => $_SESSION['user_id']
    ]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        $_SESSION['error'] = '投稿が見つからないか、編集権限がありません。';
        header('Location: live-board-fashion.php');
        exit;
    }

    // POSTリクエストの処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // デバッグ情報
            error_log('=== Update Fashion Post ===');
            error_log('POST data: ' . print_r($_POST, true));
            error_log('FILES data: ' . print_r($_FILES, true));

            // トランザクション開始
            $pdo->beginTransaction();

            $description = trim($_POST['description'] ?? '');
            $tags = trim($_POST['tags'] ?? '');
            $image_url = $post['image_url']; // デフォルトは現在の画像

            // 画像のアップロード処理（新しい画像がある場合のみ）
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK && !empty($_FILES['image']['tmp_name'])) {
                error_log('Starting image upload process...');
                
                // アップロードされたファイルの詳細なデバッグ情報
                error_log('=== Uploaded File Details ===');
                error_log('Name: ' . $_FILES['image']['name']);
                error_log('Type: ' . $_FILES['image']['type']);
                error_log('Temp Path: ' . $_FILES['image']['tmp_name']);
                error_log('Error Code: ' . $_FILES['image']['error']);
                error_log('Size: ' . $_FILES['image']['size']);
                
                $upload_dir = 'uploads/fashion/';
                // アップロードディレクトリの確認と作成
                if (!file_exists($upload_dir)) {
                    error_log('Creating upload directory: ' . $upload_dir);
                    if (!mkdir($upload_dir, 0777, true)) {
                        throw new Exception('アップロードディレクトリの作成に失敗しました。');
                    }
                }

                $temp_name = $_FILES['image']['tmp_name'];
                $original_name = $_FILES['image']['name'];
                
                // MIMEタイプの確認
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $temp_name);
                finfo_close($finfo);
                
                error_log('MIME Type: ' . $mime_type);
                
                // 許可するMIMEタイプ
                $allowed_mimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/jfif'];
                if (!in_array($mime_type, $allowed_mimes)) {
                    throw new Exception('許可されていないファイル形式です。jpg, jpeg, png, jfifのみ許可されています。（検出された形式: ' . $mime_type . '）');
                }
                
                // 拡張子の確認
                $file_type = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                error_log('File extension: ' . $file_type);
                
                // 許可する拡張子
                $allowed_types = ['jpg', 'jpeg', 'png', 'jfif'];
                if (!in_array($file_type, $allowed_types)) {
                    // JFIFの場合はJPGに変換
                    if ($file_type === 'jfif') {
                        $file_type = 'jpg';
                    } else {
                        throw new Exception('許可されていない拡張子です。jpg, jpeg, png, jfifのみ許可されています。（検出された拡張子: ' . $file_type . '）');
                    }
                }

                // 新しいファイル名を生成
                $new_filename = uniqid('fashion_') . '.' . $file_type;
                $destination = $upload_dir . $new_filename;
                error_log('New filename: ' . $new_filename);
                error_log('Destination: ' . $destination);

                // 画像をアップロード
                if (move_uploaded_file($temp_name, $destination)) {
                    error_log('New image uploaded successfully: ' . $destination);
                    
                    // 古い画像を削除（ただし、デフォルト画像は削除しない）
                    if ($post['image_url'] && file_exists($upload_dir . $post['image_url'])) {
                        error_log('Attempting to delete old image: ' . $post['image_url']);
                        if (unlink($upload_dir . $post['image_url'])) {
                            error_log('Old image deleted successfully');
                        } else {
                            error_log('Failed to delete old image');
                        }
                    }
                    
                    $image_url = $new_filename;
                    error_log('Image URL updated to: ' . $image_url);
                } else {
                    $upload_error = error_get_last();
                    error_log('Failed to upload image. Error: ' . ($upload_error ? $upload_error['message'] : 'Unknown error'));
                    throw new Exception('画像のアップロードに失敗しました。サーバーの権限を確認してください。');
                }
            } else {
                error_log('No new image uploaded or upload error occurred');
                error_log('$_FILES status: ' . print_r($_FILES, true));
            }

            // データベースの更新
            $stmt = $pdo->prepare('
                UPDATE fashion_posts 
                SET description = :description,
                    tags = :tags,
                    image_url = :image_url,
                    updated_at = NOW()
                WHERE id = :id AND user_id = :user_id
            ');

            $params = [
                ':description' => $description,
                ':tags' => $tags,
                ':image_url' => $image_url,
                ':id' => $fashion_id,
                ':user_id' => $_SESSION['user_id']
            ];

            error_log('SQL params: ' . print_r($params, true));

            if (!$stmt->execute($params)) {
                throw new Exception('データベースの更新に失敗しました。');
            }

            // 更新が成功した場合
            $pdo->commit();
            error_log('Update successful');
            $_SESSION['success'] = '投稿を更新しました。';
            header('Location: fashion-detail.php?id=' . $fashion_id);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Update Error: ' . $e->getMessage());
            if (isset($stmt)) {
                error_log('SQL Error Info: ' . print_r($stmt->errorInfo(), true));
            }
            $_SESSION['error'] = 'エラーが発生しました: ' . $e->getMessage();
            header('Location: edit-fashion.php?id=' . $fashion_id);
            exit;
        }
    }

} catch (Exception $e) {
    error_log('Edit Fashion Error: ' . $e->getMessage());
    $_SESSION['error'] = 'エラーが発生しました。' . $e->getMessage();
    header('Location: fashion-detail.php?id=' . $fashion_id);
    exit;
}

$pageTitle = '参戦コーデ編集 - LiveShare';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <!-- エラーメッセージ表示部分を追加 -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- 成功メッセージ表示部分を追加 -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <!-- デバッグ情報の表示（開発時のみ） -->
            <?php if (isset($_FILES['image'])): ?>
                <div class="alert alert-info">
                    <h4>アップロード情報:</h4>
                    <pre><?php print_r($_FILES['image']); ?></pre>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-3">
                <h1 class="h2">参戦コーデ編集</h1>
                <a href="fashion-detail.php?id=<?= $fashion_id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> 戻る
                </a>
            </div>

            <div class="card">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <!-- ライブ情報（表示のみ） -->
                        <div class="mb-3">
                            <label class="form-label">ライブ</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($post['live_title'] ?? '') ?>" readonly>
                        </div>

                        <!-- 現在の画像 -->
                        <div class="mb-3">
                            <label class="form-label">現在の画像</label>
                            <div class="mb-2">
                                <img src="uploads/fashion/<?= htmlspecialchars($post['image_url'] ?? '') ?>" 
                                     class="img-thumbnail" style="max-width: 200px;" alt="現在の画像">
                            </div>
                        </div>

                        <!-- 新しい画像のアップロード -->
                        <div class="mb-3">
                            <label for="image" class="form-label">新しい画像（変更する場合のみ）</label>
                            <input type="file" class="form-control" id="image" name="image" accept="image/*">
                        </div>

                        <!-- 説明文 -->
                        <div class="mb-3">
                            <label for="description" class="form-label">説明文</label>
                            <textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($post['description'] ?? '') ?></textarea>
                        </div>

                        <!-- タグ -->
                        <div class="mb-3">
                            <label for="tags" class="form-label">タグ（カンマ区切り）</label>
                            <input type="text" class="form-control" id="tags" name="tags" 
                                   value="<?= htmlspecialchars($post['tags'] ?? '') ?>">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">更新する</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?> 