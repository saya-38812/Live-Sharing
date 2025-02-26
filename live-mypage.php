<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class MyPageManager {
    private $pdo;
    private $userId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, int $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->error = null;
        $this->data = [
            'user' => null,
            'followerCount' => 0,
            'followingCount' => 0,
            'recentPosts' => [],
            'savedLives' => [],
            'fashionPosts' => [],
            'bookmarkedLives' => [],
            'upcomingLives' => [],
            'bookmarkedArtists' => [],
            'bookmarkedFestivals' => [],
            'bookmarkedThreads' => []
        ];
    }
    
    public function loadPageData(): void {
        try {
            $this->data['user'] = $this->getUserInfo();
            if (!$this->data['user']) {
                throw new Exception('ユーザー情報が見つかりません。');
            }
            
            $this->data['followerCount'] = $this->getFollowerCount();
            $this->data['followingCount'] = $this->getFollowingCount();
            $this->data['recentPosts'] = $this->getRecentPosts();
            $this->data['savedLives'] = $this->getSavedLives();
            $this->data['fashionPosts'] = $this->getFashionPosts();
            $this->data['bookmarkedLives'] = $this->getBookmarkedLives();
            $this->data['upcomingLives'] = $this->getUpcomingLives();
            $this->data['bookmarkedArtists'] = $this->getBookmarkedArtists();
            $this->data['bookmarkedFestivals'] = $this->getBookmarkedFestivals();
            $this->data['bookmarkedThreads'] = $this->getBookmarkedThreads();
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    public function updateProfile(array $data, ?array $file = null): bool {
        try {
            if (empty($data['username'])) {
                throw new Exception('ユーザー名を入力してください。');
            }

            $this->pdo->beginTransaction();
            
            // プロフィール情報の更新
            $this->updateUserInfo($data);
            
            // プロフィール画像の処理
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $this->updateProfileImage($file);
            }

            $this->pdo->commit();
            $_SESSION['success_message'] = 'プロフィールを更新しました。';
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }
    
    private function getUserInfo(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT id, username, email, profile_image, bio 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getFollowerCount(): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM follows WHERE followed_id = ?
        ");
        $stmt->execute([$this->userId]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getFollowingCount(): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM follows WHERE follower_id = ?
        ");
        $stmt->execute([$this->userId]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getRecentPosts(): array {
        $stmt = $this->pdo->prepare("
            SELECT p.*, l.title as live_title, l.date as live_date,
                   COUNT(DISTINCT lk.id) as like_count,
                   COUNT(DISTINCT c.id) as comment_count
            FROM posts p
            LEFT JOIN lives l ON p.live_id = l.id
            LEFT JOIN likes lk ON lk.likeable_id = p.id AND lk.likeable_type = 'post'
            LEFT JOIN comments c ON c.post_id = p.id
            WHERE p.user_id = ?
            GROUP BY p.id
            ORDER BY p.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getSavedLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, v.name as venue_name, a.name as artist_name,
                   b.created_at as saved_at
            FROM lives l
            JOIN bookmarks b ON b.bookmarkable_id = l.id AND b.bookmarkable_type = 'live'
            LEFT JOIN venues v ON l.venue_id = v.id
            LEFT JOIN artists a ON l.artist_id = a.id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getFashionPosts(): array {
        $stmt = $this->pdo->prepare("
            SELECT fp.*, l.title as live_title
            FROM fashion_posts fp
            JOIN lives l ON fp.live_id = l.id
            WHERE fp.user_id = ?
            ORDER BY fp.created_at DESC
            LIMIT 4
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll();
    }
    
    private function getBookmarkedLives(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, v.name as venue_name, a.name as artist_name,
                   b.created_at as saved_at
            FROM lives l
            JOIN bookmarks b ON b.bookmarkable_id = l.id AND b.bookmarkable_type = 'live'
            LEFT JOIN venues v ON l.venue_id = v.id
            LEFT JOIN artists a ON l.artist_id = a.id
            WHERE b.user_id = ? AND l.event_type_id != 2 /* フェスティバル以外 */
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getUpcomingLives(): array {
        // Implementation needed
        return [];
    }
    
    private function getBookmarkedArtists(): array {
        $stmt = $this->pdo->prepare("
            SELECT a.*, b.created_at as saved_at
            FROM artists a
            JOIN bookmarks b ON b.bookmarkable_id = a.id 
            WHERE b.user_id = ?
            AND b.bookmarkable_type = 'artist'
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$this->userId]);
        $artists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 画像URLの処理を追加
        foreach ($artists as &$artist) {
            // 画像の存在確認
            $imagePath = __DIR__ . '/uploads/artists/' . $artist['id'] . '.jpg';
            if (file_exists($imagePath)) {
                $artist['image_url'] = 'uploads/artists/' . $artist['id'] . '.jpg';
            } else {
                $artist['image_url'] = 'img/default-artist.jpg';
            }
        }

        return $artists;
    }
    
    private function getBookmarkedFestivals(): array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, v.name as venue_name,
                   b.created_at as saved_at
            FROM lives l
            JOIN bookmarks b ON b.bookmarkable_id = l.id AND b.bookmarkable_type = 'live'
            LEFT JOIN venues v ON l.venue_id = v.id
            WHERE b.user_id = ? AND l.event_type_id = 2 /* フェスティバルのみ */
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getBookmarkedThreads(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, 
                   l.title as live_title, 
                   l.date as live_date,
                   b.created_at as saved_at,
                   COUNT(DISTINCT p.id) as post_count,
                   t.content as description,
                   u.username as created_by
            FROM threads t
            JOIN bookmarks b ON b.bookmarkable_id = t.id AND b.bookmarkable_type = 'thread'
            LEFT JOIN lives l ON t.live_id = l.id
            LEFT JOIN posts p ON p.thread_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE b.user_id = ?
            GROUP BY t.id, l.id, u.id
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function updateUserInfo(array $data): void {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET bio = ?, username = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$data['bio'], $data['username'], $this->userId]);
    }
    
    private function updateProfileImage(array $file): void {
        $uploadDir = 'uploads/profiles/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            throw new Exception('許可されていないファイル形式です。');
        }

        $fileName = uniqid() . '.' . $fileExtension;
        $uploadFile = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
            // 古い画像の削除と新しい画像の登録
            $this->deleteOldProfileImage();
            $this->saveNewProfileImage($fileName);
        }
    }
    
    private function deleteOldProfileImage(): void {
        $stmt = $this->pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmt->execute([$this->userId]);
        $oldImage = $stmt->fetchColumn();
        
        if ($oldImage && file_exists('uploads/profiles/' . $oldImage)) {
            unlink('uploads/profiles/' . $oldImage);
        }
    }
    
    private function saveNewProfileImage(string $fileName): void {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET profile_image = ?
            WHERE id = ?
        ");
        $stmt->execute([$fileName, $this->userId]);
    }
    
    private function handleError(Exception $e): void {
        if ($e instanceof PDOException) {
            error_log('Database Error: ' . $e->getMessage());
            $this->error = 'データベースエラーが発生しました。';
        } else {
            error_log('Error: ' . $e->getMessage());
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

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ページマネージャーの初期化
$pageManager = new MyPageManager($pdo, $_SESSION['user_id']);

// プロフィール更新の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if ($pageManager->updateProfile($_POST, $_FILES['profile_image'] ?? null)) {
        header('Location: live-mypage.php');
        exit;
    }
}

// ページデータの読み込み
$pageManager->loadPageData();

// データの取得
$error_message = $pageManager->getError();
extract($pageManager->getData());

// ヘッダーを含める
$pageTitle = 'マイページ';
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>

        <!-- メインコンテンツ -->
        <div class="col-md-7 py-4">
            <!-- プロフィール情報 -->
            <?php if ($user): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <img src="<?php echo $user['profile_image'] ? 'uploads/profiles/' . $user['profile_image'] : 'img/default-profile.jpg'; ?>" 
                                 class="rounded-circle me-3" 
                                 style="width: 100px; height: 100px; object-fit: cover;" 
                                 alt="プロフィール画像">
                            <div>
                                <h3 class="mb-1"><?php echo h($user['username']); ?></h3>
                                <p class="text-muted mb-2">
                                    フォロワー: <?php echo $followerCount; ?> | 
                                    フォロー中: <?php echo $followingCount; ?>
                                </p>
                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                                    プロフィールを編集
                                </button>
                            </div>
                        </div>
                        <p class="mb-0"><?php echo nl2br(h($user['bio'] ?? '')); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- お気に入り一覧 (アコーディオン + 水平タブ) -->
            <div class="card mb-4">
                <div class="card-body">
                    <h4 class="section-title">お気に入り</h4>
                    <div class="accordion" id="favoritesAccordion">
                        <!-- アーティスト -->
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="artistsHeading">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#artistsCollapse" aria-expanded="true" aria-controls="artistsCollapse">
                                    <i class="bi bi-music-note-beamed me-2"></i> アーティスト
                                    <span class="badge bg-success ms-2"><?= count($bookmarkedArtists) ?></span>
                                </button>
                            </h2>
                            <div id="artistsCollapse" class="accordion-collapse collapse show" aria-labelledby="artistsHeading" data-bs-parent="#favoritesAccordion">
                                <div class="accordion-body">
                                    <?php if (!empty($bookmarkedArtists)): ?>
                                        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
                                            <?php foreach ($bookmarkedArtists as $artist): ?>
                                                <div class="col">
                                                    <div class="card h-100 border-0 shadow-sm">
                                                        <div class="d-flex align-items-center p-2">
                                                            <a href="artist-detail.php?id=<?= $artist['id'] ?>">
                                                                <img src="<?= h($artist['image_url']) ?>" 
                                                                     class="rounded-circle me-2" 
                                                                     alt="<?= htmlspecialchars($artist['name']) ?>"
                                                                     style="height: 50px; width: 50px; object-fit: cover;">
                                                            </a>
                                                            <h6 class="card-title mb-0">
                                                                <a href="artist-detail.php?id=<?= $artist['id'] ?>" class="text-decoration-none text-success">
                                                                    <?= htmlspecialchars($artist['name']) ?>
                                                                </a>
                                                            </h6>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">お気に入りのアーティストはまだありません。</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ライブ -->
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="livesHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#livesCollapse" aria-expanded="false" aria-controls="livesCollapse">
                                    <i class="bi bi-calendar-event me-2"></i> ライブ
                                    <span class="badge bg-success ms-2"><?= count($bookmarkedLives) ?></span>
                                </button>
                            </h2>
                            <div id="livesCollapse" class="accordion-collapse collapse" aria-labelledby="livesHeading" data-bs-parent="#favoritesAccordion">
                                <div class="accordion-body">
                                    <?php if (!empty($bookmarkedLives)): ?>
                                        <div class="row">
                                            <?php foreach ($bookmarkedLives as $live): ?>
                                                <div class="col-md-6 mb-3">
                                                    <div class="card h-100 border-0 shadow-sm">
                                                        <div class="card-body">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="bg-light rounded-circle p-2 me-3">
                                                                    <i class="bi bi-music-note-beamed text-success"></i>
                                                                </div>
                                                                <h6 class="card-title mb-0">
                                                                    <a href="live-detail.php?id=<?= $live['id'] ?>" class="text-decoration-none text-success">
                                                                        <?= htmlspecialchars($live['title']) ?>
                                                                    </a>
                                                                </h6>
                                                            </div>
                                                            <p class="card-text small text-muted">
                                                                <i class="bi bi-calendar-event"></i> <?= date('Y年m月d日', strtotime($live['date'])) ?>
                                                                <?php if (!empty($live['artist_name'])): ?>
                                                                    <br><i class="bi bi-person"></i> <?= htmlspecialchars($live['artist_name']) ?>
                                                                <?php endif; ?>
                                                                <?php if (!empty($live['venue_name'])): ?>
                                                                    <br><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($live['venue_name']) ?>
                                                                <?php endif; ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">お気に入りのライブはまだありません。</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- フェス -->
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="festivalsHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#festivalsCollapse" aria-expanded="false" aria-controls="festivalsCollapse">
                                    <i class="bi bi-flag me-2"></i> フェス
                                    <span class="badge bg-success ms-2"><?= count($bookmarkedFestivals) ?></span>
                                </button>
                            </h2>
                            <div id="festivalsCollapse" class="accordion-collapse collapse" aria-labelledby="festivalsHeading" data-bs-parent="#favoritesAccordion">
                                <div class="accordion-body">
                                    <?php if (!empty($bookmarkedFestivals)): ?>
                                        <div class="row">
                                            <?php foreach ($bookmarkedFestivals as $festival): ?>
                                                <div class="col-md-6 mb-3">
                                                    <div class="card h-100 border-0 shadow-sm">
                                                        <div class="card-body">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="bg-light rounded-circle p-2 me-3">
                                                                    <i class="bi bi-flag text-success"></i>
                                                                </div>
                                                                <h6 class="card-title mb-0">
                                                                    <a href="live-detail.php?id=<?= $festival['id'] ?>" class="text-decoration-none text-success">
                                                                        <?= htmlspecialchars($festival['title']) ?>
                                                                    </a>
                                                                </h6>
                                                            </div>
                                                            <p class="card-text small text-muted">
                                                                <i class="bi bi-calendar-event"></i> <?= date('Y年m月d日', strtotime($festival['date'])) ?>
                                                                <?php if (!empty($festival['venue_name'])): ?>
                                                                    <br><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($festival['venue_name']) ?>
                                                                <?php endif; ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">お気に入りのフェスはまだありません。</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- スレッド -->
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="threadsHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#threadsCollapse" aria-expanded="false" aria-controls="threadsCollapse">
                                    <i class="bi bi-chat-dots me-2"></i> スレッド
                                    <span class="badge bg-success ms-2"><?= count($bookmarkedThreads) ?></span>
                                </button>
                            </h2>
                            <div id="threadsCollapse" class="accordion-collapse collapse" aria-labelledby="threadsHeading" data-bs-parent="#favoritesAccordion">
                                <div class="accordion-body">
                                    <?php if (!empty($bookmarkedThreads)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($bookmarkedThreads as $thread): ?>
                                                <a href="thread-detail.php?id=<?= $thread['id'] ?>" class="list-group-item list-group-item-action border-0 mb-2 rounded shadow-sm">
                                                    <div class="d-flex w-100 justify-content-between">
                                                        <h6 class="mb-1 text-success"><?= htmlspecialchars($thread['title']) ?></h6>
                                                        <small class="text-muted"><?= date('Y/m/d', strtotime($thread['created_at'])) ?></small>
                                                    </div>
                                                    <p class="mb-1 small text-truncate"><?= htmlspecialchars($thread['description']) ?></p>
                                                    <small class="text-muted">
                                                        <i class="bi bi-chat-dots"></i> <?= $thread['post_count'] ?> 件の投稿
                                                    </small>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">お気に入りのスレッドはまだありません。</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 最近の投稿 -->
            <div class="card mb-4">
                <div class="card-body">
                    <h4 class="section-title">最近の投稿</h4>
                    <a href="live-user-posts.php?user_id=<?= $_SESSION['user_id'] ?>" class="btn btn-outline-primary btn-sm">
                        すべての投稿を見る
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentPosts)): ?>
                        <?php foreach ($recentPosts as $post): ?>
                            <div class="post-card mb-3 board-card" onclick="location.href='live-post-detail.php?id=<?= $post['id'] ?>'" style="cursor: pointer;">
                                <div class="d-flex align-items-center mb-2">
                                    <div>
                                        <h6 class="mb-0">
                                            <?php if ($post['live_title']): ?>
                                                <a href="live-detail.php?id=<?= $post['live_id'] ?>" class="text-decoration-none">
                                                    <?= htmlspecialchars($post['live_title']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?= time_ago($post['created_at']) ?>
                                            <?php if ($post['live_date']): ?>
                                                · <?= date('Y/m/d', strtotime($post['live_date'])) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <p class="card-text"><?= nl2br(htmlspecialchars($post['content'])) ?></p>
                                <div class="d-flex gap-3">
                                    <small class="text-muted">
                                        <i class="bi bi-heart"></i> <?= number_format($post['like_count']) ?>
                                    </small>
                                    <small class="text-muted">
                                        <i class="bi bi-chat"></i> <?= number_format($post['comment_count']) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">まだ投稿がありません。</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 保存したライブ
            <h4 class="section-title mt-4">保存したライブ</h4>
            <div class="row">
                <?php foreach ($savedLives as $live): ?>
                    <div class="col-md-6 mb-3">
                        <div class="card saved-live-card h-100" onclick="location.href='live-detail.php?id=<?= $live['id'] ?>'" style="cursor: pointer;">
                            <img src="<?php echo $live['image_url'] ? 'uploads/lives/' . $live['image_url'] : 'img/nainoa-shizuru-NcdG9mK3PBY-unsplash.jpg'; ?>" 
                                 class="card-img-top" 
                                 style="height: 150px; object-fit: cover;" 
                                 alt="ライブ画像">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($live['title']); ?></h5>
                                <p class="card-text">
                                    <i class="bi bi-calendar3"></i> 
                                    <?php echo date('Y/m/d', strtotime($live['date'])); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div> -->

            <!-- 参戦コーデ -->
            <div class="card mb-4">
                <div class="card-body">
                    <h4 class="section-title">参戦コーデ</h4>
                    <div class="row">
                        <?php foreach ($fashionPosts as $post): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card fashion-post-card h-100 board-card">
                                    <img src="uploads/fashion/<?php echo $post['image_url']; ?>" 
                                         class="card-img-top" 
                                         style="height: 200px; object-fit: cover;" 
                                         alt="参戦コーデ">
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($post['live_title']); ?></h6>
                                        <p class="card-text small">
                                            <?php echo nl2br(htmlspecialchars($post['description'])); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 右サイドバー -->
        <div class="col-md-3 py-4">
            <!-- テーマ設定 -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">
                        <i class="bi bi-gear-fill me-2"></i>テーマ設定
                    </h5>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        テーマカラー機能は現在開発中です。もうしばらくお待ちください。
                    </div>
                </div>
            </div>

            <!-- 通知 -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">通知</h5>
                    <div class="list-group list-group-flush">
                        <!-- 通知アイテム -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- プロフィール編集モーダル -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">プロフィールを編集</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="live-mypage.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ユーザー名</label>
                        <input type="text" class="form-control" name="username" 
                               value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">プロフィール画像</label>
                        <input type="file" class="form-control" name="profile_image" accept="image/*">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">自己紹介</label>
                        <textarea class="form-control" name="bio" rows="4"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" name="update_profile" class="btn btn-primary">更新する</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 必要に応じてJavaScriptコードを追加
});
</script>

<!-- エラーメッセージの表示 -->
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo h($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- 成功メッセージの表示 -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo h($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<style>
/* カードのホバーエフェクト */
.board-card {
    transition: transform 0.2s, box-shadow 0.2s, background-color 0.2s;
}

.board-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(var(--theme-color-rgb), 0.1);
    background-color: var(--theme-bg-light);
}

/* カード内の画像ホバーエフェクト */
.card img {
    transition: opacity 0.2s;
}

.card:hover img {
    opacity: 0.9;
}
</style>
</body>
</html> 