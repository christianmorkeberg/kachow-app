-- Per-list control over automatic category grouping (dev follow-up to #42's
-- GroceryCategories/PackingCategories work): some lists (a to-do/ideas list) should
-- never be grouped into categories at all. 'auto' (default) keeps today's behaviour
-- (name-based: packing lists group by packing categories, everything else by grocery
-- aisles); 'none' always renders flat. Run once on the server DB (kachowdk_ai).

ALTER TABLE shared_lists
    ADD COLUMN grouping VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER is_default;
