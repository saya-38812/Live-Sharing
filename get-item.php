<?php
require_once 'config/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// セッションチェック
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '認証エラー']);
    exit;
}

// IDの取得と検証
$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$itemId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '無効なID']);
    exit;
}

try {
    // アイテムの取得
    $stmt = $pdo->prepare("
        SELECT i.*, il.user_id
        FROM items i
        JOIN item_lists il ON i.item_list_id = il.id
        WHERE i.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // アイテムが存在しない、または権限がない場合
    if (!$item || $item['user_id'] !== $_SESSION['user_id']) {
        throw new Exception('アイテムが見つからないか、アクセス権限がありません。');
    }

    // 成功レスポンス
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'item' => [
            'id' => $item['id'],
            'name' => $item['name'],
            'category' => $item['category'],
            'description' => $item['description'],
            'is_public' => $item['is_public']
        ]
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 