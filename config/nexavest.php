<?php

return [
    'temporary_password_reset_enabled' => (bool) env('TEMPORARY_PASSWORD_RESET_ENABLED', true),
    'webhook_secret' => env('SANDBOX_WEBHOOK_SECRET'),
    'scheduler_enabled' => (bool) env('SIMULATION_SCHEDULER_ENABLED', false),
    'local_roi_scheduler_enabled' => (bool) env('LOCAL_ROI_SCHEDULER_ENABLED', true),
    'demo_password' => env('DEMO_PASSWORD'),
];
