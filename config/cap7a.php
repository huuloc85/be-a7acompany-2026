<?php

$systems = [];
foreach (['tail', 'spike'] as $system) {
    $prefix = 'CAP7A_'.strtoupper($system).'_';
    $systems[$system] = [
        'transport' => env($prefix.'TRANSPORT', 'thingsboard'),
        'enabled' => env($prefix.'ENABLED', false),
        'commands_enabled' => env($prefix.'COMMANDS_ENABLED', false),
        'command_key' => $system === 'spike' ? env($prefix.'COMMAND_KEY') : null,
        'thingsboard_url' => env($prefix.'TB_URL', 'https://eu.thingsboard.cloud'),
        'device_id' => env($prefix.'TRANSPORT', 'thingsboard') === 'iot'
            ? env($prefix.'IOT_DEVICE_ID') : env($prefix.'TB_DEVICE_ID'),
        'api_key' => env($prefix.'TB_API_KEY'),
        'username' => env($prefix.'TB_USERNAME'),
        'password' => env($prefix.'TB_PASSWORD'),
        'stale_seconds' => 20,
        'screen_enabled' => env($prefix.'SCREEN_ENABLED', false),
        'screen_dynamic_url' => env($prefix.'SCREEN_DYNAMIC_URL', false),
        'screen_ws_url' => env($prefix.'SCREEN_WS_URL'),
        'screen_gateway_key' => env($prefix.'SCREEN_GATEWAY_KEY'),
    ];
}

$systems['tail']['lifecycle_enabled'] = env('CAP7A_TAIL_LIFECYCLE_ENABLED', false);
$systems['tail']['lifecycle_device_id'] = env('CAP7A_TAIL_TRANSPORT', 'thingsboard') === 'iot'
    ? env('CAP7A_TAIL_IOT_LIFECYCLE_DEVICE_ID') : env('CAP7A_TAIL_LIFECYCLE_DEVICE_ID');
$systems['tail']['lifecycle_key'] = env('CAP7A_TAIL_LIFECYCLE_KEY');
$systems['spike']['lifecycle_enabled'] = env('CAP7A_SPIKE_LIFECYCLE_ENABLED', false);
$systems['spike']['lifecycle_device_id'] = env('CAP7A_SPIKE_IOT_LIFECYCLE_DEVICE_ID');
$systems['spike']['lifecycle_key'] = env('CAP7A_SPIKE_LIFECYCLE_KEY');

return ['systems' => $systems];
