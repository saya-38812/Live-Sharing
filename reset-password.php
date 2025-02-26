<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

// トークンの検証
try {
    $stmt = $pdo->prepare("
        SELECT user_id 
        FROM password_resets 
        WHERE token = ? AND expires_at > NOW() AND used = 0
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $error = 'このリンクは無効か期限切れです。';
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = '処理中にエラーが発生しました。';
}

// パスワード更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    try {
        if (empty($password)) {
            $error = 'パスワードを入力してください。';
        } elseif ($password !== $password_confirm) {
            $error = 'パスワードが一致しません。';
        } elseif (strlen($password) < 8) {
            $error = 'パスワードは8文字以上で設定してください。';
        } else {
            // パスワードの更新
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                password_hash($password, PASSWORD_DEFAULT),
                $reset['user_id']
            ]);

            // トークンを使用済みにする
            $stmt = $pdo->prepare("
                UPDATE password_resets 
                SET used = 1 
                WHERE token = ?
            ");
            $stmt->execute([$token]);

            $success = 'パスワードを更新しました。';
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $error = 'パスワードの更新に失敗しました。';
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード再設定 - LiveShare</title>
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
        .reset-password-container {
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
    <div class="reset-password-container">
        <div class="logo">
            <h1>LiveShare</h1>
            <p class="text-muted">パスワード再設定</p>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h4 class="card-title mb-4 text-center">新しいパスワードの設定</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <br>
                        <a href="forgot-password.php" class="alert-link">パスワード再設定を再度リクエスト</a>
                    </div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                        <br>
                        <a href="login.php" class="alert-link">ログインページへ</a>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="password" class="form-label">新しいパスワード</label>
                            <input type="password" class="form-control" id="password" name="password" 
                                   required autocomplete="new-password"
                                   pattern=".{8,}" title="8文字以上で入力してください">
                            <div class="form-text">8文字以上で入力してください</div>
                        </div>

                        <div class="mb-4">
                            <label for="password_confirm" class="form-label">新しいパスワード（確認）</label>
                            <input type="password" class="form-control" id="password_confirm" 
                                   name="password_confirm" required autocomplete="new-password">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">パスワードを更新</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 