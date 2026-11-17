-- The tool call log keeps the RAW values too: what the assistant was asked to do (arguments) and what came back (result), so anything it lost or got wrong
-- can be read back and re-created. Private DB, per user; results are trimmed (cards/images dropped, 16 KB cap) and secrets (token/password/key fields) are masked
-- before they are written. Pruned with the rest of the log (~180 days). Run AFTER the deploy: kdb < migrations/2026-11-17_tool_call_raw.sql
-- The code tolerates the columns being missing (it logs without them).
ALTER TABLE tool_call_log ADD COLUMN args_json MEDIUMTEXT NULL AFTER error, ADD COLUMN result_json MEDIUMTEXT NULL AFTER args_json;
