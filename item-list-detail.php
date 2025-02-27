<?php
require_once 'config/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// セッションチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// IDの取得と検証
$itemListId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$itemListId) {
    header('Location: live-board-items.php');
    exit;
}

try {
    // 持ち物リストの詳細情報を取得
    $stmt = $pdo->prepare("
        SELECT il.*, u.username, u.profile_image,
               l.title as live_title, l.date as live_date,
               COUNT(DISTINCT lk.id) as like_count,
               EXISTS (
                   SELECT 1 FROM likes 
                   WHERE likeable_id = il.id 
                   AND likeable_type = 'item_list' 
                   AND user_id = ?
               ) as is_liked
        FROM item_lists il
        JOIN users u ON il.user_id = u.id
        JOIN lives l ON il.live_id = l.id
        LEFT JOIN likes lk ON lk.likeable_id = il.id AND lk.likeable_type = 'item_list'
        WHERE il.id = ?
        GROUP BY il.id
    ");
    $stmt->execute([$_SESSION['user_id'], $itemListId]);
    $itemList = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$itemList) {
        throw new Exception('持ち物リストが見つかりません。');
    }

    // タグを取得
    $stmt = $pdo->prepare("
        SELECT t.name
        FROM tags t
        JOIN taggables tg ON t.id = tg.tag_id
        WHERE tg.taggable_id = ? AND tg.taggable_type = 'item_list'
    ");
    $stmt->execute([$itemListId]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // アイテム一覧を取得（追加）
    $stmt = $pdo->prepare("
        SELECT *
        FROM items
        WHERE item_list_id = ?
        ORDER BY category, created_at DESC
    ");
    $stmt->execute([$itemListId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: live-board-items.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($itemList['title']) ?> - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <style>
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
        .tag-badge {
            font-size: 0.9rem;
            padding: 0.3rem 0.8rem;
            margin: 0.2rem;
            border-radius: 20px;
            background-color: #f8f9fa;
            color: var(--theme-color);
            border: 1px solid var(--theme-color);
        }
        .item-list {
            margin-top: 1rem;
        }
        
        .modal-body .form-label {
            font-weight: 500;
        }
        
        .text-danger {
            color: #dc3545;
        }

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

        @media (max-width: 767.98px) {
            body {
                padding-bottom: 4rem;
            }
            .col-md-7, .col-md-10, .main-content {
                padding-bottom: 5rem;
            }
        }

        .list-group-item {
            border-left: none;
            border-right: none;
            border-radius: 0 !important;
        }

        .list-group-item:first-child {
            border-top: none;
        }

        .list-group-item:last-child {
            border-bottom: none;
        }

        .badge {
            font-weight: normal;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- サイドバー -->
            <?php include 'sidebar.php'; ?>

            <!-- メインコンテンツ -->
            <div class="col-md-7 py-4">
                <!-- 成功メッセージの表示 -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= h($_SESSION['success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <!-- 持ち物リストの詳細 -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h2 class="card-title mb-1"><?= h($itemList['title']) ?></h2>
                                <p class="text-muted mb-0">
                                    <a href="live-detail.php?id=<?= $itemList['live_id'] ?>" class="text-decoration-none">
                                        <?= h($itemList['live_title']) ?>
                                    </a>
                                    (<?= date('Y/m/d', strtotime($itemList['live_date'])) ?>)
                                </p>
                            </div>
                            <button class="btn btn-link text-decoration-none like-button <?= $itemList['is_liked'] ? 'liked' : '' ?>"
                                    data-id="<?= $itemList['id'] ?>" data-type="item_list">
                                <i class="bi bi-heart<?= $itemList['is_liked'] ? '-fill' : '' ?>"></i>
                                <span class="like-count"><?= $itemList['like_count'] ?></span>
                            </button>
                        </div>

                        <!-- 作成者情報 -->
                        <div class="d-flex align-items-center mb-3">
                            <img src="<?= empty($itemList['profile_image']) ? 'img/default-profile.jpg' : 'uploads/profiles/' . h($itemList['profile_image']) ?>" 
                                 class="rounded-circle me-2" width="32" height="32" alt="Profile">
                            <span><?= h($itemList['username']) ?></span>
                            <span class="ms-2 text-muted">
                                作成日: <?= date('Y/m/d', strtotime($itemList['created_at'])) ?>
                            </span>
                        </div>

                        <!-- 説明文 -->
                        <div class="d-flex justify-content-between align-items-start">
                            <?php if (!empty($itemList['description'])): ?>
                                <p class="card-text mb-0"><?= nl2br(h($itemList['description'])) ?></p>
                            <?php else: ?>
                                <p class="card-text text-muted mb-0">説明文がありません</p>
                            <?php endif; ?>
                            
                            <?php if ($itemList['user_id'] === $_SESSION['user_id']): ?>
                                <button class="btn btn-link btn-sm text-muted" onclick="editDescription()">
                                    <i class="bi bi-pencil"></i> 編集
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- タグ -->
                        <?php if (!empty($tags)): ?>
                            <div class="mt-3">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="tag-badge">#<?= h($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- アイテム一覧 -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="card-title h5 mb-0">アイテム一覧</h3>
                            <?php if ($itemList['user_id'] === $_SESSION['user_id']): ?>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                    <i class="bi bi-plus-lg me-1"></i>アイテムを追加
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="item-list">
                            <?php if (!empty($items)): ?>
                                <!-- カテゴリーごとにグループ化 -->
                                <?php
                                $groupedItems = [];
                                foreach ($items as $item) {
                                    $category = $item['category'] ?: '未分類';
                                    $groupedItems[$category][] = $item;
                                }
                                ?>
                                
                                <?php foreach ($groupedItems as $category => $categoryItems): ?>
                                    <div class="mb-4">
                                        <h4 class="h6 mb-3">
                                            <?php if ($category === '必須'): ?>
                                                <span class="badge bg-danger">必須</span>
                                            <?php elseif ($category === 'あると便利'): ?>
                                                <span class="badge bg-success">あると便利</span>
                                            <?php elseif ($category === 'オプション'): ?>
                                                <span class="badge bg-info">オプション</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">未分類</span>
                                            <?php endif; ?>
                                        </h4>
                                        <div class="list-group">
                                            <?php foreach ($categoryItems as $item): ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <h5 class="mb-1"><?= h($item['name']) ?></h5>
                                                            <?php if (!empty($item['description'])): ?>
                                                                <p class="mb-1 text-muted small">
                                                                    <?= nl2br(h($item['description'])) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($itemList['user_id'] === $_SESSION['user_id']): ?>
                                                            <div class="btn-group">
                                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                                        onclick="editItem(<?= $item['id'] ?>)">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                                        onclick="deleteItem(<?= $item['id'] ?>)">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">
                                    <?php if ($itemList['user_id'] === $_SESSION['user_id']): ?>
                                        まだアイテムが追加されていません。「アイテムを追加」ボタンからアイテムを追加してください。
                                    <?php else: ?>
                                        まだアイテムが追加されていません。
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右サイドバー -->
            <div class="col-md-3 py-4">
                <!-- 作成者情報カード -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3 theme-color">作成者</h5>
                        <div class="d-flex align-items-center">
                            <img src="<?= empty($itemList['profile_image']) ? 'img/default-profile.jpg' : 'uploads/profiles/' . h($itemList['profile_image']) ?>" 
                                 class="rounded-circle me-2" width="40" height="40" alt="Profile">
                            <div>
                                <div class="fw-bold"><?= h($itemList['username']) ?></div>
                                <small class="text-muted">
                                    作成日: <?= date('Y/m/d', strtotime($itemList['created_at'])) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- タグ一覧 -->
                <?php if (!empty($tags)): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3 theme-color">タグ一覧</h5>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($tags as $tag): ?>
                                <a href="live-board-items.php?tag=<?= urlencode($tag) ?>" 
                                   class="badge rounded-pill text-bg-light text-decoration-none">
                                    #<?= h($tag) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ヒントカード -->
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3 theme-color">アイテム追加のヒント</h5>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                                カテゴリーを設定しよう
                            </li>
                            <li class="mb-2">
                                <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                                メモを活用しよう
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- アイテム追加モーダル -->
    <div class="modal fade" id="addItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">アイテムを追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="add-item.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="item_list_id" value="<?= (int)$itemList['id'] ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">アイテム名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">カテゴリー</label>
                            <select class="form-select" name="category">
                                <option value="">選択してください</option>
                                <option value="必須">必須</option>
                                <option value="あると便利">あると便利</option>
                                <option value="オプション">オプション</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">メモ</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_public" value="1" id="isPublic" checked>
                            <label class="form-check-label" for="isPublic">
                                このアイテムを公開する
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">追加する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- アイテム編集モーダル -->
    <div class="modal fade" id="editItemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">アイテムを編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="edit-item.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="item_id" id="edit_item_id">
                    <input type="hidden" name="item_list_id" value="<?= (int)$itemList['id'] ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">アイテム名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">カテゴリー</label>
                            <select class="form-select" name="category" id="edit_category">
                                <option value="">選択してください</option>
                                <option value="必須">必須</option>
                                <option value="あると便利">あると便利</option>
                                <option value="オプション">オプション</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">メモ</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_public" value="1" id="edit_is_public">
                            <label class="form-check-label" for="edit_is_public">
                                このアイテムを公開する
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">更新する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 説明文編集モーダル -->
    <div class="modal fade" id="editDescriptionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">説明文を編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="edit-description.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="item_list_id" value="<?= (int)$itemList['id'] ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">説明文</label>
                            <textarea class="form-control" name="description" rows="4"><?= h($itemList['description']) ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">更新する</button>
                    </div>
                </form>
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
                const id = this.dataset.id;
                const type = this.dataset.type;
                const icon = this.querySelector('i');
                const likeCount = this.querySelector('.like-count');
                
                fetch('ajax/toggle-like.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: id,
                        type: type
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // いいねの状態を更新
                        if (data.isLiked) {
                            this.classList.add('liked');
                            icon.classList.remove('bi-heart');
                            icon.classList.add('bi-heart-fill');
                        } else {
                            this.classList.remove('liked');
                            icon.classList.remove('bi-heart-fill');
                            icon.classList.add('bi-heart');
                        }
                        // いいね数を更新
                        likeCount.textContent = data.likes;
                    } else {
                        alert('いいねの処理に失敗しました: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('いいねの処理中にエラーが発生しました。');
                });
            });
        });
    });
    </script>

    <!-- 編集用のJavaScript -->
    <script>
    // アイテム編集用の関数
    function editItem(itemId) {
        // アイテムのデータを取得
        fetch(`get-item.php?id=${itemId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const item = data.item;
                    // フォームに値を設定
                    document.getElementById('edit_item_id').value = item.id;
                    document.getElementById('edit_name').value = item.name;
                    document.getElementById('edit_category').value = item.category;
                    document.getElementById('edit_description').value = item.description;
                    document.getElementById('edit_is_public').checked = item.is_public == 1;
                    
                    // モーダルを表示
                    new bootstrap.Modal(document.getElementById('editItemModal')).show();
                } else {
                    alert('アイテムの取得に失敗しました: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('アイテムの取得中にエラーが発生しました。');
            });
    }

    // アイテム削除用の関数
    function deleteItem(itemId) {
        if (confirm('このアイテムを削除してもよろしいですか？')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'delete-item.php';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= $_SESSION['csrf_token'] ?>';
            
            const itemIdInput = document.createElement('input');
            itemIdInput.type = 'hidden';
            itemIdInput.name = 'item_id';
            itemIdInput.value = itemId;
            
            form.appendChild(csrfInput);
            form.appendChild(itemIdInput);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function editDescription() {
        new bootstrap.Modal(document.getElementById('editDescriptionModal')).show();
    }
    </script>
</body>
</html> 