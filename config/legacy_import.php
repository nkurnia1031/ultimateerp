<?php

return [
    'attachments' => [
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LEGACY_ATTACHMENT_ALLOWED_HOSTS', 'jurnal-quickbook.jurnal.id'))
        ))),
        'max_bytes' => (int) env('LEGACY_ATTACHMENT_MAX_BYTES', 5000000),
        'timeout_seconds' => (int) env('LEGACY_ATTACHMENT_TIMEOUT_SECONDS', 30),
        'connect_timeout_seconds' => (int) env('LEGACY_ATTACHMENT_CONNECT_TIMEOUT_SECONDS', 10),
        'allowed_extensions' => ['pdf', 'jpeg', 'jpg', 'png', 'zip', 'xlsx'],
        'mapping_report_directory' => storage_path('app/legacy-import-reports'),
    ],
];
