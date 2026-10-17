-- One-off cleanup after report #39: a day's workout plan was written twice (once as bare exercise names,
-- once with targets) because create_workout_plan always appended. The code now refuses to add an exercise
-- that is already in the day's plan; this removes the copies that were already stored.
--
-- Only redundant, untouched rows go (not ticked off, nothing logged from them):
--   1) a bare row (no sets/reps/weight/note) whose exercise also appears in the SAME plan with targets or a note
--   2) exact copies within the same plan (same name, sets, reps, weight and note) — the lowest id is kept
-- Names are compared ignoring case and surrounding spaces. A misspelt twin ("hip trust" vs "hip thrust") is a
-- different name and stays — remove that one by hand. Safe to run more than once. Run on the server DB (kachowdk_ai).
-- (DISTINCT in the inner selects keeps MySQL from merging the derived table into the DELETE, which would raise error 1093.)

DELETE FROM workout_plan_items
WHERE id IN (
    SELECT id FROM (
        SELECT DISTINCT b.id AS id
        FROM workout_plan_items b
        JOIN workout_plan_items t
          ON t.plan_id = b.plan_id
         AND t.id <> b.id
         AND LOWER(TRIM(t.exercise)) = LOWER(TRIM(b.exercise))
        WHERE b.done = 0 AND b.logged = 0
          AND b.target_sets IS NULL AND b.target_reps IS NULL AND b.target_weight IS NULL
          AND (b.note IS NULL OR b.note = '')
          AND (t.target_sets IS NOT NULL OR t.target_reps IS NOT NULL OR t.target_weight IS NOT NULL
               OR (t.note IS NOT NULL AND t.note <> ''))
    ) AS bare_copies
);

DELETE FROM workout_plan_items
WHERE id IN (
    SELECT id FROM (
        SELECT DISTINCT b.id AS id
        FROM workout_plan_items b
        JOIN workout_plan_items t
          ON t.plan_id = b.plan_id
         AND t.id < b.id
         AND LOWER(TRIM(t.exercise)) = LOWER(TRIM(b.exercise))
         AND (t.target_sets = b.target_sets OR (t.target_sets IS NULL AND b.target_sets IS NULL))
         AND (t.target_reps = b.target_reps OR (t.target_reps IS NULL AND b.target_reps IS NULL))
         AND (t.target_weight = b.target_weight OR (t.target_weight IS NULL AND b.target_weight IS NULL))
         AND COALESCE(t.note, '') = COALESCE(b.note, '')
        WHERE b.done = 0 AND b.logged = 0
    ) AS exact_copies
);
