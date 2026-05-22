<?php
// =============================================
// CEDEKA WORLD CUP — Database Initializer
// Runs on every boot; all statements are
// CREATE TABLE IF NOT EXISTS so they are safe
// to execute repeatedly without side effects.
// =============================================

function initDB(): void {
    $db = getDB();

    // ------------------------------------------
    // users — must exist before any FK references
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(50)  UNIQUE NOT NULL,
            email         VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name     VARCHAR(100) NOT NULL,
            avatar        VARCHAR(10)  DEFAULT '⚽',
            role          ENUM('user','admin') DEFAULT 'user',
            created_ip    VARCHAR(45)  DEFAULT NULL,
            created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_users_created_ip (created_ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // wallets — one row per user
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallets (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNIQUE NOT NULL,
            balance    DECIMAL(18,4) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // matches — apostable fixtures
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS matches (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            home_team        VARCHAR(80)   NOT NULL,
            away_team        VARCHAR(80)   NOT NULL,
            home_flag        VARCHAR(10)   DEFAULT '🏳',
            away_flag        VARCHAR(10)   DEFAULT '🏳',
            match_date       DATETIME      NOT NULL,
            status           ENUM('open','in_progress','closed','finished') DEFAULT 'open',
            pot_total        DECIMAL(18,4) DEFAULT 0,
            commission_taken DECIMAL(18,4) DEFAULT 0,
            created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // bets — user wagers on a match
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS bets (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            user_id        INT NOT NULL,
            match_id       INT NOT NULL,
            team           VARCHAR(80)   NOT NULL,
            minute         TINYINT UNSIGNED NOT NULL CHECK (minute >= 1 AND minute <= 90),
            amount_cedenas DECIMAL(18,4) NOT NULL,
            status         ENUM('pending','won','lost') DEFAULT 'pending',
            prize_cedenas  DECIMAL(18,4) DEFAULT 0,
            created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id)  REFERENCES users(id),
            FOREIGN KEY (match_id) REFERENCES matches(id),
            UNIQUE KEY unique_bet (user_id, match_id, team, minute)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // goals — goals registered by admin
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS goals (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            match_id      INT NOT NULL,
            team          VARCHAR(80)  NOT NULL,
            minute        TINYINT UNSIGNED NOT NULL,
            scorer        VARCHAR(100) DEFAULT NULL,
            created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (match_id) REFERENCES matches(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // transactions — cedena ledger
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            type          ENUM('deposit','bet','prize','commission','refund') NOT NULL,
            amount        DECIMAL(18,4) NOT NULL,
            balance_after DECIMAL(18,4) NOT NULL,
            description   VARCHAR(255)  DEFAULT NULL,
            reference_id  INT           DEFAULT NULL,
            created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // login_attempts — rate-limiting table
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            ip           VARCHAR(45)  NOT NULL,
            email        VARCHAR(150) NOT NULL,
            success      TINYINT(1)   DEFAULT 0,
            attempted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_email  (ip, email),
            INDEX idx_attempted (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ------------------------------------------
    // recharge_requests — top-up receipts
    // ------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS recharge_requests (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            user_id        INT NOT NULL,
            amount_cedenas DECIMAL(18,4) NOT NULL,
            payment_method VARCHAR(50)   DEFAULT 'transferencia',
            receipt_notes  TEXT          DEFAULT NULL,
            status         ENUM('pending','approved','rejected') DEFAULT 'pending',
            created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
