<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        /* サイドバーのスタイル */
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

        /* モバイルナビゲーション */
        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem;
            }
            .mobile-nav {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background-color: white;
                border-top: 1px solid #dee2e6;
                z-index: 1000;
                padding: 0.5rem 0;
            }
            .mobile-nav .nav-link {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 0.5rem;
                color: #6c757d;
                text-decoration: none;
                font-size: 0.8rem;
            }
            .mobile-nav .nav-link i {
                font-size: 1.2rem;
                margin-bottom: 0.2rem;
            }
            .mobile-nav .nav-link.active {
                color: #0d6efd;
                background: none;
            }
        }

        /* メッセージ表示のスタイル */
        .alert {
            margin-bottom: 1rem;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <!-- オーバーレイ -->
    <div class="overlay" id="overlay"></div>

    <!-- メッセージ表示 -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
                echo $_SESSION['error_message'];
                unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- モバイルナビゲーション -->
    <div class="d-md-none">
        <nav class="mobile-nav">
            <div class="container">
                <div class="row">
                    <div class="col">
                        <a href="live-home.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'live-home.php' ? 'active' : ''; ?>">
                            <i class="bi bi-house"></i>
                            <span>ホーム</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="live-calendar.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'live-calendar.php' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar"></i>
                            <span>カレンダー</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="live-search.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'live-search.php' ? 'active' : ''; ?>">
                            <i class="bi bi-search"></i>
                            <span>検索</span>
                        </a>
                    </div>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="col">
                            <a href="user-profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'user-profile.php' ? 'active' : ''; ?>">
                                <i class="bi bi-person"></i>
                                <span>プロフィール</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="col">
                            <a href="login.php" class="nav-link">
                                <i class="bi bi-box-arrow-in-right"></i>
                                <span>ログイン</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </div>