<?php
require_once __DIR__ . '/config/database.php';

class IndexPageManager {
    private $pdo;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->error = null;
        $this->data = [
            'upcomingLives' => [],
            'popularTags' => []
        ];
    }
    
    public function loadPageData(): void {
        try {
            $this->data['upcomingLives'] = $this->getUpcomingLives();
            $this->data['popularTags'] = $this->getPopularTags();
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getUpcomingLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, a.name as artist_name, v.name as venue_name,
                   COUNT(DISTINCT b.id) as bookmark_count
            FROM lives l
            JOIN artists a ON l.artist_id = a.id
            JOIN venues v ON l.venue_id = v.id
            LEFT JOIN bookmarks b ON b.bookmarkable_id = l.id 
                AND b.bookmarkable_type = 'live'
            WHERE l.date >= CURDATE()
            GROUP BY l.id
            ORDER BY l.date ASC
            LIMIT 6
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPopularTags(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, COUNT(tg.id) as use_count
            FROM tags t
            LEFT JOIN taggables tg ON t.id = tg.tag_id
            GROUP BY t.id
            ORDER BY use_count DESC
            LIMIT 8
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        if ($e instanceof PDOException) {
            $this->error = 'データベースエラーが発生しました。';
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
}

session_start();

// ページデータの読み込み
$pageManager = new IndexPageManager($pdo);
$pageManager->loadPageData();

// データの取得
$error = $pageManager->getError();
if ($error) {
    exit($error);
}

extract($pageManager->getData());

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LiveShare - ライブ情報共有プラットフォーム</title>
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

        /* ホーム専用スタイル */
        .welcome-header {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .event-card {
            transition: transform 0.2s;
        }
        .event-card:hover {
            transform: translateY(-2px);
        }

        /* ヒーローセクション */
        .hero-section {
            background-color: rgba(25, 135, 84, 0.1);
            padding: 6rem 0;
            margin-bottom: 4rem;
        }
        
        .hero-title {
            font-size: 3rem;
            color: #198754;
            font-weight: bold;
            margin-bottom: 1.5rem;
        }

        /* 特徴セクション */
        .feature-card {
            border: none;
            border-radius: 10px;
            transition: transform 0.3s;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-10px);
        }

        .feature-icon {
            font-size: 2.5rem;
            color: #198754;
            margin-bottom: 1rem;
        }

        .live-card {
            border: none;
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.3s;
        }

        .live-card:hover {
            transform: translateY(-5px);
        }

        .tag-badge {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
            border: 1px solid #198754;
            transition: all 0.2s;
            text-decoration: none;
            margin: 0.2rem;
        }

        .tag-badge:hover {
            background-color: #198754;
            color: white;
        }

        /* CTAセクション */
        .cta-section {
            background-color: #198754;
            color: white;
            padding: 4rem 0;
            margin-top: 4rem;
        }

        .navbar-brand .theme-color,
        .card-title.theme-color {
            color: #198754;
        }

        .section-title.theme-color {
            color: #198754;
        }

        /* 見出しのテーマカラー */
        .theme-color {
            color: #198754;
        }

        /* セクションタイトル */
        h2.theme-color {
            color: #198754;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <!-- ヘッダーナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white fixed-top shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <h1 class="h4 mb-0 theme-color">LiveShare</h1>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="live-home.php">ホーム</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="live-mypage.php">マイページ</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">ログアウト</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">ログイン</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-primary text-white" href="register.php">新規登録</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ヒーローセクション -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="hero-title">ライブ体験を<br>みんなで共有しよう</h1>
                    <p class="lead mb-4">
                        LiveShareは、ライブ・コンサートの情報共有プラットフォーム。<br>
                        セットリストの予想や、参戦コーデの共有、ライブの感想など、<br>
                        ファン同士で盛り上がりましょう！
                    </p>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <a href="register.php" class="btn btn-primary btn-lg me-3">無料で始める</a>
                        <a href="login.php" class="btn btn-outline-primary btn-lg">ログイン</a>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <img src="img/nainoa-shizuru-NcdG9mK3PBY-unsplash.jpg" class="img-fluid rounded-3" alt="ヒーロー画像">
                </div>
            </div>
        </div>
    </section>

    <!-- 特徴セクション -->
    <section class="container mb-5">
        <h2 class="text-center mb-5 theme-color">LiveShareの特徴</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card feature-card">
                    <div class="card-body text-center">
                        <i class="bi bi-music-note-list feature-icon"></i>
                        <h3 class="h5 mb-3">セットリスト予想</h3>
                        <p class="text-muted">
                            みんなでセットリストを予想して<br>
                            ライブ前から盛り上がろう！
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card">
                    <div class="card-body text-center">
                        <i class="bi bi-people feature-icon"></i>
                        <h3 class="h5 mb-3">ファン同士の交流</h3>
                        <p class="text-muted">
                            同じライブに参加するファンと<br>
                            交流を深めることができます。
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card feature-card">
                    <div class="card-body text-center">
                        <i class="bi bi-camera feature-icon"></i>
                        <h3 class="h5 mb-3">参戦コーデ共有</h3>
                        <p class="text-muted">
                            みんなの参戦コーデを共有して<br>
                            ライブをもっと楽しもう！
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 注目のライブ -->
    <section class="container mb-5">
        <h2 class="text-center mb-5 theme-color">注目のライブ</h2>
        <div class="row g-4">
            <?php foreach ($upcomingLives as $live): ?>
                <div class="col-md-4">
                    <div class="card live-card">
                        <img src="<?= htmlspecialchars($live['image_url'] ? 'uploads/lives/' . $live['image_url'] : 'img/default-live.jpg') ?>" 
                             class="card-img-top" alt="ライブ画像" style="height: 200px; object-fit: cover;">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($live['title']) ?></h5>
                            <p class="card-text">
                                <i class="bi bi-person-fill me-2"></i><?= htmlspecialchars($live['artist_name']) ?><br>
                                <i class="bi bi-geo-alt-fill me-2"></i><?= htmlspecialchars($live['venue_name']) ?><br>
                                <i class="bi bi-calendar3 me-2"></i><?= date('Y/m/d', strtotime($live['date'])) ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <i class="bi bi-bookmark-fill"></i> <?= (int)$live['bookmark_count'] ?>
                                </small>
                                <a href="live-detail.php?id=<?= (int)$live['id'] ?>" class="btn btn-outline-primary btn-sm">
                                    詳細を見る
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 人気のタグ -->
    <section class="container mb-5">
        <h2 class="text-center mb-5 theme-color">人気のタグ</h2>
        <div class="text-center">
            <?php foreach ($popularTags as $tag): ?>
                <a href="live-tag.php?tag=<?= urlencode($tag['name']) ?>" 
                   class="badge rounded-pill tag-badge">
                    #<?= htmlspecialchars($tag['name']) ?>
                    <span class="ms-1"><?= (int)$tag['use_count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- CTA セクション -->
    <section class="cta-section">
        <div class="container text-center">
            <h2 class="mb-4">さあ、始めましょう！</h2>
            <p class="lead mb-4">
                LiveShareで素敵なライブ体験を共有しませんか？<br>
                登録は無料です。
            </p>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="register.php" class="btn btn-light btn-lg me-3">新規登録</a>
                <a href="login.php" class="btn btn-outline-light btn-lg">ログイン</a>
            <?php else: ?>
                <a href="live-home.php" class="btn btn-light btn-lg">ホームへ戻る</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- フッター -->
    <?php include 'footer.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 