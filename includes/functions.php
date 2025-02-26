<?php
/**
 * 時間の経過を表示する関数
 * @param string $datetime 日時文字列
 * @return string 経過時間の文字列
 */
function time_ago($datetime) {
    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        if ($diff->y > 0) {
            return $diff->y . '年前';
        } elseif ($diff->m > 0) {
            return $diff->m . 'ヶ月前';
        } elseif ($diff->d > 0) {
            return $diff->d . '日前';
        } elseif ($diff->h > 0) {
            return $diff->h . '時間前';
        } elseif ($diff->i > 0) {
            return $diff->i . '分前';
        } else {
            return 'たった今';
        }
    } catch (Exception $e) {
        error_log('time_ago error: ' . $e->getMessage());
        return '日時不明';
    }
}

/**
 * 経過時間を人間が読みやすい形式で返す関数
 * @param string $datetime 日時文字列
 * @return string 経過時間の文字列
 */
function time_elapsed_string($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . 'ヶ月前';
    if ($diff->d > 0) return $diff->d . '日前';
    if ($diff->h > 0) return $diff->h . '時間前';
    if ($diff->i > 0) return $diff->i . '分前';
    return 'たった今';
}

/**
 * 文字列をHTML特殊文字に変換する関数
 * @param string $str 変換する文字列
 * @return string 変換された文字列
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * 日付を日本語形式で表示する関数
 * @param string $date 日付文字列
 * @return string フォーマットされた日付
 */
function format_date($date) {
    try {
        $datetime = new DateTime($date);
        return $datetime->format('Y年n月j日');
    } catch (Exception $e) {
        error_log('format_date error: ' . $e->getMessage());
        return '日付不明';
    }
}

function set_error_message($message) {
    $_SESSION['error_message'] = $message;
}

function set_success_message($message) {
    $_SESSION['success_message'] = $message;
}

function redirect($location) {
    header("Location: $location");
    exit;
}

/**
 * ユーザーが特定のアイテムをブックマークしているかチェックする関数
 * @param PDO $pdo データベース接続
 * @param int $userId ユーザーID
 * @param int $bookmarkableId ブックマーク対象のID
 * @param string $bookmarkableType ブックマーク対象のタイプ ('live', 'artist', 'setlist' など)
 * @return bool ブックマークしていればtrue、していなければfalse
 */
function isBookmarked(PDO $pdo, int $userId, int $bookmarkableId, string $bookmarkableType): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookmarks 
            WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = ?
        ");
        $stmt->execute([$userId, $bookmarkableId, $bookmarkableType]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('isBookmarked error: ' . $e->getMessage());
        return false;
    }
}

/**
 * ユーザーが管理者かどうかを確認する
 * 
 * @param PDO $pdo データベース接続オブジェクト
 * @param int $userId ユーザーID
 * @return bool 管理者の場合はtrue、それ以外はfalse
 */
function isAdmin($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user && $user['role'] === 'admin';
    } catch (PDOException $e) {
        error_log('isAdmin function error: ' . $e->getMessage());
        return false;
    }
} 