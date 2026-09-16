-- Switch login identifier from email to a plain username (no @domain required).
ALTER TABLE users RENAME COLUMN email TO username;
