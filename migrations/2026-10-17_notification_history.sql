-- Notification history: every push the app sends is also kept here (type, title, text, where a tap
-- leads), so a missed or dismissed push can still be read and opened from the bell in the app.
-- read_at is set when the user opens the entry (or the thing it points to). Entries older than 60
-- days are removed opportunistically. Run once on the server DB (kachowdk_ai).

CREATE TABLE notification_history (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(32)  NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT         NOT NULL,
    url        VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user_recent (user_id, id),
    KEY idx_user_unread (user_id, read_at),
    CONSTRAINT fk_notif_hist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
