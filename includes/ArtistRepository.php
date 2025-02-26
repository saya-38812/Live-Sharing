<?php
class ArtistRepository {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function updateArtist($artist_id, array $data) {
        $sql = "UPDATE artists SET ";
        $params = [];
        
        foreach ($data as $key => $value) {
            $sql .= "$key = ?, ";
            $params[] = $value;
        }
        
        $sql .= "updated_at = NOW() WHERE id = ?";
        $params[] = $artist_id;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
} 