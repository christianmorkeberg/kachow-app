-- Questions the recruiter assistant could not answer from the facts (the lookup found nothing), so the owner can see what to add next. Part of the chat data:
-- deleted with the chat log after 14 days. Run once on the server DB (kachowdk_ai):  kdb < migrations/2026-11-08_recruiter_gaps.sql
-- Until it is run the chat works as before; it just does not record these.
CREATE TABLE recruiter_gaps (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id      INT UNSIGNED NOT NULL,
    question     TEXT NOT NULL,
    topic        VARCHAR(160) NOT NULL,
    tool         VARCHAR(30) NOT NULL,
    dismissed_at DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rg_link (link_id, id),
    CONSTRAINT fk_rg_link FOREIGN KEY (link_id) REFERENCES recruiter_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
