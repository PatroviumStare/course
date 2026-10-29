USE newsroom;

-- The information_schema checks keep this migration repeatable on MySQL 5.7/8.x.
SET @db = DATABASE();
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='bio')=0, 'ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER role', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='avatar_url')=0, 'ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL AFTER bio', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='comments_visibility')=0, 'ALTER TABLE users ADD COLUMN comments_visibility ENUM(''everyone'',''followers'',''private'') NOT NULL DEFAULT ''everyone'' AFTER avatar_url', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='follows_visibility')=0, 'ALTER TABLE users ADD COLUMN follows_visibility ENUM(''everyone'',''followers'',''private'') NOT NULL DEFAULT ''everyone'' AFTER comments_visibility', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_enabled')=0, 'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER follows_visibility', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS auth_sessions (
    id CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    device_label VARCHAR(180) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_auth_sessions_user (user_id, last_seen)
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
