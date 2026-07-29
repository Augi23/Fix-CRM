-- APNs device tokens for the native iOS app (push notifications).
-- One row per device; re-registration updates the owning user.
CREATE TABLE IF NOT EXISTS `push_tokens` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `device_token` VARCHAR(200) NOT NULL,
    `platform`     VARCHAR(20)  NOT NULL DEFAULT 'ios',
    `app_version`  VARCHAR(40)  NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_device_token` (`device_token`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
