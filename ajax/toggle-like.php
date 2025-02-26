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
$id = $data['id'] ?? 0;
$type = $data['type'] ?? '';

if (!$id || !in_array($type, ['post', 'comment'])) {
    echo json_encode(['success' => false, 'message' => '無効なリクエストです']);
    exit;
}

try {
    // トランザクション開始
    $pdo->beginTransaction();

    // いいねの存在確認
    $stmt = $pdo->prepare("
        SELECT id FROM likes 
        WHERE user_id = ? AND likeable_id = ? AND likeable_type = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $id, $type]);
    $existing = $stmt->fetch();

    if ($existing) {
        // いいねを削除
        $stmt = $pdo->prepare("
            DELETE FROM likes 
            WHERE user_id = ? AND likeable_id = ? AND likeable_type = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $id, $type]);
        $isLiked = false;
    } else {
        // いいねを追加
        $stmt = $pdo->prepare("
            INSERT INTO likes (user_id, likeable_id, likeable_type, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $id, $type]);
        $isLiked = true;
    }

    // いいねの総数を取得
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM likes 
        WHERE likeable_id = ? AND likeable_type = ?
    ");
    $stmt->execute([$id, $type]);
    $result = $stmt->fetch();
    $likeCount = (int)$result['count'];

    // トランザクション確定
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'isLiked' => $isLiked,
        'likes' => $likeCount
    ]);

} catch (Exception $e) {
    // エラー時はロールバック
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'エラーが発生しました'
    ]);
}