-- Report #84: the hours JSON was written with the server's long float digits and overflowed VARCHAR(120), so it was cut mid-number and read back as "no hours".
-- Widen the column and drop the damaged rows (they never held usable hours); set the schedule again afterwards.
-- Run AFTER the deploy: kdb < migrations/2026-11-18_work_schedule_fix.sql
ALTER TABLE work_schedule MODIFY hours TEXT NOT NULL;
DELETE FROM work_schedule WHERE hours NOT LIKE '{%}';
