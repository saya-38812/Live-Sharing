<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

class PostManager {
    private $pdo;
    private $userId;
    private $postId;
    private $error;
    private $data;
    
    public function __construct(PDO $pdo, int $userId, int $postId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->postId = $postId;
        $this->error = null;
        $this->data = [
            'post' => null,
            'comments' => [],
            'relatedThreads' => [],
            'tags' => []
        ];
    }
    
    public function loadPageData(): void {
        try {
            $this->data['post'] = $this->getPostDetails();
            if (!$this->data['post']) {
                header('Location: live-home.php');
                exit;
            }
            $this->data['comments'] = $this->getComments();
            $this->data['relatedThreads'] = $this->getRelatedThreads();
            $this->data['tags'] = $this->getTags();
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function getPostDetails(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT p.*, t.title as thread_title, u.username, u.profile_image,
                   COUNT(DISTINCT l.id) as like_count,
                   COUNT(DISTINCT c.id) as comment_count,
                   EXISTS (
                       SELECT 1 FROM likes 
                       WHERE likeable_id = p.id 
                       AND likeable_type = 'post' 
                       AND user_id = ?
                   ) as is_liked
            FROM posts p
            JOIN threads t ON p.thread_id = t.id
            JOIN users u ON p.user_id = u.id
            LEFT JOIN likes l ON l.likeable_id = p.id AND l.likeable_type = 'post'
            LEFT JOIN posts c ON c.parent_id = p.id
            WHERE p.id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$this->userId, $this->postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    private function getComments(): array {
        $stmt = $this->pdo->prepare("
            SELECT c.*, u.username, u.profile_image,
                   COUNT(l.id) as like_count,
                   EXISTS (
                       SELECT 1 FROM likes 
                       WHERE likeable_id = c.id 
                       AND likeable_type = 'comment' 
                       AND user_id = ?
                   ) as is_liked
            FROM posts c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN likes l ON l.likeable_id = c.id AND l.likeable_type = 'comment'
            WHERE c.parent_id = ?
            GROUP BY c.id
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$this->userId, $this->postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getRelatedThreads(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, COUNT(p.id) as post_count
            FROM threads t
            LEFT JOIN posts p ON t.id = p.thread_id
            WHERE t.board_id = (
                SELECT board_id FROM threads WHERE id = (
                    SELECT thread_id FROM posts WHERE id = ?
                )
            )
            AND t.id != (SELECT thread_id FROM posts WHERE id = ?)
            GROUP BY t.id
            ORDER BY post_count DESC
            LIMIT 5
        ");
        $stmt->execute([$this->postId, $this->postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getTags(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.* 
            FROM tags t
            JOIN taggables tg ON t.id = tg.tag_id
            WHERE tg.taggable_id = ? AND tg.taggable_type = 'post'
        ");
        $stmt->execute([$this->postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function addComment(string $content): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO posts (thread_id, user_id, parent_id, content, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->data['post']['thread_id'],
                $this->userId,
                $this->postId,
                $content
            ]);
            return true;
        } catch (Exception $e) {
            $this->handleError($e);
            return false;
        }
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
}

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 投稿IDの取得
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ページマネージャーの初期化
$postManager = new PostManager($pdo, $_SESSION['user_id'], $postId);

// コメント投稿の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    if ($postManager->addComment($_POST['content'])) {
        header("Location: live-post.php?id=$postId");
        exit;
    }
}

// ページデータの読み込み
$postManager->loadPageData();

// エラーチェック
if ($error = $postManager->getError()) {
    exit($error);
}

// データの取得
extract($postManager->getData());

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($post['thread_title']); ?> - LiveShare</title>
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
        .profile-image {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            aspect-ratio: 1/1;
        }
        .trend-tag {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: #0d6efd;
            border: 1px solid #0d6efd;
            display: inline-block;
            text-decoration: none;
        }
        .reply-form {
            border-top: 1px solid #dee2e6;
            margin-top: 1rem;
            padding-top: 1rem;
        }
        .reply-list {
            margin-top: 2rem;
        }
        .reply-item {
            border-bottom: 1px solid #dee2e6;
            padding: 1rem 0;
        }
        .like-button {
            transition: all 0.2s;
        }
        .like-button.liked {
            color: #dc3545;
            border-color: #dc3545;
        }
        .like-button.liked i {
            color: #dc3545;
        }

        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem;
            }
            .col-md-7, .col-md-10, .main-content {
                padding-bottom: 5rem;
            }
        }

        /* スレッド詳細専用スタイル */
        .post-header {
            padding: 1.5rem;
            margin-bottom: 2rem;
            background-color: rgba(13, 110, 253, 0.1);
            border-bottom: 2px solid #0d6efd;
            border-radius: 8px;
        }

        .post-card {
            border-left: 3px solid #0d6efd;
            transition: transform 0.2s;
        }

        .post-card:hover {
            transform: translateX(5px);
            background-color: rgba(13, 110, 253, 0.1);
        }

        .comment-card {
            border-left: 3px solid #0d6efd;
            margin-bottom: 1rem;
        }

        .comment-card:hover {
            transform: translateX(5px);
            background-color: rgba(13, 110, 253, 0.1);
        }

        .post-tag {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: #0d6efd;
            border: 1px solid #0d6efd;
            cursor: pointer;
            display: inline-block;
        }

        .post-tag:hover {
            background-color: #0d6efd;
            color: white;
            text-decoration: none;
        }

        .reaction-button {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: 1px solid #0d6efd;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .reaction-button:hover {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .reaction-button.active {
            background-color: #0d6efd;
            color: white;
            border-color: #0d6efd;
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
            <!-- スレッドヘッダー -->
            <div class="post-header">
                <div class="d-flex align-items-center mb-3">
                    <img src="<?php echo $post['profile_image'] ? 'uploads/profiles/' . $post['profile_image'] : 'img/default-profile.jpg'; ?>" 
                         class="profile-image me-2" alt="プロフィール画像">
                    <div>
                        <h5 class="mb-0"><?php echo htmlspecialchars($post['username']); ?></h5>
                        <small class="text-muted">
                            <?php echo date('Y/m/d H:i', strtotime($post['created_at'])); ?>
                        </small>
                    </div>
                </div>
                <h4 class="mb-3"><?php echo htmlspecialchars($post['thread_title']); ?></h4>
                <p class="mb-3"><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                <div class="d-flex align-items-center">
                    <button class="btn btn-outline-primary btn-sm like-button me-2 <?php echo $post['is_liked'] ? 'liked' : ''; ?>" 
                            data-post-id="<?php echo $post['id']; ?>">
                        <i class="bi bi-heart<?php echo $post['is_liked'] ? '-fill' : ''; ?>"></i>
                        <span class="like-count"><?php echo $post['like_count']; ?></span>
                    </button>
                    <div class="ms-auto">
                        <?php foreach ($tags as $tag): ?>
                            <a href="live-tag.php?tag=<?php echo urlencode($tag['name']); ?>" 
                               class="badge rounded-pill tag-badge text-decoration-none">
                                #<?php echo htmlspecialchars($tag['name']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- コメント一覧 -->
            <h5 class="mb-3 theme-color">コメント（<?php echo count($comments); ?>）</h5>
            <?php foreach ($comments as $comment): ?>
                <div class="card comment-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <img src="<?php echo $comment['profile_image'] ? 'uploads/profiles/' . $comment['profile_image'] : 'img/default-profile.jpg'; ?>" 
                                 class="profile-image me-2" alt="プロフィール画像">
                            <div>
                                <h6 class="mb-0"><?php echo htmlspecialchars($comment['username']); ?></h6>
                                <small class="text-muted">
                                    <?php echo date('Y/m/d H:i', strtotime($comment['created_at'])); ?>
                                </small>
                            </div>
                        </div>
                        <p class="mb-2"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></p>
                        <button class="btn btn-outline-primary btn-sm like-button <?php echo $comment['is_liked'] ? 'liked' : ''; ?>"
                                data-post-id="<?php echo $comment['id']; ?>">
                            <i class="bi bi-heart<?php echo $comment['is_liked'] ? '-fill' : ''; ?>"></i>
                            <span class="like-count"><?php echo $comment['like_count']; ?></span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- コメント投稿フォーム -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 theme-color">コメントを投稿</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <textarea class="form-control" name="content" rows="3" 
                                      placeholder="コメントを入力..." required></textarea>
                        </div>
                        <button type="submit" name="comment" class="btn btn-primary">投稿する</button>
                    </form>
                </div>
            </div>

            <!-- 右サイドバー -->
            <div class="col-md-3 py-4">
                <!-- 関連スレッド -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3 theme-color">関連スレッド</h5>
                        <div class="list-group list-group-flush">
                            <?php foreach ($relatedThreads as $thread): ?>
                                <a href="thread-detail.php?id=<?php echo $thread['id']; ?>" 
                                   class="list-group-item list-group-item-action">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($thread['title']); ?></h6>
                                    <small class="text-muted"><?php echo $thread['post_count']; ?>件の投稿</small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 人気のタグ -->
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3 theme-color">人気のタグ</h5>
                        <!-- タグリスト -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- いいね機能用のJavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.like-button').forEach(button => {
        button.addEventListener('click', function() {
            const postId = this.dataset.postId;
            const icon = this.querySelector('i');
            const likeCount = this.querySelector('.like-count');
            
            fetch('ajax/toggle-like.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    post_id: postId,
                    type: 'post'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.classList.toggle('liked');
                    icon.classList.toggle('bi-heart');
                    icon.classList.toggle('bi-heart-fill');
                    likeCount.textContent = data.likes;
                }
            });
        });
    });
});
</script>
</body>
</html> 