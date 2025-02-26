<?php
session_start();
require_once 'config/database.php';

// 認証関連の処理をクラスにまとめる
class Authentication {
    private $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }
    
    public function authenticate(string $email, string $password): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, username, password_hash 
                FROM users 
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                return ['success' => true];
            }
            
            return [
                'success' => false,
                'error' => 'メールアドレスまたはパスワードが正しくありません。'
            ];
            
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return [
                'success' => false,
                'error' => 'ログイン処理中にエラーが発生しました。'
            ];
        }
    }
}

// 認証処理のインスタンス化
$auth = new Authentication($pdo);

// すでにログインしている場合はトップページにリダイレクト
if ($auth->isLoggedIn()) {
    header('Location: live-home.php');
    exit;
}

$error = '';

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    $result = $auth->authenticate($email, $password);
    
    if ($result['success']) {
        header('Location: live-home.php');
        exit;
    } else {
        $error = $result['error'];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン - LiveShare</title>
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
        .login-container {
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
        .social-login {
            border-top: 1px solid #dee2e6;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }
        .btn-social {
            width: 100%;
            margin-bottom: 0.5rem;
            text-align: center;
            padding: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>LiveShare</h1>
            <p class="text-muted">ライブ情報共有プラットフォーム</p>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h4 class="card-title mb-4 text-center">ログイン</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="mb-3">
                        <label for="email" class="form-label">メールアドレス</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               required autocomplete="email">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">パスワード</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               required autocomplete="current-password">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">ログイン状態を保持する</label>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">ログイン</button>
                        <a href="forgot-password.php" class="btn btn-link">パスワードをお忘れの方</a>
                    </div>
                </form>

                <div class="social-login">
                    <p class="text-center text-muted mb-3">または</p>
                    <a href="#" class="btn btn-outline-dark btn-social">
                        <i class="bi bi-google me-2"></i>Googleでログイン
                    </a>
                    <a href="#" class="btn btn-outline-primary btn-social">
                        <i class="bi bi-twitter me-2"></i>Twitterでログイン
                    </a>
                </div>

                <div class="text-center mt-4">
                    <p class="mb-0">アカウントをお持ちでない方は</p>
                    <a href="register.php" class="text-decoration-none">新規登録</a>
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