CREATE DATABASE IF NOT EXISTS newsroom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE newsroom;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('viewer','author','moderator','admin') NOT NULL DEFAULT 'viewer',
    bio TEXT NULL,
    avatar_url VARCHAR(500) NULL,
    comments_visibility ENUM('everyone','followers','private') NOT NULL DEFAULT 'everyone',
    follows_visibility ENUM('everyone','followers','private') NOT NULL DEFAULT 'everyone',
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    banned_until DATETIME NULL,
    ban_reason VARCHAR(1000) NULL,
    ban_previous_name VARCHAR(100) NULL,
    ban_previous_avatar VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_sessions (
    id CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_label VARCHAR(180) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    device_key CHAR(64) NOT NULL,
    switch_token CHAR(64) NOT NULL UNIQUE,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_auth_sessions_user (user_id, last_seen),
    UNIQUE KEY uq_auth_sessions_device_user (user_id, device_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS articles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(220) NOT NULL,
    subtitle VARCHAR(300) NOT NULL,
    slug VARCHAR(240) NOT NULL UNIQUE,
    body MEDIUMTEXT NOT NULL,
    image_url VARCHAR(500) NULL,
    source_url VARCHAR(500) NULL,
    status ENUM('draft','review','published','rejected') NOT NULL DEFAULT 'draft',
    ban_hold TINYINT(1) NOT NULL DEFAULT 0,
    ban_previous_status ENUM('draft','review','published','rejected') NULL,
    ban_resolution ENUM('pending','delete','hide','restore') NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    moderation_note VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_articles_author FOREIGN KEY (author_id) REFERENCES users(id),
    CONSTRAINT fk_articles_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_articles_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
    INDEX idx_articles_feed (status, published_at),
    INDEX idx_articles_category (category_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_tags (
    user_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, tag_id),
    CONSTRAINT fk_profile_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_reputation (
    profile_id BIGINT UNSIGNED NOT NULL,
    voter_id BIGINT UNSIGNED NOT NULL,
    value TINYINT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id, voter_id),
    CONSTRAINT fk_profile_rep_profile FOREIGN KEY (profile_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_rep_voter FOREIGN KEY (voter_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_profile_rep_value CHECK (value IN (-1, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS article_tags (
    article_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    CONSTRAINT fk_article_tags_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_article_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(2000) NOT NULL,
    status ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    ban_hold TINYINT(1) NOT NULL DEFAULT 0,
    ban_resolution ENUM('pending','delete','hide','restore') NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_comments_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_comments_article (article_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
    article_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    value TINYINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id, user_id),
    CONSTRAINT fk_ratings_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT chk_rating_value CHECK (value BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS article_views (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    visitor_key CHAR(64) NULL,
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_views_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_views_history (user_id, viewed_at),
    INDEX idx_views_visitor (visitor_key, viewed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS follows (
    user_id BIGINT UNSIGNED NOT NULL,
    target_type ENUM('category','author') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, target_type, target_id),
    CONSTRAINT fk_follows_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_follows_target (target_type, target_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS moderation_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    note VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_log_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;
