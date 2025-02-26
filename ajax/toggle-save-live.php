<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ログインが必要です']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$liveId = $data['live_id'] ?? 0;

try {
    // 保存の存在確認
    $stmt = $pdo->prepare("
        SELECT id FROM bookmarks 
        WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'live'
    ");
    $stmt->execute([$_SESSION['user_id'], $liveId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // 保存を削除
        $stmt = $pdo->prepare("
            DELETE FROM bookmarks 
            WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'live'
        ");
        $stmt->execute([$_SESSION['user_id'], $liveId]);
        $isSaved = false;
    } else {
        // 保存を追加
        $stmt = $pdo->prepare("
            INSERT INTO bookmarks (user_id, bookmarkable_id, bookmarkable_type, created_at)
            VALUES (?, ?, 'live', NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $liveId]);
        $isSaved = true;
    }

    echo json_encode([
        'success' => true,
        'isSaved' => $isSaved
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'エラーが発生しました'
    ]);
}