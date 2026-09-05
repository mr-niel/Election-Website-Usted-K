-- ============================================================
--  Migration: Add phone, ID photo, and OTP fields to voters
--  SAFE VERSION — skips columns that already exist.
--
--  HOW TO USE:
--  1) Open http://localhost/phpmyadmin
--  2) Select the aamusted_election database
--  3) Click "SQL" tab
--  4) Paste this entire file and click "Go"
-- ============================================================

USE aamusted_election;

-- Add columns only if they don't already exist
SET @dbname = DATABASE();
SET @tablename = 'voters';

-- phone
SET @prequery = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE table_schema = ''', @dbname, ''' AND table_name = ''', @tablename, ''' AND column_name = ''phone''');
PREPARE stmt FROM @prequery; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@colexists = 0, 'ALTER TABLE voters ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER password_hash', 'SELECT ''phone already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- id_photo_url
SET @prequery = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE table_schema = ''', @dbname, ''' AND table_name = ''', @tablename, ''' AND column_name = ''id_photo_url''');
PREPARE stmt FROM @prequery; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@colexists = 0, 'ALTER TABLE voters ADD COLUMN id_photo_url VARCHAR(255) DEFAULT NULL AFTER phone', 'SELECT ''id_photo_url already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- otp_code
SET @prequery = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE table_schema = ''', @dbname, ''' AND table_name = ''', @tablename, ''' AND column_name = ''otp_code''');
PREPARE stmt FROM @prequery; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@colexists = 0, 'ALTER TABLE voters ADD COLUMN otp_code VARCHAR(6) DEFAULT NULL AFTER id_photo_url', 'SELECT ''otp_code already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- otp_expires
SET @prequery = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE table_schema = ''', @dbname, ''' AND table_name = ''', @tablename, ''' AND column_name = ''otp_expires''');
PREPARE stmt FROM @prequery; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@colexists = 0, 'ALTER TABLE voters ADD COLUMN otp_expires DATETIME DEFAULT NULL AFTER otp_code', 'SELECT ''otp_expires already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- otp_verified
SET @prequery = CONCAT('SELECT COUNT(*) INTO @colexists FROM information_schema.COLUMNS WHERE table_schema = ''', @dbname, ''' AND table_name = ''', @tablename, ''' AND column_name = ''otp_verified''');
PREPARE stmt FROM @prequery; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@colexists = 0, 'ALTER TABLE voters ADD COLUMN otp_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER otp_expires', 'SELECT ''otp_verified already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Change default status to 'pending' so new registrations require officer approval
ALTER TABLE voters
  MODIFY COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending';

SELECT 'Migration complete. All columns are now present.' AS result;
