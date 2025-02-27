<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ログインが必要です']);
    exit;
}

// POSTデータの取得
$data = json_decode(file_get_contents('php://input'), true);
$fashion_id = (int)($data['fashion_id'] ?? 0);
$comment = trim($data['comment'] ?? '');

if (!$fashion_id || !$comment) {
    echo json_encode(['success' => false, 'message' => '無効なリクエストです']);
    exit;
}

try {
    // コメントを保存
    $stmt = $pdo->prepare('
        INSERT INTO fashion_comments (fashion_post_id, user_id, comment, created_at)
        VALUES (?, ?, ?, NOW())
    ');
    $stmt->execute([$fashion_id, $_SESSION['user_id'], $comment]);

    // ユーザー情報を取得
    $stmt = $pdo->prepare('
        SELECT username, profile_image 
        FROM users 
        WHERE id = ?
    ');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'username' => $user['username'],
        'profile_image' => $user['profile_image'],
        'comment' => htmlspecialchars($comment)
    ]);

} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'データベースエラー']);
} 