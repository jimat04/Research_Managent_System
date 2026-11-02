-- Give each message a stable identity and persist its conversation root.
-- Legacy message_id values are retained for compatibility; history_id is the
-- authoritative key for new links and conversation membership.
ALTER TABLE `messages`
  ADD COLUMN `history_id` int(10) unsigned NOT NULL AUTO_INCREMENT FIRST,
  ADD PRIMARY KEY (`history_id`),
  ADD COLUMN `thread_id` int(10) unsigned DEFAULT NULL AFTER `history_id`,
  ADD KEY `idx_messages_thread_created` (`thread_id`, `created_at`, `history_id`),
  MODIFY COLUMN `message_id` int(10) unsigned NOT NULL DEFAULT 0;

-- Existing rows had no usable message identity or reply linkage. Keep each as
-- its own conversation root; future replies join the selected root.
UPDATE `messages` SET `thread_id` = `history_id` WHERE `thread_id` IS NULL;
