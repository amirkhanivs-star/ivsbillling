<?php

// Keep Laravel's connection definitions while making reservation expiry longer
// than the 300-second dispatch timeout, including existing .env overrides.
$queue = require base_path('vendor/laravel/framework/config/queue.php');
$queue['connections']['database']['retry_after'] = max(420, (int) env('DB_QUEUE_RETRY_AFTER', 420));

return $queue;
