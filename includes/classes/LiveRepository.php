<?php

class LiveRepository {
    private $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    public function getFeaturedLives(int $userId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM lives 
                WHERE featured = 1 
                ORDER BY start_date DESC 
                LIMIT 5
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return [];
        }
    }
    
    public function getRecentPosts(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT posts.*, users.username, users.profile_image 
                FROM posts 
                JOIN users ON posts.user_id = users.id 
                ORDER BY posts.created_at DESC 
                LIMIT 10
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return [];
        }
    }
    
    public function getNextLive(): ?array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM lives 
                WHERE start_date > NOW() 
                ORDER BY start_date ASC 
                LIMIT 1
            ");
            $stmt->execute();
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }
    
    public function getUpcomingLives(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM lives 
                WHERE start_date > NOW() 
                ORDER BY start_date ASC 
                LIMIT 5
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return [];
        }
    }
} 