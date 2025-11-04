<?php

namespace App\Services;

use Aws\SecretsManager\SecretsManagerClient;

class AwsService
{
    public static function get_secret(string $secret_name): array
    {
        $sm = new SecretsManagerClient([
            'version' => 'latest',
            'region' => 'us-west-1'
        ]);

        try {
            $secret = $sm->getSecretValue(['SecretId' => $secret_name])->get('SecretString');

            return json_decode($secret, true) ?: [];
        } catch (\Throwable $th) {
            return [];
        }
    }
}
