-- Home state cache + soft commands (speed-up after report #66). Additive; run once on kachowdk_ai BEFORE deploying the code that uses it.
--
-- home_state: the latest known state of the home per user (and kind): 'lights' (the Home card's rooms/lights), 'thermostats' (every valve's last reading),
--             'valves' (which valves exist, so they are not rediscovered on every call). A cron (bin/home-state-cron.php, every 5 min — the valves report every
--             5 min) keeps it fresh; reads serve it instead of asking the Pico, which handles ONE command at a time and is the bottleneck.
-- home_agent_commands.expires_at: a command that is fired without waiting for its answer (set_thermostat) stays queued, but only until this time (UTC),
--             so a Pico that was offline never runs it hours late. NULL = the old rule (the waiter expires it when it gives up).

CREATE TABLE home_state (
    user_id    INT UNSIGNED NOT NULL,
    kind       VARCHAR(20) NOT NULL,
    payload    MEDIUMTEXT NOT NULL,                 -- JSON
    updated_at DATETIME NOT NULL,                   -- UTC
    PRIMARY KEY (user_id, kind),
    CONSTRAINT fk_hs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE home_agent_commands ADD COLUMN expires_at DATETIME NULL;
