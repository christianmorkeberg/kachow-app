-- Per-user exercise-name canonicalisation. Exercise names are free text, so the same
-- lift fragments across language/spelling variants (Deadlift / Dødløft / Dead lift,
-- Squat / Squats / Backsquat). This maps a normalised alias -> the user's chosen
-- canonical name so future logs land consistently and history/progression dedupe.
-- alias_norm is lowercased/trimmed/space-collapsed (App\Data\ExerciseAliases::normalize).
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE exercise_aliases (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    alias_norm VARCHAR(190) NOT NULL,
    canonical  VARCHAR(190) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_alias (user_id, alias_norm),
    CONSTRAINT fk_exalias_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
