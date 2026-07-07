-- Evidence denní přítomnosti zaměstnanců v CRM (informativní údaj pro Přehledy).
-- Plní se automaticky za běhu (trackStaffPresence v includes/functions.php).
CREATE TABLE IF NOT EXISTS `staff_presence_daily` (
    `user_id`        INT(11)   NOT NULL,
    `work_date`      DATE      NOT NULL,
    `seconds_active` INT(11)   NOT NULL DEFAULT 0,
    `first_seen`     DATETIME  DEFAULT NULL,
    `last_seen`      DATETIME  DEFAULT NULL,
    PRIMARY KEY (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
