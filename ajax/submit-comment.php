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
$comment = $data['comment'] ?? '';
$predictionId = $data['predictionId'] ?? 0;

if (empty($comment) || $predictionId <= 0) {
    echo json_encode(['success' => false, 'message' => '無効なデータです']);
    exit;
}

try {
    // コメントを保存
    $stmt = $pdo->prepare("
        INSERT INTO setlist_prediction_comments 
        (setlist_prediction_id, user_id, comment, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$predictionId, $_SESSION['user_id'], $comment]);

    // 投稿したユーザー情報を取得
    $stmt = $pdo->prepare("
        SELECT c.*, u.username 
        FROM setlist_prediction_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = LAST_INSERT_ID()
    ");
    $stmt->execute();
    $newComment = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'comment' => [
            'id' => $newComment['id'],
            'username' => $newComment['username'],
            'comment' => $newComment['comment'],
            'created_at' => $newComment['created_at']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'エラーが発生しました: ' . $e->getMessage()]);
} 