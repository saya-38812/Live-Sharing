<?php
require_once 'config/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class CalendarManager {
    private $pdo;
    private $userId;
    private $error;
    private $data;
    private $year;
    private $month;
    
    public function __construct(PDO $pdo, ?int $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = null;
        
        // 年月の取得（指定がない場合は現在の年月）
        $this->year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $this->month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        
        // カレンダーの初期化
        $firstDay = new DateTime("{$this->year}-{$this->month}-01");
        $lastDay = new DateTime("{$this->year}-{$this->month}-" . $firstDay->format('t'));
        $prevMonth = (clone $firstDay)->modify('-1 month');
        $nextMonth = (clone $firstDay)->modify('+1 month');
        
        $this->data = [
            'lives' => [],
            'livesByDate' => [],
            'year' => $this->year,
            'month' => $this->month,
            'firstDay' => $firstDay,
            'lastDay' => $lastDay,
            'prevMonth' => $prevMonth,
            'nextMonth' => $nextMonth
        ];
    }
    
    public function handleLiveAddition(array $postData): void {
        try {
            $this->pdo->beginTransaction();

            // デバッグ情報を出力
            error_log('Post Data: ' . print_r($postData, true));
            error_log('Event Type: ' . $postData['event_type']);

            if ($postData['event_type'] === 'festival') {
            $venueId = $this->getOrCreateVenue($postData['venue_name']);
            
                // フェスの場合は明示的にnullを指定
                $liveId = $this->createLive($postData, null, $venueId);
                
                // 出演アーティストの登録
                if (!empty($postData['festival_artists'])) {
                    foreach ($postData['festival_artists'] as $index => $artistName) {
                        if (empty($artistName)) continue;
                        
                        $artistId = $this->getOrCreateArtist($artistName);
                        $startTime = !empty($postData['festival_times'][$index]) ? 
                            $postData['festival_times'][$index] : null;
                        
                        $stmt = $this->pdo->prepare("
                            INSERT INTO performances (
                                event_id, artist_id, start_time, 
                                performance_order, created_at
                            ) VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $liveId,
                            $artistId,
                            $startTime,
                            $index + 1
                        ]);
                    }
                }
            } else {
                // ワンマンライブの処理（既存のコード）
                $artistId = $this->getOrCreateArtist($postData['artist_name']);
                $venueId = $this->getOrCreateVenue($postData['venue_name']);
            $this->createLive($postData, $artistId, $venueId);
            }

            $this->pdo->commit();
            
            $_SESSION['success_message'] = 'ライブを追加しました。';
            header('Location: ' . $_SERVER['PHP_SELF'] . 
                   '?year=' . date('Y', strtotime($postData['date'])) . 
                   '&month=' . date('m', strtotime($postData['date'])));
            exit;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
        }
    }
    
    private function getOrCreateArtist(string $artistName): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO artists (name) 
            VALUES (?) 
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
        ");
        $stmt->execute([$artistName]);
        return (int)$this->pdo->lastInsertId();
    }
    
    private function getOrCreateVenue(string $venueName): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO venues (name) 
            VALUES (?) 
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
        ");
        $stmt->execute([$venueName]);
        return (int)$this->pdo->lastInsertId();
    }
    
    private function createLive(array $data, ?int $artistId, int $venueId): int {
        try {
        $stmt = $this->pdo->prepare("
            INSERT INTO lives (
                title, artist_id, venue_id, date, open_time, start_time, 
                    event_type_id, created_by, created_at, updated_at
            ) VALUES (
                    ?, ?, ?, ?, ?, ?, 
                    (SELECT id FROM event_types WHERE name = ?),
                    ?, NOW(), NOW()
                )
            ");
            
            // 空文字列やfalsy値の場合はnullに変換
            $openTime = !empty($data['open_time']) ? $data['open_time'] : null;
            $startTime = !empty($data['start_time']) ? $data['start_time'] : null;
            
            $params = [
            $data['title'],
            $artistId,
            $venueId,
            $data['date'],
                $openTime,
                $startTime,
                $data['event_type'],
                $this->userId
            ];
            
            // デバッグ情報を出力
            error_log('SQL Parameters: ' . print_r($params, true));
            
            $stmt->execute($params);
            return (int)$this->pdo->lastInsertId();
            
        } catch (PDOException $e) {
            error_log('SQL Error: ' . $e->getMessage());
            error_log('Error Code: ' . $e->getCode());
            throw $e;
        }
    }
    
    public function loadCalendarData(): void {
        try {
            $this->data['lives'] = $this->getLives();
            $this->data['livesByDate'] = $this->groupLivesByDate($this->data['lives']);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, 
                   v.name as venue_name,
                   et.name as event_type,
                   COALESCE(a.name, 
                       (SELECT GROUP_CONCAT(a2.name SEPARATOR ', ')
                        FROM performances p
                        JOIN artists a2 ON p.artist_id = a2.id
                        WHERE p.event_id = l.id
                        GROUP BY p.event_id)
                   ) as artist_names
            FROM lives l
            JOIN venues v ON l.venue_id = v.id
            JOIN event_types et ON l.event_type_id = et.id
            LEFT JOIN artists a ON l.artist_id = a.id
            WHERE l.date BETWEEN ? AND ?
            AND (l.created_by = ? OR EXISTS (
                SELECT 1 FROM user_lives ul 
                WHERE ul.live_id = l.id 
                AND ul.user_id = ?
            ))
            GROUP BY l.id
            ORDER BY l.date ASC, l.start_time ASC
        ");
        
        $stmt->execute([
            $this->data['firstDay']->format('Y-m-d'),
            $this->data['lastDay']->format('Y-m-d'),
            $this->userId ?? 0,
            $this->userId ?? 0
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function groupLivesByDate(array $lives): array {
        $livesByDate = [];
        foreach ($lives as $live) {
            $date = date('Y-m-d', strtotime($live['date']));
            if (!isset($livesByDate[$date])) {
                $livesByDate[$date] = [];
            }
            $livesByDate[$date][] = $live;
        }
        return $livesByDate;
    }
    
    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        if ($e instanceof PDOException) {
            $this->error = 'データベースエラーが発生しました。';
            echo $e->getMessage();
        } else {
            $this->error = $e->getMessage();
        }
    }
    
    public function getData(): array {
        return $this->data;
    }
    
    public function getError(): ?string {
        return $this->error;
    }

    // 特定の日付のライブを取得するメソッドを追加
    public function getLivesByDate(string $date): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, 
                   a.name as artist_name, 
                   v.name as venue_name,
                   u.username as created_by_username,
                   COUNT(DISTINCT b.id) as bookmark_count,
                   EXISTS (
                       SELECT 1 FROM bookmarks 
                       WHERE bookmarkable_id = l.id 
                       AND bookmarkable_type = 'live' 
                       AND user_id = ?
                   ) as is_bookmarked,
                   et.name as event_type,
                   GROUP_CONCAT(DISTINCT pa.name) as festival_artists
            FROM lives l
            JOIN venues v ON l.venue_id = v.id
            JOIN users u ON l.created_by = u.id
            JOIN event_types et ON l.event_type_id = et.id
            LEFT JOIN artists a ON l.artist_id = a.id
            LEFT JOIN performances p ON p.event_id = l.id
            LEFT JOIN artists pa ON p.artist_id = pa.id
            LEFT JOIN bookmarks b ON b.bookmarkable_id = l.id AND b.bookmarkable_type = 'live'
            WHERE l.date = ?
            AND l.created_by != ?
            GROUP BY l.id
            ORDER BY l.start_time ASC
        ");
        
        $stmt->execute([
            $this->userId,
            $date,
            $this->userId
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // デフォルトのステージを取得または作成するメソッド
    private function getOrCreateDefaultStage(): int {
        // 既存のデフォルトステージを検索
        $stmt = $this->pdo->prepare("
            SELECT id FROM stages 
            WHERE name = 'メインステージ' 
            AND event_id IS NULL
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return (int)$result['id'];
        }
        
        // eventsテーブルにダミーレコードを作成
        $stmt = $this->pdo->prepare("
            INSERT INTO events (title, created_at) 
            VALUES ('デフォルトイベント', NOW())
        ");
        $stmt->execute();
        $eventId = (int)$this->pdo->lastInsertId();
        
        // デフォルトステージを作成
        $stmt = $this->pdo->prepare("
            INSERT INTO stages (name, event_id, created_at) 
            VALUES ('メインステージ', ?, NOW())
        ");
        $stmt->execute([$eventId]);
        return (int)$this->pdo->lastInsertId();
    }
}

// ページの初期化
$userId = $_SESSION['user_id'] ?? null;

// ページマネージャーの初期化
$calendarManager = new CalendarManager($pdo, $userId);

// ライブ追加の処理
if (isset($_POST['add_live'])) {
    $calendarManager->handleLiveAddition($_POST);
}

// カレンダーデータの読み込み
$calendarManager->loadCalendarData();

// エラーチェック
if ($error = $calendarManager->getError()) {
    exit($error);
}

// データの取得
$data = $calendarManager->getData();
extract($data);

// 年月の変数を明示的に設定
$year = $data['year'];
$month = $data['month'];
$firstDay = $data['firstDay'];
$lastDay = $data['lastDay'];
$prevMonth = $data['prevMonth'];
$nextMonth = $data['nextMonth'];

// 既存のHTML部分はそのまま維持
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ライブカレンダー - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        /* 共通スタイル */
        .sidebar {
            min-height: 100vh;
            background-color: #e8f0fe;
            border-right: 1px solid #dee2e6;
        }
        .nav-link {
            color: #333;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .nav-link:hover {
            background-color: #cfe2ff;
        }
        .nav-link.active {
            background-color: #0d6efd;
            color: white;
        }

        /* カレンダーテーブルのスタイル */
        .calendar-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 2px;
        }

        .calendar-table th {
            padding: 10px;
            text-align: center;
            font-weight: 600;
            color: #495057;
            background-color: #f8f9fa;
            border-bottom: 2px solid var(--theme-color);
        }

        .calendar-table td {
            height: 120px;
            vertical-align: top;
            padding: 8px;
            border: 1px solid #dee2e6;
            background-color: white;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .calendar-table td:hover {
            background-color: #f8f9fa;
        }

        .calendar-table th:first-child,
        .calendar-table td.sunday .calendar-date {
            color: #dc3545;
        }

        .calendar-table th:last-child,
        .calendar-table td.saturday .calendar-date {
            color: #0d6efd;
        }

        .calendar-table td.other-month {
            background-color: #f8f9fa;
            color: #6c757d;
        }

        .calendar-table td.today {
            background-color: #e8f0fe;
        }

        /* ライブイベントのスタイル */
        .live-event {
            background: #e8f0fe;
            border-radius: 4px;
            padding: 0.3rem;
            margin-bottom: 0.3rem;
            font-size: 0.8rem;
            border-left: 3px solid #0d6efd;
            display: block;
            transition: all 0.2s ease;
        }

        .live-event:hover {
            transform: translateX(5px);
            background-color: #cfe2ff;
            text-decoration: none;
        }

        .event-title {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.2rem;
            font-weight: 500;
        }

        .event-badge {
            font-size: 0.7rem;
            padding: 0.1rem 0.3rem;
            display: inline-block;
        }
        .today {
            background-color: #e8f0fe;
        }
        .other-month {
            background-color: #f8f9fa;
            color: #6c757d;
        }
        .sunday {
            background-color: #f8f9ff;
        }
        .saturday {
            background-color: #f8f9ff;
        }
        .calendar-header .col {
            padding: 10px;
            font-weight: 600;
            color: #495057;
        }
        .calendar-header .col:first-child,
        .calendar-day.sunday .calendar-date {
            color: #dc3545;
        }
        .calendar-header .col:last-child,
        .calendar-day.saturday .calendar-date {
            color: #0d6efd;
        }

        /* モバイル用カレンダースタイル */
        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem; /* ボトムナビゲーションの高さ分の余白 */
            }

            /* メインコンテンツの余白調整 */
            .col-md-10 {
                padding-bottom: 5rem; /* ボトムナビゲーションの高さ + 追加の余白 */
            }

            .calendar-header {
                position: sticky;
                top: 0;
                background: white;
                z-index: 100;
                padding: 1rem;
                border-bottom: 1px solid #dee2e6;
            }

            .month-selector {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 1rem;
            }

            .weekdays {
                display: flex;
                justify-content: space-around;
                padding: 0.5rem 0;
                background: #f8f9fa;
                font-weight: 500;
                font-size: 0.8rem;
                border-bottom: 2px solid var(--theme-color);
            }

            .weekdays span {
                width: calc(100% / 7);
                text-align: center;
            }

            .calendar-grid {
                display: flex;
                flex-wrap: wrap;
                border-left: 1px solid #dee2e6;
                border-top: 1px solid #dee2e6;
                margin-bottom: 1rem; /* カレンダーグリッドの下部に余白を追加 */
            }

            .calendar-day {
                width: calc(100% / 7);
                height: auto;
                min-height: 100px;
                border-bottom: 1px solid #dee2e6;
                border-right: 1px solid #dee2e6;
                padding: 0.5rem;
                position: relative;
            }

            .calendar-date {
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
                font-weight: 500;
            }

            .today .calendar-date {
                background: #0d6efd;
                color: white;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 0.5rem;
            }

            .other-month .calendar-date {
                color: #adb5bd;
            }

            .live-event {
                background: #e8f0fe;
                border-radius: 4px;
                padding: 0.3rem;
                margin-bottom: 0.3rem;
                font-size: 0.8rem;
                border-left: 3px solid #0d6efd;
            }

            .event-title {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                margin-bottom: 0.2rem;
            }

            .event-badge {
                font-size: 0.7rem;
                padding: 0.1rem 0.3rem;
            }

            /* 月切り替えボタンのスタイル */
            .month-nav {
                padding: 0.5rem 1rem;
                border: 1px solid #dee2e6;
                border-radius: 20px;
                background: white;
                font-weight: 500;
            }
        }

        /* フェスティバルとライブの表示スタイルを区別 */
        .calendar-event.festival-event {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
        }

        .calendar-event.live-event {
            background-color: #d1ecf1;
            border-left: 4px solid #17a2b8;
        }

        .event-title {
            font-weight: bold;
            margin-bottom: 2px;
        }

        .event-artist {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .event-venue {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 2px;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-10 py-4">
            <!-- メッセージ表示 -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- カレンダーヘッダー -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center">
                    <h2 class="me-4">
                        <?php echo $year; ?>年<?php echo $month; ?>月
                    </h2>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addLiveModal">
                        <i class="bi bi-plus-circle me-2"></i>ライブを追加
                    </button>
                </div>
                <div>
                    <a href="?year=<?php echo $prevMonth->format('Y'); ?>&month=<?php echo $prevMonth->format('m'); ?>" 
                       class="btn btn-outline-primary me-2">
                        <i class="bi bi-chevron-left"></i> 前月
                    </a>
                    <a href="?year=<?php echo date('Y'); ?>&month=<?php echo date('m'); ?>" 
                       class="btn btn-outline-primary me-2">今月</a>
                    <a href="?year=<?php echo $nextMonth->format('Y'); ?>&month=<?php echo $nextMonth->format('m'); ?>" 
                       class="btn btn-outline-primary">
                        次月 <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- カレンダーヘッダー -->
            <div class="calendar-header d-md-none">
                <div class="month-selector">
                    <button class="month-nav">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <h5 class="mb-0">
                        <?php echo $year; ?>年<?php echo $month; ?>月
                    </h5>
                    <button class="month-nav">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <div class="weekdays">
                    <span class="text-danger">日</span>
                    <span>月</span>
                    <span>火</span>
                    <span>水</span>
                    <span>木</span>
                    <span>金</span>
                    <span class="text-primary">土</span>
                </div>
            </div>

            <!-- モバイル用カレンダー -->
            <div class="calendar-grid d-md-none">
                <?php
                // カレンダーの日付を生成
                $firstWeekday = $firstDay->format('w');
                $lastDate = $lastDay->format('j');

                // 前月の日付を取得
                if ($firstWeekday > 0) {
                    $prevMonth = clone $firstDay;
                    $prevMonth->modify('-1 month');
                    $prevMonthLastDay = (int)$prevMonth->format('t');
                    for ($i = $firstWeekday - 1; $i >= 0; $i--) {
                        echo '<div class="calendar-day other-month">';
                        echo '<div class="calendar-date">' . ($prevMonthLastDay - $i) . '</div>';
                        echo '</div>';
                    }
                }

                // 当月の日付を表示
                for ($day = 1; $day <= $lastDate; $day++) {
                    $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $isToday = $day == date('j') && $month == date('m') && $year == date('Y');
                    $dayClass = $isToday ? 'calendar-day today' : 'calendar-day';

                    echo '<div class="' . $dayClass . '" onclick="showLivesForDate(\'' . $currentDate . '\')">';
                    echo '<div class="calendar-date">' . $day . '</div>';

                    // ライブ情報の表示
                    if (isset($livesByDate[$currentDate])) {
                        foreach ($livesByDate[$currentDate] as $live) {
                            // イベントタイプに応じてアイコンを表示
                            $eventIcon = ($live['event_type'] === 'festival') ? 
                                '<i class="bi bi-music-note-list text-danger me-1"></i>' : 
                                '<i class="bi bi-music-note text-primary me-1"></i>';
                            
                            echo '<a href="live-detail.php?id=' . $live['id'] . '" class="live-event text-decoration-none">';
                            echo '<div class="event-title text-dark">';
                            echo $eventIcon; // イベントタイプアイコン
                            echo '<small>' . htmlspecialchars($live['artist_names']) . '</small><br>';
                            echo htmlspecialchars($live['title']);
                            echo '</div>';
                            echo '</a>';
                        }
                    }
                    echo '</div>';
                }

                // 次月の日付を表示
                $remainingDays = 42 - ($firstWeekday + $lastDate);
                for ($i = 1; $i <= $remainingDays; $i++) {
                    echo '<div class="calendar-day other-month">';
                    echo '<div class="calendar-date">' . $i . '</div>';
                    echo '</div>';
                }
                ?>
            </div>

            <!-- PC用カレンダー -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-bordered calendar-table">
                    <thead>
                        <tr>
                            <th>日</th>
                            <th>月</th>
                            <th>火</th>
                            <th>水</th>
                            <th>木</th>
                            <th>金</th>
                            <th>土</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // カレンダーの日付を生成
                        $calendar = [];
                        $firstWeekday = $firstDay->format('w');
                        $lastDate = $lastDay->format('j');

                        // 前月の日付を取得
                        $prevMonthDays = [];
                        if ($firstWeekday > 0) {
                            $prevMonth = clone $firstDay;
                            $prevMonth->modify('-1 month');
                            $prevMonthLastDay = (int)$prevMonth->format('t');
                            for ($i = $firstWeekday - 1; $i >= 0; $i--) {
                    $prevMonthDays[] = [
                        'day' => $prevMonthLastDay - $i,
                        'date' => $prevMonth->format('Y-m') . '-' . sprintf('%02d', $prevMonthLastDay - $i)
                    ];
                            }
                        }

                        // 今月の日付を配列に格納
            $currentMonthDays = [];
            for ($day = 1; $day <= $lastDate; $day++) {
                $currentMonthDays[] = [
                    'day' => $day,
                    'date' => sprintf('%04d-%02d-%02d', $year, $month, $day)
                ];
            }

                        // 次月の日付を取得
            $nextMonthDays = [];
                        $remainingDays = 42 - (count($prevMonthDays) + count($currentMonthDays));
            for ($i = 1; $i <= $remainingDays; $i++) {
                $nextMonthDays[] = [
                    'day' => $i,
                    'date' => $nextMonth->format('Y-m') . '-' . sprintf('%02d', $i)
                ];
            }

                        // カレンダー配列の作成
                        $calendar = array_merge($prevMonthDays, $currentMonthDays, $nextMonthDays);

                        // カレンダーの表示
                        for ($i = 0; $i < 6; $i++) {
                            echo "<tr>";
                            for ($j = 0; $j < 7; $j++) {
                                $index = $i * 7 + $j;
                    $dayData = $calendar[$index];
                                $isCurrentMonth = $index >= count($prevMonthDays) && 
                                                $index < (count($prevMonthDays) + count($currentMonthDays));
                    $isToday = $isCurrentMonth && $dayData['day'] == date('j') &&
                                         $year == date('Y') && $month == date('m');
                                
                                $classes = [];
                                if (!$isCurrentMonth) $classes[] = 'other-month';
                                if ($isToday) $classes[] = 'today';
                                if ($j == 0) $classes[] = 'sunday';
                                if ($j == 6) $classes[] = 'saturday';
                                
                                echo '<td class="' . implode(' ', $classes) . '"';
                    echo ' style="cursor: pointer;"'; // カーソルをポインターに
                    echo ' onclick="showLivesForDate(\'' . $dayData['date'] . '\')">';
                    echo '<div class="calendar-date">' . $dayData['day'] . '</div>';

                    if ($isCurrentMonth && isset($livesByDate[$dayData['date']])) {
                        foreach ($livesByDate[$dayData['date']] as $live) {
                            // イベントタイプに応じてアイコンを表示
                            $eventIcon = ($live['event_type'] === 'festival') ?
                                '<i class="bi bi-music-note-list text-danger me-1"></i>' :
                                '<i class="bi bi-music-note text-primary me-1"></i>';
                                            echo '<a href="live-detail.php?id=' . $live['id'] . '" class="live-event text-decoration-none">';
                            echo '<div class="event-title text-dark">';
                            echo $eventIcon;
                            echo '<small>' . htmlspecialchars($live['artist_names']) . '</small><br>';
                            echo htmlspecialchars($live['title']);
                                            echo '</div>';
                                            echo '</a>';
                                        }
                                    }

                                echo '</td>';
                            }
                            echo "</tr>";
                        }

                        ?>
                    </tbody>
                </table>
            </div>


            <!-- 凡例 -->
            <div class="mt-4">
                <div class="d-flex align-items-center">
                    <div class="me-4">
                            <i class="bi bi-circle-fill me-2" style="color: #0d6efd;"></i>
                        フェス・大規模イベント
                    </div>
                    <div class="me-4">
                        <i class="bi bi-circle-fill text-success me-2"></i>
                        ワンマンライブ
                    </div>
                    <div>
                        <i class="bi bi-circle-fill text-warning me-2"></i>
                        その他イベント
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ライブ追加モーダル -->
<div class="modal fade" id="addLiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ライブを追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <!-- 日付選択フォーム -->
            <div class="modal-body" id="dateSelectorForm">
                <div class="mb-3">
                    <label class="form-label">日付を選択</label>
                    <div class="d-flex gap-2 align-items-center">
                        <div class="flex-grow-1">
                            <input type="text" 
                                   class="form-control text-center" 
                                   id="yearInput" 
                                   placeholder="YYYY" 
                                   maxlength="4" 
                                   pattern="\d{4}"
                                   required>
                        </div>
                        <span class="fs-5">年</span>
                        <div style="width: 80px;">
                            <input type="text" 
                                   class="form-control text-center" 
                                   id="monthInput" 
                                   placeholder="MM" 
                                   maxlength="2" 
                                   pattern="\d{2}"
                                   required>
                        </div>
                        <span class="fs-5">月</span>
                        <div style="width: 80px;">
                            <input type="text" 
                                   class="form-control text-center" 
                                   id="dayInput" 
                                   placeholder="DD" 
                                   maxlength="2" 
                                   pattern="\d{2}"
                                   required>
                        </div>
                        <span class="fs-5">日</span>
                    </div>
                    <input type="hidden" id="dateSelector">
                    <div class="invalid-feedback" id="dateError"></div>
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-primary" id="confirmDateBtn" disabled>
                        次へ
                    </button>
                </div>
            </div>

            <!-- ライブ形式選択フォーム -->
            <div class="modal-body" id="eventTypeForm" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">ライブ形式を選択</label>
                    <select class="form-select" name="event_type" id="eventTypeSelect" required>
                        <option value="">選択してください</option>
                        <option value="one_man">ワンマンライブ</option>
                        <option value="festival">フェス</option>
                    </select>
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-secondary me-2" id="backToDateBtn">戻る</button>
                    <button type="button" class="btn btn-primary" id="confirmEventTypeBtn">次へ</button>
                </div>
            </div>

            <!-- 既存のライブ選択または新規作成フォーム -->
            <div class="modal-body" id="liveSelectionForm" style="display: none;">
                <!-- 既存のライブ一覧 -->
                <div id="existingLives" style="display: none;">
                    <h6 class="mb-3">この日の既存のライブ</h6>
                    <div id="existingLivesList" class="list-group mb-3">
                        <!-- 既存のライブがJSで追加されます -->
                    </div>
                    <div class="text-center">
                        <button type="button" class="btn btn-outline-primary" id="createNewLiveBtn">
                            新しいライブを作成
                        </button>
                    </div>
                </div>

                <!-- 新規ライブ作成フォーム -->
                <form action="" method="POST" id="newLiveForm" style="display: none;">
                    <input type="hidden" name="date" id="selectedDate">
                    <input type="hidden" name="event_type" id="formEventType">

                    <div class="mb-3">
                        <label class="form-label">ライブタイトル</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>

                    <!-- ワンマン用のフォーム -->
                    <div id="oneManForm" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label">アーティスト</label>
                            <input type="text" class="form-control" name="artist_name">
                    </div>
                    </div>

                    <!-- フェス用のフォーム -->
                    <div id="festivalForm" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">出演アーティスト</label>
                            <div id="artistsList">
                                <div class="artist-entry mb-2">
                                    <div class="input-group">
                                        <input type="text" 
                                               class="form-control" 
                                               name="festival_artists[]" 
                                               placeholder="アーティスト名" 
                                               required>
                                        <input type="time" 
                                               class="form-control" 
                                               name="festival_times[]" 
                                               placeholder="出演時間">
                                        <button type="button" 
                                                class="btn btn-outline-secondary remove-artist">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-2">
                                <button type="button" class="btn btn-outline-primary" id="addArtistBtn">
                                    <i class="bi bi-plus"></i> アーティストを追加
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">会場</label>
                        <input type="text" class="form-control" name="venue_name" required>
                    </div>

                    <!-- 既存の時間選択部分 -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                開場時間
                                <span class="text-muted">(任意)</span>
                            </label>
                            <select class="form-select" name="open_time">
                                <option value="">選択してください</option>
                                <?php
                                for ($hour = 0; $hour < 24; $hour++) {
                                    for ($min = 0; $min < 60; $min += 30) {
                                        $time = sprintf('%02d:%02d', $hour, $min);
                                        echo "<option value=\"{$time}\">{$time}</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                開演時間
                                <span class="text-muted">(任意)</span>
                            </label>
                            <select class="form-select" name="start_time">
                                <option value="">選択してください</option>
                                <?php
                                for ($hour = 0; $hour < 24; $hour++) {
                                    for ($min = 0; $min < 60; $min += 30) {
                                        $time = sprintf('%02d:%02d', $hour, $min);
                                        echo "<option value=\"{$time}\">{$time}</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" name="add_live" class="btn btn-primary">登録する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ライブ選択モーダル -->
<div class="modal fade" id="livesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ライブを選択</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="existingLivesList" class="mb-3">
                    <!-- 既存のライブ一覧がここに表示される -->
                </div>
                <div class="text-center">
                    <button type="button" class="btn btn-primary" onclick="showAddLiveModal()">
                        <i class="bi bi-plus-circle me-2"></i>新規ライブを追加
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const yearInput = document.getElementById('yearInput');
    const monthInput = document.getElementById('monthInput');
    const dayInput = document.getElementById('dayInput');
    const dateSelector = document.getElementById('dateSelector');
    const confirmDateBtn = document.getElementById('confirmDateBtn');
    const dateError = document.getElementById('dateError');
    const dateSelectorForm = document.getElementById('dateSelectorForm');
    const liveSelectionForm = document.getElementById('liveSelectionForm');
    const existingLives = document.getElementById('existingLives');
    const existingLivesList = document.getElementById('existingLivesList');
    const newLiveForm = document.getElementById('newLiveForm');
    const createNewLiveBtn = document.getElementById('createNewLiveBtn');
    const selectedDateInput = document.getElementById('selectedDate');
    const addLiveModal = new bootstrap.Modal(document.getElementById('addLiveModal'));
    const eventTypeForm = document.getElementById('eventTypeForm');
    const eventTypeSelect = document.getElementById('eventTypeSelect');
    const confirmEventTypeBtn = document.getElementById('confirmEventTypeBtn');
    const backToDateBtn = document.getElementById('backToDateBtn');
    const oneManForm = document.getElementById('oneManForm');
    const festivalForm = document.getElementById('festivalForm');
    const addArtistBtn = document.getElementById('addArtistBtn');
    const artistsList = document.getElementById('artistsList');

    // 数字のみ入力を許可する関数
    function onlyNumbers(event) {
        if (!/[0-9]/.test(event.key) && event.key !== 'Backspace' && event.key !== 'Delete' && event.key !== 'Tab') {
            event.preventDefault();
        }
    }

    // 入力値の検証
    function validateInputs() {
        const year = parseInt(yearInput.value);
        const month = parseInt(monthInput.value);
        const day = parseInt(dayInput.value);

        if (yearInput.value.length !== 4) {
            dateError.textContent = '年は4桁で入力してください';
            return false;
        }
        if (monthInput.value.length !== 2 || month < 1 || month > 12) {
            dateError.textContent = '月は01-12の範囲で入力してください';
            return false;
        }
        if (dayInput.value.length !== 2) {
            dateError.textContent = '日は2桁で入力してください';
            return false;
        }

        // 日付の妥当性チェック
        const date = new Date(year, month - 1, day);
        if (date.getFullYear() !== year || date.getMonth() + 1 !== month || date.getDate() !== day) {
            dateError.textContent = '無効な日付です';
            return false;
        }

        dateError.textContent = '';
        return true;
    }

    // 入力フィールドのイベントリスナー設定
    [yearInput, monthInput, dayInput].forEach(input => {
        input.addEventListener('keypress', onlyNumbers);
        input.addEventListener('input', function() {
            // 2桁になったら次のフィールドに移動（年は4桁）
            if (this === yearInput && this.value.length === 4) {
                monthInput.focus();
            } else if (this === monthInput && this.value.length === 2) {
                dayInput.focus();
            }

            // ボタンの有効/無効を切り替え
            confirmDateBtn.disabled = !validateInputs();
        });
    });

    // 日付確認ボタンのクリックイベント
    confirmDateBtn.addEventListener('click', function() {
        if (!validateInputs()) return;

        const dateStr = `${yearInput.value}-${monthInput.value}-${dayInput.value}`;
        dateSelector.value = dateStr;
        selectedDateInput.value = dateStr;

        // 日付選択フォームを非表示にしてライブ形式選択フォームを表示
        dateSelectorForm.style.display = 'none';
        eventTypeForm.style.display = 'block';
    });

    // 戻るボタンの処理
    backToDateBtn.addEventListener('click', function() {
        eventTypeForm.style.display = 'none';
        dateSelectorForm.style.display = 'block';
    });

    // ライブ形式確認ボタンの処理
    confirmEventTypeBtn.addEventListener('click', function() {
        if (!eventTypeSelect.value) {
            alert('ライブ形式を選択してください');
            return;
        }

        // フォームのevent_type値を設定
        document.getElementById('formEventType').value = eventTypeSelect.value;

        eventTypeForm.style.display = 'none';
        liveSelectionForm.style.display = 'block';
        newLiveForm.style.display = 'block';

        // ライブ形式に応じてフォームを表示
        if (eventTypeSelect.value === 'one_man') {
            oneManForm.style.display = 'block';
            festivalForm.style.display = 'none';
        } else {
            oneManForm.style.display = 'none';
            festivalForm.style.display = 'block';
        }
    });

    // モーダルリセット処理を更新
    document.getElementById('addLiveModal').addEventListener('hidden.bs.modal', function () {
        yearInput.value = '';
        monthInput.value = '';
        dayInput.value = '';
        dateSelector.value = '';
        dateError.textContent = '';
        eventTypeSelect.value = '';
        confirmDateBtn.disabled = true;
        dateSelectorForm.style.display = 'block';
        eventTypeForm.style.display = 'none';
        liveSelectionForm.style.display = 'none';
        existingLives.style.display = 'none';
        newLiveForm.style.display = 'none';
    });

    // カレンダーの日付クリック時の処理を追加
    window.showLivesForDate = async function(date) {
        selectedDateInput.value = date;

        try {
            const response = await fetch(`get_lives.php?date=${date}`);
            if (!response.ok) throw new Error('Network response was not ok');
            const lives = await response.json();

            if (lives.length > 0) {
                // 既存のライブがある場合の処理
            existingLivesList.innerHTML = lives.map(live => `
                    <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">${live.title}</h6>
                        <small>${live.start_time}</small>
                    </div>
                        <p class="mb-1">
                            ${live.artist_name} @ ${live.venue_name}<br>
                            <small class="text-muted">登録者: ${live.created_by_username}</small>
                        </p>
                        <button class="btn btn-sm btn-primary mt-2" onclick="addToMyCalendar(${live.id})">
                            自分のカレンダーに追加
                        </button>
                    </div>
            `).join('');

                existingLives.style.display = 'block';
                newLiveForm.style.display = 'none';
            } else {
                // 既存のライブがない場合の処理
                existingLives.style.display = 'none';
                newLiveForm.style.display = 'block';
            }

            // 日付選択フォームを非表示にして直接ライブ選択/作成フォームを表示
            dateSelectorForm.style.display = 'none';
            liveSelectionForm.style.display = 'block';
            addLiveModal.show();
        } catch (error) {
            console.error('Error:', error);
            alert('ライブ情報の取得に失敗しました');
        }
    };

    // 「ライブを追加」ボタンクリック時は日付選択から開始
    document.querySelector('.add-live-btn').addEventListener('click', function() {
        dateSelectorForm.style.display = 'block';
        liveSelectionForm.style.display = 'none';
        existingLives.style.display = 'none';
        newLiveForm.style.display = 'none';
        addLiveModal.show();
    });

    // アーティスト追加ボタンの処理
    addArtistBtn.addEventListener('click', function() {
        const newArtistEntry = document.createElement('div');
        newArtistEntry.className = 'artist-entry mb-2';
        newArtistEntry.innerHTML = `
            <div class="input-group">
                <input type="text" 
                       class="form-control" 
                       name="festival_artists[]" 
                       placeholder="アーティスト名" 
                       required>
                <input type="time" 
                       class="form-control" 
                       name="festival_times[]" 
                       placeholder="出演時間">
                <button type="button" 
                        class="btn btn-outline-secondary remove-artist">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        `;
        artistsList.appendChild(newArtistEntry);

        // すべての削除ボタンを表示（最初のエントリーも含む）
        const removeButtons = artistsList.querySelectorAll('.remove-artist');
        removeButtons.forEach(button => {
            button.style.display = 'block';
        });
    });

    // アーティスト削除ボタンの処理を修正
    artistsList.addEventListener('click', function(e) {
        if (e.target.closest('.remove-artist')) {
            const entry = e.target.closest('.artist-entry');
            const entries = artistsList.querySelectorAll('.artist-entry');
            
            // エントリーが2つ以上ある場合のみ削除を許可
            if (entries.length > 1) {
                entry.remove();
                
                // 残りのエントリーが1つになった場合も削除ボタンを表示したままにする
                const remainingButtons = artistsList.querySelectorAll('.remove-artist');
                remainingButtons.forEach(button => {
                    button.style.display = 'block';
                });
            } else {
                alert('少なくとも1組のアーティストが必要です');
            }
        }
    });

    // フォーム送信前の検証
    newLiveForm.addEventListener('submit', function(e) {
        const eventType = eventTypeSelect.value;
        if (eventType === 'festival') {
            const artistEntries = artistsList.querySelectorAll('.artist-entry');
            let hasEmptyFields = false;
            
            artistEntries.forEach(entry => {
                const artistName = entry.querySelector('input[name="festival_artists[]"]').value;
                if (!artistName.trim()) {
                    hasEmptyFields = true;
                }
            });
            
            if (hasEmptyFields) {
                e.preventDefault();
                alert('すべてのアーティスト名を入力してください');
            }
        }
    });
});
</script>
</body>
</html> 