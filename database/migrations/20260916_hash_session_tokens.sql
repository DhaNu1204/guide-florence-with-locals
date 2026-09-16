-- Step 1.5 (2026-09-16): store sha256(token) in sessions.token instead of the raw token.
-- Raw tokens are also 64 hex characters, so length cannot tell raw from hashed rows;
-- hashed rows carry the marker session_id = 'sha256:<hash>'. Idempotent: only unmarked
-- rows are touched. Run once per environment as part of the step 1.5 deploy, AFTER the
-- code that understands hashed tokens is live (the code also rewrites raw rows on first use).
UPDATE sessions
SET token = SHA2(token, 256),
    session_id = CONCAT('sha256:', SHA2(token, 256))
WHERE session_id NOT LIKE 'sha256:%';

-- Verify: must be 0
-- SELECT COUNT(*) FROM sessions WHERE session_id NOT LIKE 'sha256:%';
