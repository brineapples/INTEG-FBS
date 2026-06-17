<?php
$conn = new mysqli("localhost", "root", "", "survey");

if ($conn->connect_error) {
    die("Database connection failed. Please make sure MySQL is running in XAMPP and the survey database has been imported.");
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function ensureAnswerSchema(mysqli $conn): void {
    $hasAnswerText = columnExists($conn, 'answers', 'answerText');
    $hasRatingValue = columnExists($conn, 'answers', 'ratingValue');
    $hasOptionsNullable = false;

    $stmt = $conn->prepare(
        "SELECT IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'answers'
           AND COLUMN_NAME = 'optionsId'
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $hasOptionsNullable = isset($row['IS_NULLABLE']) && $row['IS_NULLABLE'] === 'YES';

    if (!$hasAnswerText || !$hasRatingValue || !$hasOptionsNullable) {
        if (!$hasOptionsNullable) {
            $conn->query("ALTER TABLE answers MODIFY optionsId INT NULL");
        }
        if (!$hasAnswerText) {
            $conn->query("ALTER TABLE answers ADD COLUMN answerText TEXT NULL AFTER optionsId");
        }
        if (!$hasRatingValue) {
            $conn->query("ALTER TABLE answers ADD COLUMN ratingValue TINYINT UNSIGNED NULL AFTER answerText");
        }
    }
}

ensureAnswerSchema($conn);
?>
