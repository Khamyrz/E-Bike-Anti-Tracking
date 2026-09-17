-- Widen system_settings values so perimeter JSON is not truncated.
ALTER TABLE system_settings MODIFY setting_value TEXT NOT NULL;
