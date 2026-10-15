<?php

return [
    'enabled' => env('CAP7A_ENABLED', false),
    'commands_enabled' => env('CAP7A_COMMANDS_ENABLED', false),
    'thingsboard_url' => env('CAP7A_TB_URL', 'https://eu.thingsboard.cloud'),
    'device_id' => env('CAP7A_TB_DEVICE_ID'),
    'api_key' => env('CAP7A_TB_API_KEY'),
    // This is a ThingsBoard user with access to the device, NOT its MQTT token.
    'username' => env('CAP7A_TB_USERNAME'),
    'password' => env('CAP7A_TB_PASSWORD'),
    'stale_seconds' => 20,
    'screen_enabled' => env('CAP7A_SCREEN_ENABLED', false),
    'screen_dynamic_url' => env('CAP7A_SCREEN_DYNAMIC_URL', false),
    'screen_ws_url' => env('CAP7A_SCREEN_WS_URL'),
    'screen_gateway_key' => env('CAP7A_SCREEN_GATEWAY_KEY'),
];
