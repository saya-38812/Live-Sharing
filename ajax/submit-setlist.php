<?php
require_once __DIR__ . '/../includes/config.php';

// JSONレスポンスのヘッダー設定
header('Content-Type: application/json');

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ログインが必要です']);
    exit;
}

// POSTデータの取得
$data = json_decode(file_get_contents('php://input'), true);
$title = $data['title'] ?? '';
$description = $data['description'] ?? '';
$songs = $data['songs'] ?? [];
$threadId = $data['threadId'] ?? 0;

if (empty($title) || empty($songs) || $threadId <= 0) {
    echo json_encode(['success' => false, 'message' => '無効なデータです']);
    exit;
}

try {
    // 予想を保存
    $stmt = $pdo->prepare("
        INSERT INTO setlist_predictions 
        (title, description, user_id, thread_id, songs, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");

    // songsをJSON形式に変換
    $songsJson = json_encode($songs, JSON_THROW_ON_ERROR);

    $stmt->execute([
        $title,
        $description,
        $_SESSION['user_id'],
        $threadId,
        $songsJson
    ]);

    echo json_encode(['success' => true]);

} catch (JsonException $e) {
    echo json_encode(['success' => false, 'message' => '曲データの形式が不正です']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'エラーが発生しました: ' . $e->getMessage()]);
} 