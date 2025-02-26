<!-- PC用サイドバー -->
<div class="col-md-2 sidebar py-4 d-none d-md-block">
    <div class="d-flex flex-column">
        <div class="mb-4 px-3">
            <a href="index.php" class="text-decoration-none">
                <h3 class="logo-text">
                    <span class="theme-color">Live Sharing</span>
                </h3>
            </a>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'live-home.php') ? 'active' : ''; ?>" href="live-home.php">
                <i class="bi bi-house-fill me-2"></i>ホーム
            </a>
            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'live-calendar.php') ? 'active' : ''; ?>" href="live-calendar.php">
                <i class="bi bi-calendar-event me-2"></i>ライブ一覧
            </a>
            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'live-board.php') ? 'active' : ''; ?>" href="live-board.php">
                <i class="bi bi-chat-square-text me-2"></i>掲示板
            </a>
            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'live-search.php') ? 'active' : ''; ?>" href="live-search.php">
                <i class="bi bi-search me-2"></i>検索
            </a>
            <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'live-mypage.php') ? 'active' : ''; ?>" href="live-mypage.php">
                <i class="bi bi-person-circle me-2"></i>マイページ
            </a>
        </nav>
    </div>
</div>

<!-- モバイル用ボトムナビゲーション -->
<div class="fixed-bottom bg-white border-top d-md-none">
    <div class="row g-0">
        <div class="col">
            <a href="live-home.php" class="btn btn-link text-decoration-none px-1 w-100 py-2 <?php echo (basename($_SERVER['PHP_SELF']) == 'live-home.php') ? 'text-primary active' : 'text-dark'; ?>">
                <i class="bi bi-house-fill d-block"></i>
                <small>ホーム</small>
            </a>
        </div>
        <div class="col">
            <a href="live-calendar.php" class="btn btn-link text-decoration-none px-1 w-100 py-2 <?php echo (basename($_SERVER['PHP_SELF']) == 'live-calendar.php') ? 'text-primary' : 'text-dark'; ?>">
                <i class="bi bi-calendar-event d-block"></i>
                <small>ライブ</small>
            </a>
        </div>
        <div class="col">
            <a href="live-board.php" class="btn btn-link text-decoration-none px-1 w-100 py-2 <?php echo (basename($_SERVER['PHP_SELF']) == 'live-board.php') ? 'text-primary' : 'text-dark'; ?>">
                <i class="bi bi-chat-square-text d-block"></i>
                <small>掲示板</small>
            </a>
        </div>
        <div class="col">
            <a href="live-search.php" class="btn btn-link text-decoration-none px-1 w-100 py-2 <?php echo (basename($_SERVER['PHP_SELF']) == 'live-search.php') ? 'text-primary' : 'text-dark'; ?>">
                <i class="bi bi-search d-block"></i>
                <small>検索</small>
            </a>
        </div>
        <div class="col">
            <a href="live-mypage.php" class="btn btn-link text-decoration-none px-1 w-100 py-2 <?php echo (basename($_SERVER['PHP_SELF']) == 'live-mypage.php') ? 'text-primary' : 'text-dark'; ?>">
                <i class="bi bi-person-circle d-block"></i>
                <small>マイページ</small>
            </a>
        </div>
    </div>
</div>

<style>
    .sidebar {
        min-height: 100vh;
        background-color: #e8f0fe;
        border-right: 1px solid #dee2e6;
    }

    /* ロゴ部分のスタイル */
    .sidebar .mb-4 {
        margin-bottom: 2rem !important;
    }

    .sidebar .px-3 {
        padding-left: 1.5rem !important;
        padding-right: 1.5rem !important;
    }

    /* ナビゲーションのスタイル */
    .nav.flex-column {
        padding: 0 1rem;
    }

    .nav-link {
        color: #333;
        padding: 0.8rem 1rem;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        transition: all 0.3s ease;
    }

    .nav-link:hover {
        background-color: #cfe2ff;
        transform: translateX(5px);
    }

    .nav-link.active {
        background-color: #0d6efd;
        color: white;
    }
</style> 