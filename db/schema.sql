-- SIMRAD: skema basis data (MySQL/MariaDB), 11 tabel, rancangan Deliverable B3.
-- Perubahan terhadap B3 (dicatat di log revisi): app_user + password_hash (B4: kata sandi di-hash),
-- indeks tambahan untuk worklist dan jejak audit.
CREATE DATABASE IF NOT EXISTS simrad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE simrad;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS workflow_event, report, instance, series, study, imaging_order, modality, `procedure`, patient, app_user, role;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE role (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  nama_role VARCHAR(30) NOT NULL UNIQUE
);
CREATE TABLE app_user (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  role_id INT NOT NULL,
  FOREIGN KEY (role_id) REFERENCES role (role_id)
);
CREATE TABLE patient (
  patient_id INT AUTO_INCREMENT PRIMARY KEY,
  no_rm VARCHAR(20) NOT NULL UNIQUE,
  nik CHAR(16) NULL,
  nama VARCHAR(100) NOT NULL,
  tanggal_lahir DATE NOT NULL,
  jenis_kelamin CHAR(1) NULL
);
CREATE TABLE `procedure` (
  procedure_id INT AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(20) NOT NULL UNIQUE,
  nama_pemeriksaan VARCHAR(100) NOT NULL,
  jenis_modalitas ENUM('CT','MR','DX','US') NOT NULL
);
CREATE TABLE modality (
  modality_id INT AUTO_INCREMENT PRIMARY KEY,
  kode_alat VARCHAR(20) NOT NULL UNIQUE,
  jenis ENUM('CT','MR','DX','US') NOT NULL,
  lokasi VARCHAR(50) NULL,
  status ENUM('ONLINE','PERLU_CEK','OFFLINE') NOT NULL DEFAULT 'ONLINE'
);
CREATE TABLE imaging_order (
  order_id INT AUTO_INCREMENT PRIMARY KEY,
  accession_no VARCHAR(20) NOT NULL UNIQUE,
  patient_id INT NOT NULL,
  procedure_id INT NOT NULL,
  requester_id INT NOT NULL,
  prioritas ENUM('CITO','REGULER') NOT NULL,
  status ENUM('ORDERED','SCHEDULED','IN_PROGRESS','COMPLETED','REPORTED') NOT NULL DEFAULT 'ORDERED',
  ordered_at DATETIME NOT NULL,
  FOREIGN KEY (patient_id) REFERENCES patient (patient_id),
  FOREIGN KEY (procedure_id) REFERENCES `procedure` (procedure_id),
  FOREIGN KEY (requester_id) REFERENCES app_user (user_id),
  INDEX idx_order_worklist (status, prioritas, ordered_at)
);
CREATE TABLE study (
  study_id INT AUTO_INCREMENT PRIMARY KEY,
  study_instance_uid VARCHAR(64) NOT NULL UNIQUE,
  order_id INT NOT NULL,
  modality_id INT NOT NULL,
  performed_by INT NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  status ENUM('IN_PROGRESS','COMPLETED','REPORTED') NOT NULL,
  FOREIGN KEY (order_id) REFERENCES imaging_order (order_id),
  FOREIGN KEY (modality_id) REFERENCES modality (modality_id),
  FOREIGN KEY (performed_by) REFERENCES app_user (user_id)
);
CREATE TABLE series (
  series_id INT AUTO_INCREMENT PRIMARY KEY,
  series_instance_uid VARCHAR(64) NOT NULL UNIQUE,
  study_id INT NOT NULL,
  series_number INT NOT NULL,
  body_part VARCHAR(30) NULL,
  FOREIGN KEY (study_id) REFERENCES study (study_id)
);
CREATE TABLE instance (
  instance_id INT AUTO_INCREMENT PRIMARY KEY,
  sop_instance_uid VARCHAR(64) NOT NULL UNIQUE,
  series_id INT NOT NULL,
  instance_number INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  FOREIGN KEY (series_id) REFERENCES series (series_id)
);
CREATE TABLE report (
  report_id INT AUTO_INCREMENT PRIMARY KEY,
  study_id INT NOT NULL,
  author_id INT NOT NULL,
  jenis ENUM('AWAL','ADDENDUM') NOT NULL,
  isi_temuan TEXT NULL,
  kesimpulan TEXT NOT NULL,
  status ENUM('DRAFT','FINAL') NOT NULL DEFAULT 'DRAFT',
  finalized_at DATETIME NULL,
  FOREIGN KEY (study_id) REFERENCES study (study_id),
  FOREIGN KEY (author_id) REFERENCES app_user (user_id)
);
CREATE TABLE workflow_event (
  event_id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  actor_id INT NOT NULL,
  jenis_event VARCHAR(30) NOT NULL,
  event_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES imaging_order (order_id),
  FOREIGN KEY (actor_id) REFERENCES app_user (user_id),
  INDEX idx_event_order (order_id, event_at)
);
