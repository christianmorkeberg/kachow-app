-- The last text the assistant read from a web page, per user and URL, so the next fetch_webpage of
-- the same page can say what changed since (website watching: "tell me when it's back in stock").
-- url_hash = sha256 of the normalised URL. text is the readable page text (capped ~100k chars).
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE web_snapshots (
    user_id      INT UNSIGNED NOT NULL,
    url_hash     CHAR(64) NOT NULL,
    url          VARCHAR(2048) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    text         MEDIUMTEXT NOT NULL,
    fetched_at   DATETIME NOT NULL,                 -- UTC
    PRIMARY KEY (user_id, url_hash),
    CONSTRAINT fk_web_snapshots_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
