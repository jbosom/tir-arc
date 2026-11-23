PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS clubs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
  cif TEXT UNIQUE, address TEXT, manager_name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT,
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_clubs_name ON clubs(name);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER, name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('admin','club')), active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  last_login_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE RESTRICT,
  CHECK ((role='admin' AND club_id IS NULL) OR (role='club' AND club_id IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS seasons (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, starts_on TEXT NOT NULL,
  ends_on TEXT NOT NULL, fee REAL NOT NULL DEFAULT 0 CHECK(fee >= 0),
  active INTEGER NOT NULL DEFAULT 0 CHECK(active IN (0,1)), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK(ends_on >= starts_on)
);

CREATE TABLE IF NOT EXISTS archers (
  id INTEGER PRIMARY KEY AUTOINCREMENT, current_club_id INTEGER NOT NULL, member_number TEXT UNIQUE,
  first_name TEXT NOT NULL, last_name TEXT NOT NULL, document_id TEXT UNIQUE COLLATE NOCASE,
  birth_date TEXT NOT NULL, email TEXT, phone TEXT, discipline TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','inactive')), joined_on TEXT,
  guardian_name TEXT, guardian_document_id TEXT, guardian_email TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (current_club_id) REFERENCES clubs(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_archers_club ON archers(current_club_id);
CREATE INDEX IF NOT EXISTS idx_archers_name ON archers(last_name,first_name);

CREATE TABLE IF NOT EXISTS registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, archer_id INTEGER NOT NULL, club_id INTEGER NOT NULL,
  season_id INTEGER NOT NULL, type TEXT NOT NULL CHECK(type IN ('new','renewal')),
  status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','pending','approved','rejected')),
  submitted_at TEXT, club_notes TEXT, admin_notes TEXT, reviewed_at TEXT, reviewed_by INTEGER,
  created_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (archer_id) REFERENCES archers(id) ON DELETE RESTRICT,
  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE RESTRICT,
  FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE(archer_id,season_id)
);
CREATE INDEX IF NOT EXISTS idx_reg_club_status ON registrations(club_id,status);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT, registration_id INTEGER NOT NULL UNIQUE,
  amount REAL NOT NULL CHECK(amount >= 0), status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','exempt','cancelled')),
  paid_at TEXT, method TEXT, reference TEXT, notes TEXT, verified_by INTEGER, verified_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS privacy_acceptances (
  id INTEGER PRIMARY KEY AUTOINCREMENT, archer_id INTEGER NOT NULL, registration_id INTEGER,
  legal_text_version TEXT NOT NULL, acceptance_method TEXT NOT NULL, accepted_at TEXT NOT NULL,
  recorded_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (archer_id) REFERENCES archers(id) ON DELETE RESTRICT,
  FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS imports (
  id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER NOT NULL, season_id INTEGER NOT NULL,
  original_filename TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'preview' CHECK(status IN ('preview','confirmed','failed')),
  total_rows INTEGER NOT NULL DEFAULT 0, valid_rows INTEGER NOT NULL DEFAULT 0, error_rows INTEGER NOT NULL DEFAULT 0,
  preview_data TEXT, created_by INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, confirmed_at TEXT,
  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE RESTRICT,
  FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS import_errors (
  id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INTEGER NOT NULL, row_number INTEGER NOT NULL,
  field_name TEXT, message TEXT NOT NULL, raw_data TEXT,
  FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, entity_type TEXT NOT NULL,
  entity_id INTEGER, details TEXT, ip_address TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type,entity_id);

CREATE TABLE IF NOT EXISTS settings (
  setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

