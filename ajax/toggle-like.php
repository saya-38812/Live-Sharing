<?php
require_once '../config/database.php';
require_once '../includes/config.php';
require_once '../includes/functions.php';

// セッションチェック
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ログインが必要です。']);
    exit;
}

// POSTデータの取得
$data = json_decode(file_get_contents('php://input'), true);

// CSRFトークンの検証
if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => '不正なリクエストです。']);
    exit;
}

try {
    // トランザクション開始
    $pdo->beginTransaction();

    // いいねの存在確認
    $stmt = $pdo->prepare('
        SELECT id FROM likes 
        WHERE user_id = ? AND likeable_id = ? AND likeable_type = ?
    ');
    $stmt->execute([
        $_SESSION['user_id'],
        $data['likeable_id'],
        $data['likeable_type']
    ]);
    $like = $stmt->fetch();

    if ($like) {
        // いいねを削除
        $stmt = $pdo->prepare('
            DELETE FROM likes 
            WHERE id = ?
        ');
        $stmt->execute([$like['id']]);
        $isLiked = false;
    } else {
        // いいねを追加
        $stmt = $pdo->prepare('
            INSERT INTO likes (user_id, likeable_id, likeable_type, created_at) 
            VALUES (?, ?, ?, NOW())
        ');
        $stmt->execute([
            $_SESSION['user_id'],
            $data['likeable_id'],
            $data['likeable_type']
        ]);
        $isLiked = true;
    }

    // いいね数を取得
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM likes 
        WHERE likeable_id = ? AND likeable_type = ?
    ');
    $stmt->execute([$data['likeable_id'], $data['likeable_type']]);
    $likes = $stmt->fetchColumn();

    // トランザクションをコミット
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'isLiked' => $isLiked,
        'likes' => $likes
    ]);

} catch (Exception $e) {
    // エラー時はロールバック
    $pdo->rollBack();
    error_log('Like Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'データベースエラーが発生しました。']);
}