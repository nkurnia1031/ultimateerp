<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS `project_task_tags` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_task_id` INT UNSIGNED NOT NULL,
                `tag_id` BIGINT UNSIGNED NOT NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_task_tags_task_id_tag_id_unique` (`project_task_id`, `tag_id`),
                KEY `project_task_tags_project_task_id_index` (`project_task_id`),
                KEY `project_task_tags_tag_id_index` (`tag_id`),
                CONSTRAINT `project_task_tags_project_task_id_foreign`
                    FOREIGN KEY (`project_task_id`) REFERENCES `pjt_project_tasks` (`id`) ON DELETE CASCADE,
                CONSTRAINT `project_task_tags_tag_id_foreign`
                    FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS `project_time_log_tags` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_time_log_id` INT UNSIGNED NOT NULL,
                `tag_id` BIGINT UNSIGNED NOT NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_time_log_tags_log_id_tag_id_unique` (`project_time_log_id`, `tag_id`),
                KEY `project_time_log_tags_project_time_log_id_index` (`project_time_log_id`),
                KEY `project_time_log_tags_tag_id_index` (`tag_id`),
                CONSTRAINT `project_time_log_tags_project_time_log_id_foreign`
                    FOREIGN KEY (`project_time_log_id`) REFERENCES `pjt_project_time_logs` (`id`) ON DELETE CASCADE,
                CONSTRAINT `project_time_log_tags_tag_id_foreign`
                    FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        DB::statement('DROP TABLE IF EXISTS `project_time_log_tags`');
        DB::statement('DROP TABLE IF EXISTS `project_task_tags`');
    }
};
