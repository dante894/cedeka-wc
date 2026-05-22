-- =============================================
-- CEDEKA WORLD CUP QUINIELA - Base de Datos
-- =============================================

CREATE DATABASE IF NOT EXISTS cedeka_quiniela CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cedeka_quiniela;

-- Usuarios
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    avatar VARCHAR(10) DEFAULT '⚽',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Partidos apostables
CREATE TABLE matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    home_team VARCHAR(80) NOT NULL,
    away_team VARCHAR(80) NOT NULL,
    home_flag VARCHAR(10) DEFAULT '🏳',
    away_flag VARCHAR(10) DEFAULT '🏳',
    match_date DATETIME NOT NULL,
    status ENUM('open', 'in_progress', 'closed', 'finished') DEFAULT 'open',
    pot_total DECIMAL(18,4) DEFAULT 0,
    commission_taken DECIMAL(18,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Apuestas de usuarios
CREATE TABLE bets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    match_id INT NOT NULL,
    team VARCHAR(80) NOT NULL,
    minute TINYINT UNSIGNED NOT NULL CHECK (minute >= 1 AND minute <= 90),
    amount_cedenas DECIMAL(18,4) NOT NULL,
    status ENUM('pending', 'won', 'lost') DEFAULT 'pending',
    prize_cedenas DECIMAL(18,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (match_id) REFERENCES matches(id),
    UNIQUE KEY unique_bet (user_id, match_id, team, minute)
);

-- Goles registrados (cargados por admin)
CREATE TABLE goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    team VARCHAR(80) NOT NULL,
    minute TINYINT UNSIGNED NOT NULL,
    scorer VARCHAR(100),
    registered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (registered_by) REFERENCES users(id)
);

-- Billetera de cada usuario
CREATE TABLE wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    balance DECIMAL(18,4) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Movimientos de cedenas
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('deposit', 'bet', 'prize', 'commission', 'refund') NOT NULL,
    amount DECIMAL(18,4) NOT NULL,
    balance_after DECIMAL(18,4) NOT NULL,
    description VARCHAR(255),
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Solicitudes de recarga (comprobantes)
CREATE TABLE recharge_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount_cedenas DECIMAL(18,4) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'transferencia',
    receipt_notes TEXT,
    receipt_image VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    review_notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

-- =============================================
-- DATOS INICIALES
-- =============================================

-- Admin por defecto (password: admin123)
INSERT INTO users (username, email, password_hash, full_name, role, avatar) VALUES
('admin', 'admin@cedeka.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador Cedeka', 'admin', '👑');

-- Wallet del admin
INSERT INTO wallets (user_id, balance) VALUES (1, 0);

-- Partidos de ejemplo
INSERT INTO matches (home_team, away_team, home_flag, away_flag, match_date, status) VALUES
('Brasil', 'Argentina', '🇧🇷', '🇦🇷', DATE_ADD(NOW(), INTERVAL 2 DAY), 'open'),
('España', 'Francia', '🇪🇸', '🇫🇷', DATE_ADD(NOW(), INTERVAL 3 DAY), 'open'),
('Alemania', 'Italia', '🇩🇪', '🇮🇹', DATE_ADD(NOW(), INTERVAL 5 DAY), 'open'),
('México', 'Colombia', '🇲🇽', '🇨🇴', DATE_ADD(NOW(), INTERVAL 1 DAY), 'open');

-- =============================================
-- TABLAS DE SEGURIDAD (agregar a BD existente)
-- =============================================

-- Rate limiting de login
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    email        VARCHAR(150) NOT NULL,
    success      TINYINT(1) DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_email (ip, email),
    INDEX idx_attempted (attempted_at)
);

-- IP de registro en tabla users (si no existe)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS created_ip VARCHAR(45) DEFAULT NULL AFTER created_at;

-- Índice para búsqueda por IP en registro
CREATE INDEX IF NOT EXISTS idx_users_created_ip ON users (created_ip, created_at);
