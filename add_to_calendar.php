<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => '認証が必要です']));
}

$data = json_decode(file_get_contents('php://input'), true);
$liveId = $data['live_id'] ?? null;

if (!$liveId) {
    http_response_code(400);
    exit(json_encode(['error' => 'ライブIDが指定されていません']));
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO user_lives (user_id, live_id, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$_SESSION['user_id'], $liveId]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'データベースエラーが発生しました']);
} 