<?php
session_start();
require_once 'config/database.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = 'ログインが必要です。';
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $live_id = $_POST['live_id'] ?? null;
    $content = $_POST['content'] ?? '';
    $tags = $_POST['tags'] ?? '';

    // 入力値のバリデーション
    if (empty($live_id) || empty($content)) {
        $_SESSION['error_message'] = '必要な情報が不足しています。';
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // つぶやきを投稿
        $stmt = $pdo->prepare("
            INSERT INTO posts (user_id, thread_id, live_id, content, tags, created_at, updated_at)
            VALUES (?, NULL, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $live_id, $content, $tags]);

        $pdo->commit();
        $_SESSION['success_message'] = 'つぶやきを投稿しました。';

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        // より詳細なエラーメッセージをログに記録
        error_log('SQL Error: ' . $e->getMessage());
        error_log('Parameters: ' . json_encode([
            'user_id' => $_SESSION['user_id'],
            'live_id' => $live_id,
            'content' => $content,
            'tags' => $tags
        ]));
        $_SESSION['error_message'] = 'つぶやきの投稿に失敗しました。';
    }
}

header('Location: ' . $_SERVER['HTTP_REFERER']);
exit; 