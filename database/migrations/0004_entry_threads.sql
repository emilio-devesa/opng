-- Open Pastebin NG v1.0: entry threads (F1).
--
-- parent_id links a reply to its direct parent, root_id identifies the whole
-- thread with one index, and depth bounds the tree. Existing rows become
-- roots; legacy "RE: " replies are not linked heuristically because the same
-- topic can be reused and the author may differ.
--
-- The self-referencing foreign key uses ON DELETE SET NULL so deleting one
-- message preserves its replies. root_id intentionally has no foreign key:
-- an orphaned subtree keeps pointing at the deleted root and stays findable.

ALTER TABLE entries
    ADD COLUMN parent_id VARCHAR(255) NULL AFTER id,
    ADD COLUMN root_id VARCHAR(255) NULL AFTER parent_id,
    ADD COLUMN depth TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER root_id;

ALTER TABLE entries
    ADD INDEX idx_entries_parent (parent_id, date, id),
    ADD INDEX idx_entries_root (root_id, date, id);

ALTER TABLE entries
    ADD CONSTRAINT fk_entries_parent
        FOREIGN KEY (parent_id) REFERENCES entries (id)
        ON DELETE SET NULL;

UPDATE entries SET root_id = id, depth = 0 WHERE root_id IS NULL;
