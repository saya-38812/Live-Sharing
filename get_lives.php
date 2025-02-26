<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => '認証が必要です']));
}

$date = $_GET['date'] ?? null;
if (!$date) {
    http_response_code(400);
    exit(json_encode(['error' => '日付が指定されていません']));
}

try {
    $stmt = $pdo->prepare("
        SELECT l.*, 
               a.name as artist_name, 
               v.name as venue_name,
               u.username as created_by_username
        FROM lives l
        JOIN artists a ON l.artist_id = a.id
        JOIN venues v ON l.venue_id = v.id
        JOIN users u ON l.created_by = u.id
        WHERE l.date = ?
        AND l.created_by != ?
        ORDER BY l.start_time ASC
    ");
    
    $stmt->execute([$date, $_SESSION['user_id']]);
    $lives = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($lives);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'データベースエラーが発生しました']);
} 