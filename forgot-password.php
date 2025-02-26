<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    try {
        // メールアドレスの存在確認
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // リセットトークンの生成
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // トークンをデータベースに保存
            $stmt = $pdo->prepare("
                INSERT INTO password_resets (user_id, token, expires_at) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user['id'], $token, $expires]);

            // 実際のアプリケーションではここでメール送信処理を行う
            // 今回はデモ用にトークンを表示
            $resetLink = "http://{$_SERVER['HTTP_HOST']}/reset-password.php?token=" . $token;
            $success = "パスワードリセットリンクを送信しました。（デモ用リンク: <a href='{$resetLink}'>{$resetLink}</a>）";

        } else {
            $error = 'このメールアドレスは登録されていません。';
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $error = '処理中にエラーが発生しました。';
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワードを忘れた方 - LiveShare</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js" defer></script>
    <style>
        body {
            background-color: var(--theme-bg-light);
        }
        .forgot-password-container {
            max-width: 400px;
            margin: 100px auto;
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo h1 {
            color: var(--theme-color);
            font-size: 2.5rem;
            font-weight: bold;
        }
        .card {
            border: none;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="forgot-password-container">
        <div class="logo">
            <h1>LiveShare</h1>
            <p class="text-muted">パスワード再設定</p>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h4 class="card-title mb-4 text-center">パスワードをお忘れの方</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo $success; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-4">
                        登録したメールアドレスを入力してください。<br>
                        パスワード再設定用のリンクをお送りします。
                    </p>

                    <form method="POST" action="forgot-password.php">
                        <div class="mb-4">
                            <label for="email" class="form-label">メールアドレス</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   required autocomplete="email">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">送信する</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <a href="login.php" class="text-decoration-none">
                        <i class="bi bi-arrow-left"></i> ログインページに戻る
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 