<?php

use App\Services\AwsService;

if (!function_exists('set_execution_limits')) {

    function set_execution_limits(
        int|string $memory = '4G',
        int $execution_time = 7200
    ): bool {
        return ini_set('memory_limit', $memory)
            || ini_set('max_execution_time', $execution_time);
    }
}

if (!function_exists('get_db_config')) {

    function get_db_config(?string $secret_name): array
    {
        if (!$secret_name || env('APP_ENV') !== 'production') return [];

        $config = AwsService::get_secret($secret_name);

        return [
            'read' => ['host' => [$config['hostreader']]],
            'write' => ['host' => [$config['host']]],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
        ];
    }
}
