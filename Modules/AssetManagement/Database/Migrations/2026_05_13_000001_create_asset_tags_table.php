<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateAssetTagsTable extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS `asset_tags` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `asset_id` INT(10) UNSIGNED NOT NULL,
                `tag_id` BIGINT(20) UNSIGNED NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `asset_tags_asset_id_tag_id_unique` (`asset_id`, `tag_id`),
                KEY `asset_tags_asset_id_index` (`asset_id`),
                KEY `asset_tags_tag_id_index` (`tag_id`),
                CONSTRAINT `asset_tags_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
                CONSTRAINT `asset_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        DB::statement('DROP TABLE IF EXISTS `asset_tags`');
    }
}
