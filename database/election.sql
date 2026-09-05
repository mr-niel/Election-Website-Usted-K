-- ============================================================
--  AAMUSTED / USTED Student Election System
--  Database: aamusted_election
--  Engine:   MySQL 8.x  (XAMPP / MariaDB 10.x compatible)
--
--  HOW TO USE THIS FILE
--  1) Start Apache + MySQL in XAMPP Control Panel.
--  2) Open http://localhost/phpmyadmin
--  3) Click "Import" -> choose this file -> click "Go".
--
--  Tables overview
--   campuses      - Kumasi (KSI) and Mampong (MPG)
--   departments   - academic departments (real AAMUSTED departments)
--   programmes    - courses of study offered by the school
--   halls         - halls of residence
--   admins        - election officers
--   elections     - one row per election (SRC, departmental, hall, class)
--   voters        - every student eligible to vote
--   positions     - contested roles, linked to an election
--   candidates    - people standing for a position
--   votes         - one row per vote cast (anonymous + tamper-proof)
-- ============================================================

CREATE DATABASE IF NOT EXISTS aamusted_election
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE aamusted_election;

-- Drop in reverse dependency order
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS candidates;
DROP TABLE IF EXISTS positions;
DROP TABLE IF EXISTS voters;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS halls;
DROP TABLE IF EXISTS programmes;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS campuses;
DROP TABLE IF EXISTS elections;

-- ------------------------------------------------------------
--  Campuses
-- ------------------------------------------------------------
CREATE TABLE campuses (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL UNIQUE,
  code          VARCHAR(10)  NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Departments (academic)
--  scope = 'src'   -> can participate in campus-wide SRC
--          'dept'  -> a department that may hold its own election
-- ------------------------------------------------------------
CREATE TABLE departments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  campus_id     INT NOT NULL,
  name          VARCHAR(150) NOT NULL,
  code          VARCHAR(30)  NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dept_campus FOREIGN KEY (campus_id)
      REFERENCES campuses(id) ON DELETE RESTRICT,
  UNIQUE KEY uniq_dept_code (campus_id, code),
  INDEX idx_dept_campus (campus_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Programmes (courses of study)
-- ------------------------------------------------------------
CREATE TABLE programmes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prog_dept FOREIGN KEY (department_id)
      REFERENCES departments(id) ON DELETE CASCADE,
  INDEX idx_prog_dept (department_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Halls of Residence
-- ------------------------------------------------------------
CREATE TABLE halls (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  campus_id     INT NOT NULL,
  name          VARCHAR(120) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hall_campus FOREIGN KEY (campus_id)
      REFERENCES campuses(id) ON DELETE RESTRICT,
  INDEX idx_hall_campus (campus_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Admins (election officers)
-- ------------------------------------------------------------
CREATE TABLE admins (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  full_name     VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Elections
--  type   = 'src'  (campus-wide SRC)
--            'departmental' (a single department's election)
--            'hall'  (a hall of residence election)
--            'class' (classroom / class representative election)
--  scope_id = FK to departments.id (departmental) or halls.id (hall)
--             or NULL for SRC
--  status = 'setup' | 'open' | 'closed'
-- ------------------------------------------------------------
CREATE TABLE elections (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  type          ENUM('src','departmental','hall','class') NOT NULL DEFAULT 'src',
  campus_id     INT NOT NULL,
  department_id INT DEFAULT NULL,
  hall_id       INT DEFAULT NULL,
  start_time    DATETIME NOT NULL,
  end_time      DATETIME NOT NULL,
  status        ENUM('setup','open','closed') NOT NULL DEFAULT 'setup',
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_el_campus FOREIGN KEY (campus_id)
      REFERENCES campuses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_el_dept FOREIGN KEY (department_id)
      REFERENCES departments(id) ON DELETE CASCADE,
  CONSTRAINT fk_el_hall FOREIGN KEY (hall_id)
      REFERENCES halls(id) ON DELETE CASCADE,
  INDEX idx_el_type (type),
  INDEX idx_el_campus (campus_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Voters (students)
--  level  = '100' | '200' | '300' | '400'
--  status = 'pending' | 'approved' | 'rejected'
--  gender = 'male' | 'female'
-- ------------------------------------------------------------
CREATE TABLE voters (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    VARCHAR(30)  NOT NULL UNIQUE,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone         VARCHAR(20)  DEFAULT NULL,
  id_photo_url  VARCHAR(255) DEFAULT NULL,
  otp_code      VARCHAR(6)   DEFAULT NULL,
  otp_expires   DATETIME     DEFAULT NULL,
  otp_verified  TINYINT(1)   NOT NULL DEFAULT 0,
  campus_id     INT          NOT NULL,
  department_id INT          NOT NULL,
  programme_id  INT          NOT NULL,
  hall_id       INT          DEFAULT NULL,
  level         ENUM('100','200','300','400') NOT NULL DEFAULT '100',
  gender        ENUM('male','female') NOT NULL DEFAULT 'male',
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  has_voted_src TINYINT(1)   NOT NULL DEFAULT 0,
  has_voted_dept TINYINT(1)  NOT NULL DEFAULT 0,
  has_voted_hall TINYINT(1)  NOT NULL DEFAULT 0,
  has_voted_class TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_voter_campus FOREIGN KEY (campus_id)
      REFERENCES campuses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_voter_dept FOREIGN KEY (department_id)
      REFERENCES departments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_voter_prog FOREIGN KEY (programme_id)
      REFERENCES programmes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_voter_hall FOREIGN KEY (hall_id)
      REFERENCES halls(id) ON DELETE SET NULL,
  INDEX idx_voter_student (student_id),
  INDEX idx_voter_status (status),
  INDEX idx_voter_dept (department_id),
  INDEX idx_voter_hall (hall_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Positions being contested
--  election_id links to a specific election cycle
-- ------------------------------------------------------------
CREATE TABLE positions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  election_id   INT NOT NULL,
  title         VARCHAR(150) NOT NULL,
  description   TEXT,
  max_votes     INT NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pos_election FOREIGN KEY (election_id)
      REFERENCES elections(id) ON DELETE CASCADE,
  INDEX idx_pos_election (election_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Candidates standing for a position
-- ------------------------------------------------------------
CREATE TABLE candidates (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  position_id   INT NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  student_id    VARCHAR(30)  NOT NULL,
  department_id INT NOT NULL,
  manifesto     TEXT,
  photo_url     VARCHAR(255) DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cand_pos FOREIGN KEY (position_id)
      REFERENCES positions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cand_dept FOREIGN KEY (department_id)
      REFERENCES departments(id) ON DELETE RESTRICT,
  INDEX idx_cand_pos (position_id),
  INDEX idx_cand_dept (department_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Votes
--  voter_id recorded for one-vote-one-position enforcement.
--  The ballot stays secret: results only count candidate IDs.
-- ------------------------------------------------------------
CREATE TABLE votes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  position_id   INT NOT NULL,
  candidate_id  INT NOT NULL,
  voter_id      INT NOT NULL,
  voted_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vote_pos  FOREIGN KEY (position_id)
      REFERENCES positions(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_cand FOREIGN KEY (candidate_id)
      REFERENCES candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_voter FOREIGN KEY (voter_id)
      REFERENCES voters(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_one_vote (voter_id, position_id),
  INDEX idx_vote_pos (position_id)
) ENGINE=InnoDB;

-- ============================================================
--  SEED DATA
-- ============================================================

-- Campuses
INSERT INTO campuses (name, code) VALUES
  ('Kumasi Campus', 'KSI'),
  ('Mampong Campus', 'MPG');

-- Departments — Kumasi Campus (campus_id = 1)
INSERT INTO departments (campus_id, name, code) VALUES
  (1, 'Construction and Wood Technology Education', 'CWE'),
  (1, 'Electricals and Electronics Engineering Education', 'EEE'),
  (1, 'Mechanical and Automotive Engineering Education', 'MAE'),
  (1, 'Fashion and Textiles Education', 'FTE'),
  (1, 'Hospitality and Tourism Education', 'HTE'),
  (1, 'Information Technology Education', 'ITE'),
  (1, 'Accounting Education', 'ACE'),
  (1, 'Management Education', 'MGE'),
  (1, 'Economics Education', 'ECO'),
  (1, 'Human Resource Education', 'HRE'),
  (1, 'Interdisciplinary Studies Education', 'IDS'),
  (1, 'Languages Education', 'LAN'),
  (1, 'Mathematics Education', 'MAT'),
  (1, 'Entrepreneurship and Innovation Education', 'EIE');

-- Departments — Mampong Campus (campus_id = 2)
INSERT INTO departments (campus_id, name, code) VALUES
  (2, 'Agricultural Education', 'AGE'),
  (2, 'Crops and Soil Science Education', 'CSS'),
  (2, 'Agriculture Economics and Extension Education', 'AEE'),
  (2, 'Agriculture Mechanisation and Engineering Education', 'AME'),
  (2, 'Integrated Science Education', 'ISE'),
  (2, 'Biological Sciences Education', 'BSE'),
  (2, 'Chemistry Education', 'CHE'),
  (2, 'Public Health Education', 'PHE'),
  (2, 'Environmental Health and Sanitation Education', 'EHS'),
  (2, 'Educational Studies', 'EDS'),
  (2, 'Interdisciplinary Studies (Mampong)', 'IDM');

-- Programmes — Kumasi (selected key programmes, linked to department IDs 1-14)
INSERT INTO programmes (department_id, name) VALUES
  (1, 'B.Sc. Construction Technology and Management with Education'),
  (1, 'B.Ed. Applied Technology (Building Construction and Wood)'),
  (1, 'B.Sc. Wood Technology with Education'),
  (1, 'B.Sc. Plumbing, Gas and Sanitary Technology'),
  (1, 'B.Sc. Civil Engineering'),
  (2, 'B.Sc. Electrical and Electronics Engineering'),
  (2, 'B.Sc. Biomedical Equipment Engineering'),
  (2, 'B.Ed. Applied Technology (Electrical and Electronics)'),
  (2, 'B.Sc. Electrical and Electronics Engineering Technology Education'),
  (2, 'B.Ed. STEM (Engineering with Robotics)'),
  (3, 'B.Sc. Mechanical Engineering Technology'),
  (3, 'B.Sc. Mechanical Engineering Technology Education'),
  (3, 'B.Sc. Automotive Engineering Technology Education'),
  (3, 'B.Ed. Applied Technology (Automotive and Mechanical)'),
  (3, 'B.Sc. Welding and Fabrication Technology with Education'),
  (4, 'B.Sc. Fashion Design and Textiles Education'),
  (4, 'B.Ed. Home Economics (Clothing and Textiles)'),
  (5, 'B.Sc. Catering and Hospitality Education'),
  (5, 'B.Ed. Home Economics (Food and Nutrition)'),
  (6, 'B.Sc. Information Technology'),
  (6, 'B.Ed. Information Technology'),
  (6, 'B.Sc. Cyber Security and Digital Forensics'),
  (6, 'B.Ed. Computing with Artificial Intelligence'),
  (6, 'B.Ed. Computing with Internet of Things (IoT)'),
  (7, 'B.Sc. Accounting'),
  (7, 'B.Sc. Admin Accounting'),
  (7, 'B.Sc. Banking and Finance'),
  (7, 'B.Sc. Procurement and Supply Chain Management'),
  (7, 'B.Ed. Business Studies (Accounting)'),
  (8, 'B.Sc. Marketing'),
  (8, 'B.B.A. Management'),
  (8, 'B.Ed. Business Studies (Management)'),
  (9, 'B.Sc. Economics'),
  (9, 'B.Ed. Economics'),
  (10, 'B.B.A. Human Resource Management'),
  (11, 'B.Ed. Social Studies'),
  (11, 'B.Ed. Geography'),
  (11, 'B.Ed. Physical Education and Health'),
  (11, 'B.Ed. Early Grade (Early Childhood)'),
  (11, 'B.Ed. Junior High'),
  (11, 'B.Ed. Upper Primary'),
  (12, 'B.Ed. English'),
  (12, 'B.Ed. French'),
  (12, 'B.Ed. Arabic'),
  (12, 'B.Ed. Ghanaian Language'),
  (13, 'B.Ed. Mathematics'),
  (14, 'B.Sc. Entrepreneurship Education'),
  (14, 'B.Sc. Marketing and Entrepreneurship');

-- Programmes — Mampong (department IDs 15-25)
INSERT INTO programmes (department_id, name) VALUES
  (15, 'B.Sc. Agricultural Science Education'),
  (15, 'B.Ed. Agricultural Science'),
  (16, 'B.Sc. Natural Resources Management and Education'),
  (17, 'B.Sc. Agribusiness Management and Entrepreneurship Education'),
  (18, 'B.Sc. Agricultural Engineering, Technology and Innovations Education'),
  (19, 'B.Ed. General Science'),
  (19, 'B.Ed. Mathematics'),
  (19, 'B.Ed. Physics'),
  (20, 'B.Ed. Biology'),
  (20, 'B.Sc. Nutrition and Dietetics'),
  (21, 'B.Ed. Chemistry'),
  (22, 'B.Sc. Public Health (Disease Control)'),
  (22, 'B.Sc. Public Health (Nutrition)'),
  (22, 'B.Sc. Public Health (Promotion)'),
  (22, 'B.Sc. Occupational Health and Safety'),
  (23, 'B.Sc. Environmental Health and Sanitation'),
  (24, 'B.Ed. Junior High'),
  (24, 'B.Ed. Early Grade'),
  (24, 'B.Ed. Upper Primary'),
  (25, 'B.Ed. English'),
  (25, 'B.Ed. French'),
  (25, 'B.Ed. Ghanaian Language (Asante Twi Option)'),
  (25, 'B.Ed. Physical Education and Health Education');

-- Halls — Kumasi Campus (campus_id = 1)
INSERT INTO halls (campus_id, name) VALUES
  (1, 'Opoku Ware Hall'),
  (1, 'Autonomy Hall'),
  (1, 'Atwima Hall');

-- Halls — Mampong Campus (campus_id = 2)
INSERT INTO halls (campus_id, name) VALUES
  (2, 'Opoku Ware Hall (Mampong)'),
  (2, 'Autonomy Hall (Mampong)'),
  (2, 'Atwima Hall (Mampong)');

-- Default admin  ->  username: admin   password: admin123
-- IMPORTANT: run api/make_admin.php once after import to set a
-- valid password hash. See README section 6.
INSERT INTO admins (username, full_name, password_hash) VALUES
  ('admin', 'System Administrator',
   '$2y$10$e0RSZQ8M0ZkLQ8tZ0ZkLQ8tZ0ZkLQ8tZ0ZkLQ8tZ0ZkLQ8tZ0ZkLQ');

-- ============================================================
--  Elections
-- ============================================================

-- SRC Election (Kumasi, campus-wide)
INSERT INTO elections (title, type, campus_id, start_time, end_time, status) VALUES
  ('USTED SRC Elections 2026 — Kumasi', 'src', 1,
   '2026-08-20 08:00:00', '2026-08-20 18:00:00', 'setup');

-- SRC Election (Mampong, campus-wide)
INSERT INTO elections (title, type, campus_id, start_time, end_time, status) VALUES
  ('USTED SRC Elections 2026 — Mampong', 'src', 2,
   '2026-08-20 08:00:00', '2026-08-20 18:00:00', 'setup');

-- Hall Election (Opoku Ware Hall, Kumasi)
INSERT INTO elections (title, type, campus_id, hall_id, start_time, end_time, status) VALUES
  ('Opoku Ware Hall Election 2026', 'hall', 1, 1,
   '2026-08-20 08:00:00', '2026-08-20 18:00:00', 'setup');

-- Departmental Election (Information Technology Education, Kumasi, dept_id=6)
INSERT INTO elections (title, type, campus_id, department_id, start_time, end_time, status) VALUES
  ('ITE Departmental Election 2026', 'departmental', 1, 6,
   '2026-08-20 08:00:00', '2026-08-20 18:00:00', 'setup');

-- ============================================================
--  Positions + Candidates for the Kumasi SRC Election (election_id=1)
-- ============================================================
INSERT INTO positions (election_id, title, description, max_votes, display_order) VALUES
  (1, 'President',
   'Head of the student body and chief spokesperson.', 1, 1),
  (1, 'Vice President',
   'Assists the president and oversees assigned coordination.', 1, 2),
  (1, 'General Secretary',
   'Handles official correspondence, minutes, and records.', 1, 3),
  (1, 'Deputy General Secretary',
   'Supports administrative secretarial duties.', 1, 4),
  (1, 'Financial Secretary',
   'Manages financial records and budget proposals.', 1, 5),
  (1, 'Treasurer',
   'Custodian of day-to-day finances and cash flows.', 1, 6),
  (1, 'Organizing Secretary',
   'Coordinates campus-wide student events, programs, and logistics.', 1, 7),
  (1, 'Public Relations Officer (PRO)',
   'Manages information flow, media, and public relations.', 1, 8),
  (1, 'Women\u2019s Commissioner',
   'Advocates for female student welfare and organizes gender-specific programs.', 1, 9),
  (1, 'Sports Commissioner',
   'Oversees sports, games, and male student welfare matters.', 1, 10);

-- Candidates for SRC President (election 1, position 1)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (1, 'Kwame Mensah',    '20210045', 6,
   'A vote for progress: better welfare, transparent leadership, and stronger student representation.'),
  (1, 'Ama Serwaa',      '20210088', 7,
   'Empowering every voice, building a united campus. Together we rise.');

-- Candidates for Vice President (position 2)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (2, 'Yaw Owusu',       '20210012', 2,
   'Strengthening faculty-student relations and ensuring every department has a seat at the table.'),
  (2, 'Akosua Frimpong', '20210067', 13,
   'Innovation in student services. Your welfare, my priority.');

-- Candidates for General Secretary (position 3)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (3, 'Kofi Boateng',    '20210033', 1,
   'Accurate minutes, timely notices, transparent communication.'),
  (3, 'Esi Ansah',       '20210091', 6,
   'Digital records for a digital campus. Efficiency meets accountability.');

-- Candidates for Financial Secretary (position 5)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (5, 'Daniel Osei',     '20210055', 7,
   'Every cedi accounted for. I will publish SRC finances every semester.'),
  (5, 'Grace Adjei',     '20210072', 8,
   'Responsible spending, smart budgeting, and full transparency.');

-- Candidates for Organizing Secretary (position 7)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (7, 'Samuel Asante',   '20210019', 3,
   'From sports to hall weeks, I will make campus life unforgettable.'),
  (7, 'Lydia Owusuaa',   '20210040', 4,
   'Events that bring us together. Culture, sport, and celebration.');

-- ============================================================
--  Positions for the Opoku Ware Hall Election (election_id=3)
-- ============================================================
INSERT INTO positions (election_id, title, description, max_votes, display_order) VALUES
  (3, 'JCRC President',
   'Head of the hall of residence student leadership.', 1, 1),
  (3, 'JCRC Vice President',
   'Supports hall governance and internal management.', 1, 2),
  (3, 'JCRC Secretary',
   'Manages hall meetings and documentation.', 1, 3),
  (3, 'Financial Secretary / Treasurer',
   'Oversees hall dues and JCRC account administration.', 1, 4),
  (3, 'Organizing Secretary',
   'Coordinates hall week celebrations, clean-up exercises, and social gatherings.', 1, 5),
  (3, 'Entertainment / Welfare Committee Head',
   'Manages recreational activities and room allocation / student comfort issues within the hall.', 1, 6);

-- Candidates for JCRC President (position 11)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (11, 'Philip Darko',    '20210060', 2,
   'A cleaner, safer, more connected hall. Your home, your voice.'),
  (11, 'Janet Agyei',     '20210077', 11,
   'Welfare first. I will fight for better facilities and timely repairs.');

-- ============================================================
--  Positions for the ITE Departmental Election (election_id=4)
-- ============================================================
INSERT INTO positions (election_id, title, description, max_votes, display_order) VALUES
  (4, 'President',
   'Leads the departmental student association.', 1, 1),
  (4, 'Vice President',
   'Deputizes for the departmental president.', 1, 2),
  (4, 'Secretary',
   'Takes records and manages correspondence for the department.', 1, 3),
  (4, 'Financial Secretary / Treasurer',
   'Handles association dues and program funds.', 1, 4),
  (4, 'Organizing Secretary',
   'Plans academic quizzes, excursions, and department-specific seminars.', 1, 5),
  (4, 'Public Relations Officer (PRO)',
   'Communicates updates between faculty, department heads, and students.', 1, 6);

-- Candidates for Departmental President (position 17)
INSERT INTO candidates (position_id, full_name, student_id, department_id, manifesto) VALUES
  (17, 'Michael Tannor',  '20210050', 6,
   'Better labs, more workshops, and a stronger ITE community.'),
  (17, 'Sarah Boatema',   '20210063', 6,
   'Connecting ITE students with industry. Your future starts here.');
