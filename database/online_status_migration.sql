-- Rider online status columns (auto-added by PHP on first request if not run manually)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_online` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `last_online_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `online_source` VARCHAR(16) NULL DEFAULT NULL;
