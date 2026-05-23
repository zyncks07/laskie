-- ============================================================
-- Migration 007 — Add users.avatar_path
-- ============================================================
-- Stores the web path (e.g. /uploads/avatars/20260524_…_a1b2c3.jpg) of an
-- uploaded profile picture. NULL means "show initials in a circle" — the
-- existing fallback in includes/header.php.
--
-- Apply: mysql -u laskie_db_user -p laskie_rental < migrations/007_add_user_avatar.sql
-- Safe to re-run (information_schema guard).
-- ============================================================

USE laskie_rental;

SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'avatar_path'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE users ADD COLUMN avatar_path VARCHAR(500) DEFAULT NULL AFTER address',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
