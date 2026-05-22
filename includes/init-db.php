<?php
// =============================================
// CEDEKA WORLD CUP — Database Initializer
// Runs on every boot; all statements are
// CREATE TABLE IF NOT EXISTS so they are safe
// to execute repeatedly without side effects.
// =============================================

function initDB(): void {
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS matches (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            home_team        VARCHAR(80)  NOT NULL,
            away_team        VARCHAR(80)  NOT NULL,
            home_flag        VARCHAR(10)  DEFAULT '🏳',
            away_flag        VARCHAR(10)  DEFAULT '🏳',
            match_date       DATETIME     NOT NULL,
            status           ENUM('open','in_progress','closed','finished') DEFAULT 'open',
            pot_total        DECIMAL(18,4) DEFAULT 0,
            commission_taken DECIMAL(18,4) DEFAULT 0,
            created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
