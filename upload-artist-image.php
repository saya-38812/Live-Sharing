<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// アーティストIDの取得
$artistId = isset($_POST['artist_id']) ? (int)$_POST['artist_id'] : 0;

if (!$artistId) {
    set_error_message('アーティストIDが指定されていません。');
    header('Location: artists.php');
    exit;
}

// 画像がアップロードされているか確認
if (!isset($_FILES['artist_image']) || $_FILES['artist_image']['error'] === UPLOAD_ERR_NO_FILE) {
    set_error_message('画像ファイルが選択されていません。');
    header('Location: artist-detail.php?id=' . $artistId);
    exit;
}

// アップロードエラーのチェック
if ($_FILES['artist_image']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'アップロードされたファイルが php.ini の upload_max_filesize ディレクティブを超えています。',
        UPLOAD_ERR_FORM_SIZE => 'アップロードされたファイルがフォームで指定された MAX_FILE_SIZE を超えています。',
        UPLOAD_ERR_PARTIAL => 'アップロードされたファイルが一部のみしかアップロードされていません。',
        UPLOAD_ERR_NO_TMP_DIR => '一時フォルダがありません。',
        UPLOAD_ERR_CANT_WRITE => 'ディスクへの書き込みに失敗しました。',
        UPLOAD_ERR_EXTENSION => 'PHPの拡張モジュールがファイルのアップロードを中止しました。',
    ];
    
    $errorMessage = $errorMessages[$_FILES['artist_image']['error']] ?? '不明なエラーが発生しました。';
    set_error_message('画像のアップロードに失敗しました: ' . $errorMessage);
    header('Location: artist-detail.php?id=' . $artistId);
    exit;
}

// ファイルタイプのチェック
$allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedType = finfo_file($fileInfo, $_FILES['artist_image']['tmp_name']);
finfo_close($fileInfo);

if (!in_array($detectedType, $allowedTypes)) {
    set_error_message('アップロードできるのはJPEGまたはPNG画像のみです。');
    header('Location: artist-detail.php?id=' . $artistId);
    exit;
}

// アップロードディレクトリの確認と作成
$uploadDir = 'uploads/artists/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// 既存の画像を削除
$existingImage = $uploadDir . $artistId . '.jpg';
if (file_exists($existingImage)) {
    unlink($existingImage);
}

// 新しい画像を保存
$uploadFile = $uploadDir . $artistId . '.jpg';

// 画像のリサイズ処理
try {
    // 元の画像を読み込む
    if ($detectedType === 'image/jpeg' || $detectedType === 'image/jpg') {
        $sourceImage = imagecreatefromjpeg($_FILES['artist_image']['tmp_name']);
    } else {
        $sourceImage = imagecreatefrompng($_FILES['artist_image']['tmp_name']);
    }
    
    // 元の画像サイズを取得
    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    
    // 新しいサイズを計算（最大幅500px）
    $maxWidth = 500;
    $maxHeight = 500;
    
    if ($sourceWidth > $maxWidth || $sourceHeight > $maxHeight) {
        if ($sourceWidth > $sourceHeight) {
            $newWidth = $maxWidth;
            $newHeight = ($sourceHeight / $sourceWidth) * $maxWidth;
        } else {
            $newHeight = $maxHeight;
            $newWidth = ($sourceWidth / $sourceHeight) * $maxHeight;
        }
    } else {
        $newWidth = $sourceWidth;
        $newHeight = $sourceHeight;
    }
    
    // 新しい画像を作成
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // 透明度を保持（PNGの場合）
    if ($detectedType === 'image/png') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // リサイズ
    imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
    
    // 画像を保存
    imagejpeg($newImage, $uploadFile, 90);
    
    // メモリを解放
    imagedestroy($sourceImage);
    imagedestroy($newImage);
    
    // データベースの更新（画像URLを保存する場合）
    $stmt = $pdo->prepare("UPDATE artists SET image_url = ?, updated_at = NOW() WHERE id = ?");
    $imageUrl = $uploadDir . $artistId . '.jpg';
    $stmt->execute([$imageUrl, $artistId]);
    
    set_success_message('アーティスト画像を更新しました。');
} catch (Exception $e) {
    error_log('Image upload error: ' . $e->getMessage());
    set_error_message('画像の処理中にエラーが発生しました。');
}

// アーティスト詳細ページにリダイレクト
header('Location: artist-detail.php?id=' . $artistId);
exit; 