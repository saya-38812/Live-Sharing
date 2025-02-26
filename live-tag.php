<?php
// URLパラメータからタグ名を取得
$tag = isset($_GET['tag']) ? htmlspecialchars($_GET['tag']) : '神セトリ';

// タグに応じた投稿数とフォロワー数（実際のアプリケーションではDBから取得）
$tag_data = [
    '神セトリ' => ['posts' => 1234, 'followers' => 890],
    'レア曲' => ['posts' => 856, 'followers' => 654],
    'MC感動' => ['posts' => 567, 'followers' => 432],
    '持ち物リスト' => ['posts' => 789, 'followers' => 567],
    '夏フェス' => ['posts' => 1567, 'followers' => 1234],
    '参戦コーデ' => ['posts' => 678, 'followers' => 543],
    'セトリ予想' => ['posts' => 456, 'followers' => 345]
];

// 現在のタグのデータを取得
$current_tag_data = isset($tag_data[$tag]) ? $tag_data[$tag] : ['posts' => 0, 'followers' => 0];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>タグ検索 - LiveShare</title>
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

        /* タグページ専用スタイル */
        .tag-header {
            background-size: cover;
            background-position: center;
            padding: 2rem;
            border-radius: 8px;
            background-color: rgba(25, 135, 84, 0.1);
            border-bottom: 2px solid #198754;
            margin-bottom: 2rem;
        }
        .tag-title {
            font-size: 2rem;
            font-weight: bold;
            color: #198754;
        }
        .tag-stats {
            font-size: 0.9rem;
            color: #198754;
            margin-top: 0.5rem;
        }
        .post-card {
            border-left: 3px solid #198754;
            transition: transform 0.2s;
        }
        .post-card:hover {
            transform: translateX(5px);
            background-color: rgba(25, 135, 84, 0.1);
        }
        .profile-image {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .trend-tag {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: #198754;
            border: 1px solid #198754;
            display: inline-block;
            text-decoration: none;
        }
        .trend-tag:hover {
            background-color: #198754;
            color: white;
        }
        .related-tag {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: #198754;
            border: 1px solid #198754;
            cursor: pointer;
            display: inline-block;
        }
        .related-tag:hover {
            background-color: #198754;
            color: white;
            text-decoration: none;
        }
        .filter-button {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: 1px solid #198754;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-button:hover {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        .filter-button.active {
            background-color: #198754;
            color: white;
            border-color: #198754;
        }

        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem;
            }
            .col-md-7, .col-md-10, .main-content {
                padding-bottom: 5rem;
            }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <!-- タグヘッダー -->
            <div class="tag-header">
                <h2 class="tag-title theme-color">#<?php echo $tag; ?></h2>
                <div class="tag-stats">
                    <i class="bi bi-hash me-2"></i><?php echo number_format($current_tag_data['posts']); ?>件の投稿
                    <i class="bi bi-people-fill ms-4 me-2"></i>
                    <?php echo number_format($current_tag_data['followers']); ?>人がフォロー中
                </div>
            </div>

            <!-- 投稿一覧 -->
            <div class="posts">
                <!-- 投稿カード -->
                <div class="card post-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <img src="img/everton-vila-AsahNlC0VhQ-unsplash.jpg" class="profile-image me-2" alt="ユーザー">
                            <div>
                                <h6 class="mb-0">山田太郎</h6>
                                <small class="text-muted">2時間前</small>
                            </div>
                        </div>
                        <h6>Amazing Night Tour Final</h6>
                        <p>最高のセトリでした！アンコールまで全35曲！</p>
                        <div class="mb-3">
                            <a href="live-tag.php?tag=神セトリ" class="trend-tag">#神セトリ</a>
                            <a href="live-tag.php?tag=レア曲" class="trend-tag">#レア曲</a>
                        </div>
                        <div class="d-flex align-items-center">
                            <button class="btn btn-outline-primary btn-sm me-2">
                                <i class="bi bi-heart me-1"></i>123
                            </button>
                            <button class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-chat me-1"></i>45
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 他の投稿カード -->
                <div class="card post-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <img src="img/everton-vila-AsahNlC0VhQ-unsplash.jpg" class="profile-image me-2" alt="ユーザー">
                            <div>
                                <h6 class="mb-0">佐藤花子</h6>
                                <small class="text-muted">3時間前</small>
                            </div>
                        </div>
                        <h6>Spring Tour 2024</h6>
                        <p>ファンが選んだ曲を中心としたセットリスト！感動しました！</p>
                        <div class="mb-3">
                            <a href="live-tag.php?tag=神セトリ" class="trend-tag">#神セトリ</a>
                            <a href="live-tag.php?tag=感動" class="trend-tag">#感動</a>
                        </div>
                        <div class="d-flex align-items-center">
                            <button class="btn btn-outline-primary btn-sm me-2">
                                <i class="bi bi-heart me-1"></i>89
                            </button>
                            <button class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-chat me-1"></i>23
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- 関連タグ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">関連タグ</h5>
                    <div class="d-flex flex-wrap">
                        <?php
                        // 現在のタグに応じて関連タグを表示（実際のアプリケーションではDBから関連タグを取得）
                        $related_tags = [
                            '神セトリ' => ['レア曲', 'アンコール', '感動', 'ライブ'],
                            'レア曲' => ['神セトリ', 'アンコール', '名曲', 'ライブ'],
                            'MC感動' => ['神MC', 'ライブ', '名言', '感動'],
                            '持ち物リスト' => ['夏フェス', '参戦準備', 'ライブグッズ', '初参戦'],
                            '夏フェス' => ['持ち物リスト', 'フェス飯', '野外ライブ', '夏ライブ'],
                            '参戦コーデ' => ['ライブコーデ', 'グッズコーデ', 'フェスコーデ', 'ファッション'],
                            'セトリ予想' => ['神セトリ', 'レア曲', '予習', 'セトリ']
                        ];

                        $current_related_tags = isset($related_tags[$tag]) ? $related_tags[$tag] : [];
                        foreach ($current_related_tags as $related_tag) {
                            echo '<a href="live-tag.php?tag=' . urlencode($related_tag) . '" class="related-tag">#' . $related_tag . '</a>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- 人気のタグ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">人気のタグ</h5>
                    <!-- タグの内容 -->
                </div>
            </div>

            <!-- トレンド -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">トレンド</h5>
                    <!-- トレンドリスト -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- スマホ版ボトムナビゲーション -->
<nav class="mobile-nav d-md-none">
    <div class="row g-0">
        <div class="col text-center">
            <a href="live-home.php" class="nav-link">
                <i class="bi bi-house-fill"></i>
                <span>ホーム</span>
            </a>
        </div>
        <div class="col text-center">
            <a href="live-calendar.php" class="nav-link">
                <i class="bi bi-calendar-event"></i>
                <span>ライブ</span>
            </a>
        </div>
        <div class="col text-center">
            <a href="live-board.php" class="nav-link">
                <i class="bi bi-chat-square-text"></i>
                <span>掲示板</span>
            </a>
        </div>
        <div class="col text-center">
            <a href="live-search.php" class="nav-link">
                <i class="bi bi-search"></i>
                <span>検索</span>
            </a>
        </div>
        <div class="col text-center">
            <a href="live-mypage.php" class="nav-link">
                <i class="bi bi-person-circle"></i>
                <span>マイページ</span>
            </a>
        </div>
    </div>
</nav>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 