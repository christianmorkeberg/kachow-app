-- Scheduled tasks: more schedule shapes and a "tell me every time" flag.
--   frequency  + 'minutes' (every N minutes, see interval_minutes), 'weekdays' (Mon-Fri at
--              time_of_day), 'once' (a single run at next_run_at, then status 'done')
--   status     + 'done' (a finished one-shot)
--   interval_minutes  the N for 'minutes' (the tool only accepts 5-1440)
--   notify_always     1 = the run reports what it did every time (a routine that ACTS, like
--                     turning the lights on if you are home); 0 = silent unless it finds something
-- Additive, and safe to run BEFORE the new code is pushed (old code ignores the new columns).
-- Run it first: the new code selects the new columns.
-- Run once on the server DB (kachowdk_ai).

ALTER TABLE scheduled_tasks
    MODIFY frequency ENUM('minutes','hourly','daily','weekdays','weekly','once') NOT NULL,
    MODIFY status ENUM('active','cancelled','done') NOT NULL DEFAULT 'active',
    ADD COLUMN interval_minutes SMALLINT UNSIGNED NULL AFTER day_of_week,
    ADD COLUMN notify_always TINYINT(1) NOT NULL DEFAULT 0 AFTER interval_minutes;
