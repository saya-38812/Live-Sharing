<?php
// セッション開始
session_start();

// すでにログインしている場合はマイページにリダイレクト
if (isset($_SESSION['user_id'])) {
    header('Location: live-mypage.php');
    exit;
}

// データベース接続
require_once 'config/database.php';

$error = '';
$success = '';

// 新規登録処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    try {
        // 入力値の検証
        if (empty($username) || empty($email) || empty($password)) {
            $error = '全ての項目を入力してください。';
        } elseif ($password !== $password_confirm) {
            $error = 'パスワードが一致しません。';
        } elseif (strlen($password) < 8) {
            $error = 'パスワードは8文字以上で設定してください。';
        } else {
            // メールアドレスの重複チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'このメールアドレスは既に登録されています。';
            } else {
                // ユーザー登録
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $username,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT)
                ]);

                $success = '登録が完了しました。ログインしてください。';
            }
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $error = '登録処理中にエラーが発生しました。';
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規登録 - LiveShare</title>
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
        .register-container {
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
        .form-control:focus {
            border-color: var(--theme-color);
            box-shadow: 0 0 0 0.2rem rgba(var(--theme-color-rgb), 0.25);
        }
        .btn-primary {
            background-color: var(--theme-color);
            border-color: var(--theme-color);
        }
        .btn-primary:hover {
            background-color: var(--theme-color-dark);
            border-color: var(--theme-color-dark);
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <h1>LiveShare</h1>
            <p class="text-muted">ライブ情報共有プラットフォーム</p>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h4 class="card-title mb-4 text-center">新規登録</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                        <br>
                        <a href="login.php" class="alert-link">ログインページへ</a>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php">
                    <div class="mb-3">
                        <label for="username" class="form-label">ユーザー名</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               required autocomplete="username">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">メールアドレス</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               required autocomplete="email">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">パスワード</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               required autocomplete="new-password"
                               pattern=".{8,}" title="8文字以上で入力してください">
                        <div class="form-text">8文字以上で入力してください</div>
                    </div>

                    <div class="mb-4">
                        <label for="password_confirm" class="form-label">パスワード（確認）</label>
                        <input type="password" class="form-control" id="password_confirm" 
                               name="password_confirm" required autocomplete="new-password">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">登録する</button>
                    </div>
                </form>

                <div class="text-center mt-4">
                    <p class="mb-0">すでにアカウントをお持ちの方は</p>
                    <a href="login.php" class="text-decoration-none">ログイン</a>
                </div>
            </div>
        </div>

        <div class="text-center mt-3">
            <a href="index.php" class="text-muted text-decoration-none">
                <i class="bi bi-arrow-left"></i> トップページに戻る
            </a>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 