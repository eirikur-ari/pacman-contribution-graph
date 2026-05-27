<?php
/**
 * leaderboard_api.php
 * Returns the top-10 players for each leaderboard category,
 * considering only entries submitted in the last 3 days.
 *
 * GET /leaderboard_api.php
 * Response: { top_score: [...], fastest: [...], ghost_hunter: [...] }
 *
 * Each entry has: { rank, username, platform, value, date }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dbPath = __DIR__ . '/pacman_stats.sqlite';

if (!file_exists($dbPath)) {
    // Return empty leaderboard if no data yet
    echo json_encode([
        'top_score' => [],
        'fastest' => [],
        'ghost_hunter' => [],
        'generated_at' => date('c'),
    ]);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Only look at entries from the last 3 days
    $cutoff = time() - (3 * 24 * 60 * 60);

    // Helper: build a top-10 for a given metric
    // For top_score / ghost_hunter  → MAX is best
    // For fastest                   → MIN steps is best (but only complete runs, steps > 0)
    $buildTop = function (string $metric, string $order, int $limit = 10) use ($pdo, $cutoff): array {
        // Get best value per username within window, then sort globally
        $stmt = $pdo->prepare("
            SELECT
                username,
                platform,
                {$metric} AS value,
                timestamp
            FROM stats
            WHERE timestamp >= :cutoff
              AND steps > 0
            GROUP BY username
            HAVING value = {$order}({$metric})
            ORDER BY value " . ($order === 'MAX' ? 'DESC' : 'ASC') . "
            LIMIT :limit
        ");
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $i => $row) {
            $result[] = [
                'rank' => $i + 1,
                'username' => $row['username'],
                'platform' => $row['platform'],
                'value' => (int) $row['value'],
                'date' => date('Y-m-d', (int) $row['timestamp']),
            ];
        }
        return $result;
    };

    $response = [
        'top_score' => $buildTop('score', 'MAX'),
        'fastest' => $buildTop('steps', 'MIN'),
        'ghost_hunter' => $buildTop('ghosts_eaten', 'MAX'),
        'generated_at' => date('c'),
        'window_days' => 3,
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}