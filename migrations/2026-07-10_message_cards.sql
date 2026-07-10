-- Persist a message's interactive card (workout/shopping/weather/agenda/work-hours)
-- as JSON, so reopening a past conversation can re-render the clickable widget,
-- not just the text. Run once on the server DB (kachowdk_ai).

ALTER TABLE messages ADD COLUMN card TEXT NULL AFTER tool_name;
