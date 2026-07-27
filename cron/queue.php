<?php

chdir(__DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->call('queue:work', [
    '--queue' => 'imports',
    '--tries' => 1,
    '--timeout' => 0,
    '--stop-when-empty' => true,
]);

echo $kernel->output();

exit($status);