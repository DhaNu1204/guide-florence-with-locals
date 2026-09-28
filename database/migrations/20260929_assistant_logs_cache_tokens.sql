-- Step 7.2: prompt-caching token counts per assistant question.
--
-- input_tokens now holds UNCACHED input only (7.1 rows: everything, cache included - there was no
-- caching then). cache_write_tokens = cache_creation_input_tokens, cache_read_tokens =
-- cache_read_input_tokens as reported by the Claude API. The daily cap counts input + output + cache writes + one tenth of cache reads (their price ratio).
-- api/lib/assistant_core.php adds the same columns itself (ensureAssistantTables).
-- Fails with "Duplicate column name" when already applied.

ALTER TABLE assistant_logs
    ADD COLUMN cache_read_tokens INT UNSIGNED NULL AFTER output_tokens,
    ADD COLUMN cache_write_tokens INT UNSIGNED NULL AFTER cache_read_tokens;
