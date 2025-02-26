-- データベースの作成（存在する場合は一旦削除）
DROP DATABASE IF EXISTS liveshare;
CREATE DATABASE liveshare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE liveshare;

-- 1. users（ユーザー）テーブル
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255),
    bio TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. artists（アーティスト）テーブル
CREATE TABLE artists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    image_url VARCHAR(255),
    official_site VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. venues（会場）テーブル
CREATE TABLE venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT NOT NULL,
    capacity INT NOT NULL,
    access_info TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. lives（ライブ情報）テーブル
CREATE TABLE lives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    artist_id INT NOT NULL,
    venue_id INT NOT NULL,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    doors_open TIME,
    capacity INT,
    status ENUM('upcoming', 'on_sale', 'sold_out', 'finished') DEFAULT 'upcoming',
    description TEXT,
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. boards（掲示板カテゴリー）テーブル
CREATE TABLE boards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    color_code VARCHAR(7),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6. threads（スレッド）テーブル
CREATE TABLE threads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    board_id INT,
    live_id INT,
    user_id INT,
    title VARCHAR(100) NOT NULL,
    content TEXT,
    status ENUM('open', 'closed', 'archived') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    FOREIGN KEY (live_id) REFERENCES lives(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. posts（投稿）テーブル
CREATE TABLE posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    thread_id INT,
    user_id INT,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. setlists（セットリスト）テーブル
CREATE TABLE setlists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    live_id INT,
    user_id INT,
    type ENUM('actual', 'prediction') DEFAULT 'prediction',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (live_id) REFERENCES lives(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. setlist_songs（セットリストの曲）テーブル
CREATE TABLE setlist_songs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setlist_id INT,
    song_name VARCHAR(100) NOT NULL,
    order_number INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (setlist_id) REFERENCES setlists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. item_lists（持ち物リスト）テーブル
CREATE TABLE item_lists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    live_id INT,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (live_id) REFERENCES lives(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. items（持ち物アイテム）テーブル
CREATE TABLE items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_list_id INT,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    is_essential BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (item_list_id) REFERENCES item_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 12. fashion_posts（参戦コーデ投稿）テーブル
CREATE TABLE fashion_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    live_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    description TEXT,
    tags VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (live_id) REFERENCES lives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. comments（コメント）テーブル
CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    fashion_post_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (fashion_post_id) REFERENCES fashion_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. tags（タグ）テーブル
CREATE TABLE tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. taggables（タグ付け - ポリモーフィック関連）テーブル
CREATE TABLE taggables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tag_id INT NOT NULL,
    taggable_id INT NOT NULL,
    taggable_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    INDEX (taggable_id, taggable_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. likes（いいね）テーブル
CREATE TABLE likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    likeable_id INT NOT NULL,
    likeable_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (user_id, likeable_id, likeable_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. bookmarks（ブックマーク）テーブル
CREATE TABLE bookmarks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    bookmarkable_type VARCHAR(50) NOT NULL,
    bookmarkable_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 18. follows（フォロー関係）テーブル
CREATE TABLE follows (
    id INT PRIMARY KEY AUTO_INCREMENT,
    follower_id INT,
    followed_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- インデックスの作成
CREATE INDEX idx_lives_artist ON lives(artist_id);
CREATE INDEX idx_lives_venue ON lives(venue_id);
CREATE INDEX idx_lives_date ON lives(date);
CREATE INDEX idx_lives_status ON lives(status);

CREATE INDEX idx_threads_board ON threads(board_id);
CREATE INDEX idx_threads_live ON threads(live_id);
CREATE INDEX idx_threads_user ON threads(user_id);
CREATE INDEX idx_threads_created ON threads(created_at);

CREATE INDEX idx_posts_thread ON posts(thread_id);
CREATE INDEX idx_posts_user ON posts(user_id);
CREATE INDEX idx_posts_created ON posts(created_at);

CREATE INDEX idx_setlists_live ON setlists(live_id);
CREATE INDEX idx_setlists_user ON setlists(user_id);

CREATE INDEX idx_item_lists_user ON item_lists(user_id);
CREATE INDEX idx_item_lists_live ON item_lists(live_id);

CREATE INDEX idx_fashion_posts_user ON fashion_posts(user_id);
CREATE INDEX idx_fashion_posts_live ON fashion_posts(live_id);
CREATE INDEX idx_fashion_posts_created ON fashion_posts(created_at);

CREATE INDEX idx_taggables_tag ON taggables(tag_id);
CREATE INDEX idx_taggables_type_id ON taggables(taggable_type, taggable_id);

CREATE INDEX idx_likes_user ON likes(user_id);
CREATE INDEX idx_likes_type_id ON likes(likeable_type, likeable_id);

CREATE INDEX idx_bookmarks_user ON bookmarks(user_id);
CREATE INDEX idx_bookmarks_type_id ON bookmarks(bookmarkable_type, bookmarkable_id); 