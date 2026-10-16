-- Home agent: a small device on the home network (a Raspberry Pi Pico W) that makes OUTBOUND
-- calls to the app, picks up queued commands, runs them against LAN devices (the Hue bridge,
-- later thermostats) and posts the answer back. The server never connects into the home network.
-- Run once on the server DB (kachowdk_ai).
--
-- status: pending (queued) -> sent (the agent took it) -> done | failed;
--         expired = nobody answered in time (set by the caller that was waiting, never by a clock).

CREATE TABLE home_agent_commands (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    device      VARCHAR(32)  NOT NULL,
    method      VARCHAR(8)   NOT NULL,
    path        VARCHAR(255) NOT NULL,
    body        TEXT NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'pending',
    http_status SMALLINT NULL,
    response    MEDIUMTEXT NULL,
    error       VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at     TIMESTAMP NULL,
    done_at     TIMESTAMP NULL,
    CONSTRAINT fk_home_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_home_agent_pending (user_id, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
