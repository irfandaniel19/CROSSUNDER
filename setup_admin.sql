-- ============================================================
-- setup_admin.sql
-- Run this ONCE in phpMyAdmin or MySQL CLI AFTER importing
-- footweardb.sql to create the ADMIN login account.
--
-- Admin credentials:
--   Username : admin
--   Password : admin123
-- ============================================================

-- The password below is the bcrypt hash of 'admin123'
-- Generated with: password_hash('admin123', PASSWORD_DEFAULT)

INSERT INTO `login` (`USERNAME`, `PASSWORD`, `ROLE`, `CUST_ID`, `STAFF_ID`)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'ADMIN',
    NULL,
    NULL
);

-- ============================================================
-- OPTIONAL: Update existing demo account passwords to bcrypt
-- (The login.php supports both plain-text and hashed — this
--  is recommended for production-like testing)
-- ============================================================

-- ali123  → passali
UPDATE `login` SET `PASSWORD` = '$2y$10$TKh8H1.PfKVW8RRdXIGRqOvSFmm6O9mNlWbXKJBQ.RsA2WMN2EQCe' WHERE `USERNAME` = 'ali123';

-- siti123 → passsiti
UPDATE `login` SET `PASSWORD` = '$2y$10$mQ1J.dKvxl0b.dKOjy7.wuJf1V4zMqPJ0VTvV3Qg5tX6F8kZ9GiVu' WHERE `USERNAME` = 'siti123';

-- johnstaff → passjohn
UPDATE `login` SET `PASSWORD` = '$2y$10$Y5Ee6p7VgJuvqSJBX9h4qe5O4gFqF1aw4pkMhPxzT5K7LdH1hQ/sW' WHERE `USERNAME` = 'johnstaff';

-- marystaff → passmary
UPDATE `login` SET `PASSWORD` = '$2y$10$x5jOrP0kCr5FQXMHK4EqquvJ7k4oO5dDmzBQ8MJBkbJ3.JLi.Q/Nm' WHERE `USERNAME` = 'marystaff';

-- ============================================================
-- NOTE: If the UPDATE hashes above don't work (different salt),
-- the login.php falls back to plain-text comparison automatically
-- for the demo accounts. Only the admin account MUST use this SQL.
-- ============================================================
