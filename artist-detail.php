<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

class ArtistDetailManager {
    private $pdo;
    private $userId;
    private $artistId;
    private $error;
    private $data;
    private $canEdit;

    public function __construct(PDO $pdo, ?int $userId, int $artistId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->artistId = $artistId;
        $this->error = null;
        $this->data = [];
        $this->canEdit = false;
        
        // ログインしているユーザーは編集可能
        if ($userId) {
            $this->canEdit = true;
        }
    }

    public function loadArtistData(): void {
        try {
            // アーティストの基本情報を取得
            $stmt = $this->pdo->prepare("
                SELECT a.*, 
                       COUNT(DISTINCT l.id) as live_count,
                       COUNT(DISTINCT p.id) as performance_count
                FROM artists a
                LEFT JOIN lives l ON a.id = l.artist_id
                LEFT JOIN performances p ON a.id = p.artist_id
                WHERE a.id = ?
                GROUP BY a.id
            ");
            $stmt->execute([$this->artistId]);
            $this->data['artist'] = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$this->data['artist']) {
                throw new Exception('アーティストが見つかりませんでした');
            }

            // 今後のライブ予定を取得
            $stmt = $this->pdo->prepare("
                SELECT l.*, v.name as venue_name
                FROM lives l
                JOIN venues v ON l.venue_id = v.id
                WHERE l.artist_id = ? AND l.date >= CURDATE()
                ORDER BY l.date ASC
                LIMIT 5
            ");
            $stmt->execute([$this->artistId]);
            $this->data['upcoming_lives'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 過去のライブ履歴を取得
            $stmt = $this->pdo->prepare("
                SELECT l.*, v.name as venue_name
                FROM lives l
                JOIN venues v ON l.venue_id = v.id
                WHERE l.artist_id = ? AND l.date < CURDATE()
                ORDER BY l.date DESC
                LIMIT 5
            ");
            $stmt->execute([$this->artistId]);
            $this->data['past_lives'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 人気のセットリストを取得
            $stmt = $this->pdo->prepare("
                SELECT s.*, l.title as live_title, l.date as live_date,
                       COUNT(DISTINCT lk.id) as like_count
                FROM actual_setlists s
                JOIN lives l ON s.live_id = l.id
                LEFT JOIN likes lk ON lk.likeable_id = s.id AND lk.likeable_type = 'setlist'
                WHERE l.artist_id = ?
                GROUP BY s.id
                ORDER BY like_count DESC, l.date DESC
                LIMIT 3
            ");
            $stmt->execute([$this->artistId]);
            $this->data['popular_setlists'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ブックマーク状態の確認
            if ($this->userId) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM bookmarks 
                    WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'artist'
                ");
                $stmt->execute([$this->userId, $this->artistId]);
                $this->data['is_bookmarked'] = (bool)$stmt->fetchColumn();
            } else {
                $this->data['is_bookmarked'] = false;
            }

        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    public function toggleBookmark(): bool {
        if (!$this->userId) return false;

        try {
            $this->pdo->beginTransaction();

            if ($this->data['is_bookmarked']) {
                // ブックマーク解除
                $stmt = $this->pdo->prepare("
                    DELETE FROM bookmarks 
                    WHERE user_id = ? AND bookmarkable_id = ? AND bookmarkable_type = 'artist'
                ");
                $stmt->execute([$this->userId, $this->artistId]);
                $this->data['is_bookmarked'] = false;
            } else {
                // ブックマーク追加
                $stmt = $this->pdo->prepare("
                    INSERT INTO bookmarks (user_id, bookmarkable_id, bookmarkable_type, created_at) 
                    VALUES (?, ?, 'artist', NOW())
                ");
                $stmt->execute([$this->userId, $this->artistId]);
                $this->data['is_bookmarked'] = true;
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }

    public function updateArtist(array $data, ?array $file = null): bool {
        if (!$this->canEdit) return false;

        try {
            if (empty($data['name'])) {
                throw new Exception('アーティスト名を入力してください。');
            }

            $this->pdo->beginTransaction();
            
            // アーティスト情報の更新
            $stmt = $this->pdo->prepare("
                UPDATE artists 
                SET name = ?, description = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $this->artistId
            ]);

            // アーティスト画像の処理
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $this->updateArtistImage($file);
            }

            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->handleError($e);
            return false;
        }
    }

    private function updateArtistImage(array $file): void {
        $uploadDir = 'uploads/artists/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = $this->artistId . '.jpg';
        $uploadFile = $uploadDir . $filename;

        // 画像のバリデーション
        $check = getimagesize($file['tmp_name']);
        if (!$check) {
            throw new Exception('アップロードされたファイルは画像ではありません。');
        }

        // 既存の画像を削除
        if (file_exists($uploadFile)) {
            unlink($uploadFile);
        }

        // 新しい画像を保存
        if (!move_uploaded_file($file['tmp_name'], $uploadFile)) {
            throw new Exception('画像のアップロードに失敗しました。');
        }
    }

    public function getData(): array {
        return $this->data;
    }

    public function getError(): ?string {
        return $this->error;
    }

    public function canEdit(): bool {
        return $this->canEdit;
    }

    private function handleError(Exception $e): void {
        error_log($e->getMessage());
        $this->error = $e->getMessage();
    }
}

// ページの初期化
$artistId = $_GET['id'] ?? null;
if (!$artistId) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$manager = new ArtistDetailManager($pdo, $userId, (int)$artistId);
$manager->loadArtistData();

// ブックマーク処理
if (isset($_POST['toggle_bookmark']) && $userId) {
    $manager->toggleBookmark();
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// アーティスト情報更新処理
if (isset($_POST['update_artist']) && $userId) {
    if ($manager->updateArtist($_POST, $_FILES['artist_image'] ?? null)) {
        $successMessage = 'アーティスト情報を更新しました。';
    } else {
        $errorMessage = $manager->getError() ?? 'アーティスト情報の更新に失敗しました。';
    }
}

// データの取得
$artist = $manager->getData()['artist'] ?? null;
$upcomingLives = $manager->getData()['upcoming_lives'] ?? [];
$pastLives = $manager->getData()['past_lives'] ?? [];
$popularSetlists = $manager->getData()['popular_setlists'] ?? [];
$isBookmarked = $manager->getData()['is_bookmarked'] ?? false;
$canEdit = $manager->canEdit();
$error = $manager->getError();

if (!$artist) {
    header('Location: index.php');
    exit;
}

$pageTitle = htmlspecialchars($artist['name']);
include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- サイドバー -->
        <?php include 'sidebar.php'; ?>
        
        <!-- メインコンテンツ -->
        <div class="col-md-10 ps-md-4">
            <div class="container-fluid px-0 mt-3">
                <?php if (isset($successMessage)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $successMessage ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($errorMessage)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $errorMessage ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- メインコンテンツ（左側） -->
                    <div class="col-lg-8 col-md-7 pe-md-4">
                        <!-- アーティスト情報 -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">アーティスト情報</h5>
                                <div>
                                    <?php if ($userId): ?>
                                        <form method="post" class="d-inline-block me-2">
                                            <button type="submit" name="toggle_bookmark" class="btn btn-sm <?= $isBookmarked ? 'btn-danger' : 'btn-outline-primary' ?>">
                                                <i class="bi <?= $isBookmarked ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                                <?= $isBookmarked ? 'お気に入り解除' : 'お気に入りに追加' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editArtistModal">
                                            <i class="bi bi-pencil"></i> 編集
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 text-center">
                                        <?php
                                        // アーティスト画像があれば表示
                                        $artistImage = 'uploads/artists/' . $artist['id'] . '.jpg';
                                        if (file_exists($artistImage)): 
                                        ?>
                                            <img src="<?= $artistImage ?>?v=<?= time() ?>" alt="<?= htmlspecialchars($artist['name']) ?>" class="img-fluid rounded mb-3" style="max-width: 200px;">
                                        <?php else: ?>
                                            <div class="mb-3">
                                                <i class="bi bi-person-circle" style="font-size: 8rem; color: #6c757d;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <h2 class="mb-3"><?= htmlspecialchars($artist['name']) ?></h2>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6 col-md-3">
                                                <div class="card bg-light">
                                                    <div class="card-body text-center p-2">
                                                        <h5 class="mb-0"><?= (int)$artist['live_count'] ?></h5>
                                                        <small class="text-muted">ライブ数</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <div class="card bg-light">
                                                    <div class="card-body text-center p-2">
                                                        <h5 class="mb-0"><?= (int)$artist['performance_count'] ?></h5>
                                                        <small class="text-muted">出演数</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($artist['description']): ?>
                                            <div class="mt-3">
                                                <h6>アーティスト紹介</h6>
                                                <p><?= nl2br(htmlspecialchars($artist['description'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 今後のライブ予定 -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">今後のライブ予定</h5>
                                <a href="artist-lives.php?id=<?= $artist['id'] ?>&type=upcoming" class="btn btn-sm btn-outline-primary">すべて見る</a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($upcomingLives)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($upcomingLives as $live): ?>
                                            <a href="live-detail.php?id=<?= $live['id'] ?>" class="list-group-item list-group-item-action">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?= htmlspecialchars($live['title']) ?></h6>
                                                    <small><?= date('Y年m月d日', strtotime($live['date'])) ?></small>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($live['venue_name']) ?>
                                                </small>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">今後のライブ予定はありません。</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- 過去のライブ履歴 -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">過去のライブ履歴</h5>
                                <a href="artist-lives.php?id=<?= $artist['id'] ?>&type=past" class="btn btn-sm btn-outline-primary">すべて見る</a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($pastLives)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($pastLives as $live): ?>
                                            <a href="live-detail.php?id=<?= $live['id'] ?>" class="list-group-item list-group-item-action">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?= htmlspecialchars($live['title']) ?></h6>
                                                    <small><?= date('Y年m月d日', strtotime($live['date'])) ?></small>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($live['venue_name']) ?>
                                                </small>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">過去のライブ履歴はありません。</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- サイドバー（右側） -->
                    <div class="col-lg-4 col-md-5">
                        <!-- 人気のセットリスト -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">人気のセットリスト</h5>
                                <a href="artist-setlists.php?id=<?= $artist['id'] ?>" class="btn btn-sm btn-outline-primary">すべて見る</a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($popularSetlists)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($popularSetlists as $setlist): ?>
                                            <a href="setlist-detail.php?id=<?= $setlist['id'] ?>" class="list-group-item list-group-item-action">
                                                <div class="d-flex w-100 justify-content-between">
                                                    <h6 class="mb-1"><?= htmlspecialchars($setlist['live_title']) ?></h6>
                                                    <small><?= date('Y年m月d日', strtotime($setlist['live_date'])) ?></small>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center mt-2">
                                                    <small class="text-muted"><?= $setlist['song_count'] ?? 0 ?>曲</small>
                                                    <small class="text-muted">
                                                        <i class="bi bi-heart-fill text-danger"></i> <?= $setlist['like_count'] ?>
                                                    </small>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">セットリストはまだありません。</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- 関連アーティスト -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">関連アーティスト</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-0">関連アーティスト機能は現在開発中です。</p>
                            </div>
                        </div>
                        
                        <!-- 共有ボタン -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">共有する</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-around">
                                    <a href="https://twitter.com/intent/tweet?text=<?= urlencode($artist['name']) ?>&url=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                                       class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-twitter"></i>
                                    </a>
                                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                                       class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-facebook"></i>
                                    </a>
                                    <a href="https://line.me/R/msg/text/?<?= urlencode($artist['name'] . ' https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                                       class="btn btn-outline-success" target="_blank">
                                        <i class="bi bi-line"></i>
                                    </a>
                                    <button class="btn btn-outline-secondary" onclick="copyToClipboard()">
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- アーティスト編集モーダル -->
<?php if ($canEdit): ?>
<div class="modal fade" id="editArtistModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">アーティスト情報を編集</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">アーティスト名</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($artist['name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">アーティスト紹介</label>
                        <textarea class="form-control" id="description" name="description" rows="5"><?= htmlspecialchars($artist['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="artist_image" class="form-label">アーティスト画像</label>
                        <input type="file" class="form-control" id="artist_image" name="artist_image" accept="image/*">
                        <div class="form-text">JPG、PNG、GIF形式の画像をアップロードできます。推奨サイズは500x500ピクセルです。</div>
                    </div>
                    <?php if (file_exists($artistImage)): ?>
                        <div class="mb-3">
                            <label class="form-label">現在の画像</label>
                            <div>
                                <img src="<?= $artistImage ?>?v=<?= time() ?>" alt="現在の画像" class="img-thumbnail" style="max-width: 200px;">
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" name="update_artist" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// URLをクリップボードにコピーする関数
function copyToClipboard() {
    const url = window.location.href;
    navigator.clipboard.writeText(url).then(() => {
        alert('URLをコピーしました');
    }).catch(err => {
        console.error('コピーに失敗しました:', err);
    });
}
</script>
</body>
</html> 