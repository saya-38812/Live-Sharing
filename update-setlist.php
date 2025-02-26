<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// POSTリクエストの確認
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_error_message('不正なアクセスです。');
    redirect('live-calendar.php');
}

// データの取得
$live_id = filter_input(INPUT_POST, 'live_id', FILTER_VALIDATE_INT);
$setlist = trim($_POST['setlist'] ?? '');

if (!$live_id) {
    set_error_message('無効なライブIDです。');
    redirect('live-calendar.php');
}

try {
    // トランザクション開始
    $pdo->beginTransaction();

    // ライブのセットリストを更新
    $stmt = $pdo->prepare("UPDATE lives SET setlist = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$setlist, $live_id]);

    // actual_setlistsテーブルに登録
    $stmt = $pdo->prepare("
        INSERT INTO actual_setlists (live_id, title, performance_date, created_at, updated_at)
        SELECT id, title, date, NOW(), NOW()
        FROM lives WHERE id = ?
    ");
    $stmt->execute([$live_id]);
    $actual_setlist_id = $pdo->lastInsertId();

    // セットリストの曲を登録
    if (!empty($setlist)) {
        $songs = explode("\n", $setlist);
        $stmt = $pdo->prepare("
            INSERT INTO actual_setlist_songs 
            (actual_setlist_id, title, position, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        foreach ($songs as $position => $title) {
            if (trim($title) !== '') {
                $stmt->execute([$actual_setlist_id, trim($title), $position + 1]);
            }
        }
    }

    $pdo->commit();
    set_success_message('セットリストを更新しました。');

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Setlist update error: ' . $e->getMessage());
    set_error_message('セットリストの更新に失敗しました。');
}

redirect("live-detail.php?id=" . $live_id); 