<?php
class LiveRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function findById($live_id, $user_id = 0) {
        $stmt = $this->pdo->prepare("
            SELECT l.*, 
                   a.id as artist_id,
                   a.name as artist_name, 
                   a.artist_image,
                   a.description as artist_description,
                   a.setlist,
                   a.genre as artist_genre,
                   a.debut_year as artist_debut_year,
                   a.official_site,
                   a.twitter_url,
                   a.instagram_url,
                   a.youtube_url,
                   v.name as venue_name,
                   COUNT(DISTINCT b.id) as bookmark_count,
                   EXISTS (
                       SELECT 1 FROM bookmarks 
                       WHERE bookmarkable_id = l.id 
                       AND bookmarkable_type = 'live' 
                       AND user_id = ?
                   ) as is_bookmarked
            FROM lives l
            LEFT JOIN artists a ON l.artist_id = a.id
            LEFT JOIN venues v ON l.venue_id = v.id
            LEFT JOIN bookmarks b ON b.bookmarkable_id = l.id AND b.bookmarkable_type = 'live'
            WHERE l.id = ?
            GROUP BY l.id, a.id, v.id
        ");
        $stmt->execute([$user_id, $live_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getPostsByLiveId($live_id, $user_id = 0) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, u.name as user_name, u.profile_image,
                   COUNT(DISTINCT l.id) as like_count,
                   COUNT(DISTINCT c.id) as comment_count,
                   EXISTS (
                       SELECT 1 FROM likes 
                       WHERE post_id = p.id 
                       AND user_id = ?
                   ) as is_liked
            FROM posts p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN likes l ON l.post_id = p.id
            LEFT JOIN comments c ON c.post_id = p.id
            WHERE p.live_id = ?
            GROUP BY p.id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$user_id, $live_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFeaturedLives($user_id = 0) {
        $stmt = $this->pdo->prepare("
            SELECT l.*, a.name as artist_name, v.name as venue_name,
                   COUNT(DISTINCT b.id) as bookmark_count,
                   EXISTS (
                       SELECT 1 FROM bookmarks 
                       WHERE bookmarkable_id = l.id 
                       AND bookmarkable_type = 'live' 
                       AND user_id = ?
                   ) as is_bookmarked
            FROM lives l
            JOIN artists a ON l.artist_id = a.id
            JOIN venues v ON l.venue_id = v.id
            LEFT JOIN bookmarks b ON b.bookmarkable_id = l.id AND b.bookmarkable_type = 'live'
            WHERE l.date >= CURDATE()
            GROUP BY l.id
            ORDER BY l.date ASC
            LIMIT 3
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNextLive() {
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.title, l.date, v.name as venue_name
            FROM lives l
            LEFT JOIN venues v ON l.venue_id = v.id
            WHERE l.date > CURRENT_DATE
            ORDER BY l.date ASC
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getUpcomingLives() {
        $stmt = $this->pdo->prepare("
            SELECT l.id, l.title, l.date, v.name as venue_name
            FROM lives l
            LEFT JOIN venues v ON l.venue_id = v.id
            WHERE l.date >= CURRENT_DATE() 
            AND l.date <= DATE_ADD(CURRENT_DATE(), INTERVAL 1 MONTH)
            ORDER BY l.date ASC
            LIMIT 5
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} 