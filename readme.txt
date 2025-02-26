# LiveShare データベース設計

## テーブル構造

### 1. users（ユーザー）
- id (INT, PK, AUTO_INCREMENT)
- username (VARCHAR(50), UNIQUE)
- email (VARCHAR(100), UNIQUE)
- password_hash (VARCHAR(255))
- profile_image (VARCHAR(255))
- bio (TEXT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 2. lives（ライブ情報）
- id (INT, PK, AUTO_INCREMENT)
- title (VARCHAR(100))
- artist_id (INT, FK -> artists.id)
- venue_id (INT, FK -> venues.id)
- date (DATE)
- start_time (TIME)
- doors_open (TIME)
- capacity (INT)
- status (ENUM('upcoming', 'on_sale', 'sold_out', 'finished'))
- description (TEXT)
- image_url (VARCHAR(255))
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 3. artists（アーティスト）
- id (INT, PK, AUTO_INCREMENT)
- name (VARCHAR(100))
- description (TEXT)
- image_url (VARCHAR(255))
- official_site (VARCHAR(255))
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 4. venues（会場）
- id (INT, PK, AUTO_INCREMENT)
- name (VARCHAR(100))
- address (VARCHAR(255))
- capacity (INT)
- access_info (TEXT)
- latitude (DECIMAL(10,8))
- longitude (DECIMAL(11,8))
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 5. boards（掲示板カテゴリー）
- id (INT, PK, AUTO_INCREMENT)
- name (VARCHAR(50))
- description (TEXT)
- icon (VARCHAR(50))
- color_code (VARCHAR(7))
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 6. threads（スレッド）
- id (INT, PK, AUTO_INCREMENT)
- board_id (INT, FK -> boards.id)
- live_id (INT, FK -> lives.id)
- user_id (INT, FK -> users.id)
- title (VARCHAR(100))
- content (TEXT)
- status (ENUM('open', 'closed', 'archived'))
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 7. posts（投稿）
- id (INT, PK, AUTO_INCREMENT)
- thread_id (INT, FK -> threads.id)
- user_id (INT, FK -> users.id)
- content (TEXT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 8. setlists（セットリスト）
- id (INT, PK, AUTO_INCREMENT)
- live_id (INT, FK -> lives.id)
- user_id (INT, FK -> users.id)
- type (ENUM('actual', 'prediction'))
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 9. setlist_songs（セットリストの曲）
- id (INT, PK, AUTO_INCREMENT)
- setlist_id (INT, FK -> setlists.id)
- song_name (VARCHAR(100))
- order_number (INT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 10. item_lists（持ち物リスト）
- id (INT, PK, AUTO_INCREMENT)
- user_id (INT, FK -> users.id)
- live_id (INT, FK -> lives.id)
- title (VARCHAR(100))
- description (TEXT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 11. items（持ち物アイテム）
- id (INT, PK, AUTO_INCREMENT)
- item_list_id (INT, FK -> item_lists.id)
- name (VARCHAR(100))
- category (VARCHAR(50))
- is_essential (BOOLEAN)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 12. fashion_posts（参戦コーデ投稿）
- id (INT, PK, AUTO_INCREMENT)
- user_id (INT, FK -> users.id)
- live_id (INT, FK -> lives.id)
- image_url (VARCHAR(255))
- description (TEXT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 13. tags（タグ）
- id (INT, PK, AUTO_INCREMENT)
- name (VARCHAR(50), UNIQUE)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### 14. taggables（タグ付け - ポリモーフィック関連）
- id (INT, PK, AUTO_INCREMENT)
- tag_id (INT, FK -> tags.id)
- taggable_type (VARCHAR(50))
- taggable_id (INT)
- created_at (TIMESTAMP)

### 15. likes（いいね）
- id (INT, PK, AUTO_INCREMENT)
- user_id (INT, FK -> users.id)
- likeable_type (VARCHAR(50))
- likeable_id (INT)
- created_at (TIMESTAMP)

### 16. bookmarks（ブックマーク）
- id (INT, PK, AUTO_INCREMENT)
- user_id (INT, FK -> users.id)
- bookmarkable_type (VARCHAR(50))
- bookmarkable_id (INT)
- created_at (TIMESTAMP)

### 17. follows（フォロー関係）
- id (INT, PK, AUTO_INCREMENT)
- follower_id (INT, FK -> users.id)
- followed_id (INT, FK -> users.id)
- created_at (TIMESTAMP)

## インデックス設計

主要な検索・結合操作を最適化するために、以下のインデックスを作成します：

1. users
   - email (UNIQUE)
   - username (UNIQUE)

2. lives
   - artist_id
   - venue_id
   - date
   - status

3. threads
   - board_id
   - live_id
   - user_id
   - created_at

4. posts
   - thread_id
   - user_id
   - created_at

5. setlists
   - live_id
   - user_id

6. item_lists
   - user_id
   - live_id

7. fashion_posts
   - user_id
   - live_id
   - created_at

8. taggables
   - tag_id
   - (taggable_type, taggable_id)

9. likes
   - user_id
   - (likeable_type, likeable_id)

10. bookmarks
    - user_id
    - (bookmarkable_type, bookmarkable_id)

## 外部キー制約

すべての外部キー参照には、ON DELETE CASCADE または ON DELETE SET NULL の適切な制約を設定します。

## 文字セットと照合順序

- 文字セット: utf8mb4
- 照合順序: utf8mb4_unicode_ci

## 注意事項

1. すべてのテーブルにcreated_atとupdated_atを含め、データの作成・更新時刻を追跡
2. 重要なデータの削除は論理削除（ソフトデリート）を使用
3. パスワードは必ずハッシュ化して保存
4. 画像URLは実際のファイル名のみを保存し、ベースパスは設定で管理
5. 位置情報はINDEXを使用して位置検索を最適化 