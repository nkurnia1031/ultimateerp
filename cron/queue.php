<?php

chdir(__DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->call('queue:work', [
    // Solar import/preview jobs use the dedicated `solar` queue. Keep the
    // existing imports queue in the same worker so the cron entry point can
    // continue handling both kinds of background work.
    '--queue' => 'solar,imports,default',
    '--tries' => 1,
    '--timeout' => 0,
    '--stop-when-empty' => true,
]);

echo $kernel->output();

exit($status);
