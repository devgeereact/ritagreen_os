-- 044: staff, workers and portal clients sign in with Clerk (email code).
--
-- users.clerk_user_id is the Clerk user id (the token's `sub`). It is set on a person's first
-- sign-in, when the verified email on the Clerk token matches an active users row. Unique and
-- nullable. Nothing existing is changed or dropped: password_hash, the 2FA columns and the
-- tokens table stay until the owner confirms the old sign-in can go.
--
-- Re-runnable: both statements are guarded with IF NOT EXISTS.
-- Dry run (read-only): SHOW COLUMNS FROM users LIKE 'clerk_user_id';

ALTER TABLE users ADD COLUMN IF NOT EXISTS clerk_user_id VARCHAR(64) NULL DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_clerk_user_id ON users (clerk_user_id);
