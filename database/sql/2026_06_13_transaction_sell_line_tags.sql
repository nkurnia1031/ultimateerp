CREATE TABLE IF NOT EXISTS `transaction_sell_line_tags` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_sell_line_id` INT UNSIGNED NOT NULL,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `transaction_sell_line_tags_line_id_tag_id_unique` (`transaction_sell_line_id`, `tag_id`),
    KEY `transaction_sell_line_tags_transaction_sell_line_id_index` (`transaction_sell_line_id`),
    KEY `transaction_sell_line_tags_tag_id_index` (`tag_id`),
    CONSTRAINT `transaction_sell_line_tags_line_id_foreign`
        FOREIGN KEY (`transaction_sell_line_id`) REFERENCES `transaction_sell_lines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `transaction_sell_line_tags_tag_id_foreign`
        FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO transaction_sell_line_tags (transaction_sell_line_id, tag_id)
SELECT DISTINCT tsl.id, tt.tag_id
FROM transaction_tags tt
INNER JOIN transactions t
    ON t.id = tt.transaction_id
    AND t.type = 'sell'
INNER JOIN transaction_sell_lines tsl
    ON tsl.transaction_id = t.id
    AND tsl.parent_sell_line_id IS NULL;

INSERT IGNORE INTO transaction_sell_line_tags (transaction_sell_line_id, tag_id)
SELECT DISTINCT child.id, tt.tag_id
FROM transaction_tags tt
INNER JOIN transactions t
    ON t.id = tt.transaction_id
    AND t.type = 'sell'
INNER JOIN transaction_sell_lines parent
    ON parent.transaction_id = t.id
    AND parent.parent_sell_line_id IS NULL
INNER JOIN transaction_sell_lines child
    ON child.parent_sell_line_id = parent.id;

DELETE tt
FROM transaction_tags tt
INNER JOIN transactions t
    ON t.id = tt.transaction_id
WHERE t.type = 'sell';

-- Validation helpers:
-- SELECT COUNT(*) AS legacy_sell_transaction_tags
-- FROM transaction_tags tt
-- INNER JOIN transactions t ON t.id = tt.transaction_id
-- WHERE t.type = 'sell';
--
-- SELECT COUNT(*) AS sell_line_tag_rows
-- FROM transaction_sell_line_tags tslt
-- INNER JOIN transaction_sell_lines tsl ON tsl.id = tslt.transaction_sell_line_id
-- INNER JOIN transactions t ON t.id = tsl.transaction_id
-- WHERE t.type = 'sell';
