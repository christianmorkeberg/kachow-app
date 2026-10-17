-- Location work clock (phase 4 switch-over): days the user fixed by hand, which the automatic
-- clock must never rewrite. A day also counts as manual when it holds any punch that did not
-- come from location (chat, Shortcut) — this table covers the one case that leaves no such
-- trace: the user DELETING a location-made punch. `day` is the local (Europe/Copenhagen) date.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE work_clock_manual_days (
    user_id    INT UNSIGNED NOT NULL,
    day        DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_work_clock_manual_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
