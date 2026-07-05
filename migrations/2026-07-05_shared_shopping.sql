-- Shared shopping lists: named lists jointly owned by the two people on a
-- connection, both read/write. Run once on the server DB (kachowdk_ai).
--
-- No FK on connection_id (it references user_connections.id, whose exact type we
-- don't need to match since access is enforced in app code). The only FK is
-- between these two tables, with matching INT types, so no errno-150 surprises.

CREATE TABLE shared_lists (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    name          VARCHAR(64) NOT NULL,
    is_default    TINYINT(1) NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shared_lists_conn (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shared_list_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    list_id    INT NOT NULL,
    item       VARCHAR(255) NOT NULL,
    checked    TINYINT(1) NOT NULL DEFAULT 0,
    added_by   INT UNSIGNED NOT NULL,
    checked_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_items_list FOREIGN KEY (list_id) REFERENCES shared_lists(id) ON DELETE CASCADE,
    INDEX idx_items_list (list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
