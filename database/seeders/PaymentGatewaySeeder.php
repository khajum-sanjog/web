<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Map keys to their descriptive labels
        $keyLabels = [
            'publishable_key' => 'Publishable Key',
            'secret_key' => 'Secret Key',
            'login_id' => 'Login ID',
            'transaction_key' => 'Transaction Key',
            'client_key' => 'Client Key',
            'gMerchant_id' => 'Google Merchant ID',
            'aMerchant_id' => 'Apple Merchant ID',
            'reader_id' => 'Reader ID',
            'paymentGateway_id' => 'Payment Gateway ID',
        ];

        // All gateways (primary and wallet, ensuring unique names)
        $gateways = [
            [
                'name' => 'Stripe',
                'description' => '',
                'required_keys' => json_encode([
                    ['label' => 'Publishable Key', 'value' => 'publishable_key'],
                    ['label' => 'Secret Key', 'value' => 'secret_key']
                ]),
                'image' => '',
                'status' => '1',
                'type' => '0', // Primary gateway
                'slug' => null,
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'required_keys' => ['publishable_key', 'secret_key'],
            ],
            [
                'name' => 'Authorize.net',
                'description' => '',
                'required_keys' => json_encode([
                    ['label' => 'Login ID', 'value' => 'login_id'],
                    ['label' => 'Transaction Key', 'value' => 'transaction_key'],
                    ['label' => 'Client Key', 'value' => 'client_key']
                ]),
                'image' => '',
                'status' => '1',
                'type' => '0', // Primary gateway
                'slug' => null,
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'required_keys' => ['login_id', 'transaction_key', 'client_key'],
            ],
            [
                'name' => 'Google Pay',
                'description' => '',
                'required_keys' => json_encode([
                    ['label' => 'Google Merchant ID', 'value' => 'gMerchant_id']
                ]),
                'image' => '',
                'status' => '1',
                'type' => '1', // Wallet gateway
                'slug' => 'has_google_pay',
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'required_keys' => [
                    'Stripe' => ['gMerchant_id'],
                    'Authorize.net' => ['gMerchant_id', 'paymentGateway_id'],
                ],
                'parents' => ['Stripe', 'Authorize.net'],
            ],
            [
                'name' => 'Apple Pay',
                'description' => '',
                'required_keys' => json_encode([
                    ['label' => 'Apple Merchant ID', 'value' => 'aMerchant_id']
                ]),
                'image' => '',
                'status' => '1',
                'type' => '1', // Wallet gateway
                'slug' => 'has_apple_pay',
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'required_keys' => [
                    'Stripe' => ['aMerchant_id'],
                    'Authorize.net' => ['aMerchant_id'],
                ],
                'parents' => ['Stripe', 'Authorize.net'],
            ],
            [
                'name' => 'Pos Pay',
                'description' => '',
                'required_keys' => json_encode([
                    ['label' => 'Reader ID', 'value' => 'reader_id']
                ]),
                'image' => '',
                'status' => '1',
                'type' => '1', // Wallet gateway
                'slug' => 'has_pos_pay',
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'required_keys' => [
                    'Stripe' => ['reader_id'],
                ],
                'parents' => ['Stripe'],
            ],
            [
                'name' => 'Apple Pay',
                'description' => '',
                'required_keys' => json_encode([
                    ['label' => 'Apple Merchant ID', 'value' => 'aMerchant_id']
                ]),
                'image' => '',
                'status' => '1',
                'type' => '1', // Wallet gateway
                'slug' => 'has_card_pay',
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'required_keys' => [
                    'Stripe' => [],
                    'Authorize.net' => [],
                ],
                'parents' => ['Stripe', 'Authorize.net'],
            ],
        ];

        // Insert or update gateways and get their IDs
        $gatewayIds = [];
        foreach ($gateways as $gateway) {
            $requiredKeys = $gateway['required_keys'];
            $parents = $gateway['parents'] ?? [];
            unset($gateway['required_keys'], $gateway['parents']); // Remove non-column fields

            // Insert into payment_gateways
            DB::table('payment_gateways')->updateOrInsert(
                [
                    'name' => $gateway['name'],
                    'slug' => $gateway['slug'],
                ],
                $gateway
            );
            $id = DB::table('payment_gateways')->where([
                'name' => $gateway['name'],
                'slug' => $gateway['slug'],
            ])->value('id');
            $gatewayIds[$gateway['name']] = $id;

            // Seed required_keys and parent relationships in payment_gateway_keys
            if (empty($parents)) {
                // Primary gateways: no parent
                foreach ($requiredKeys as $key) {
                    DB::table('payment_gateway_keys')->updateOrInsert(
                        [
                            'payment_gateway_id' => $id,
                            'key_name' => $keyLabels[$key] ?? str_replace('_', ' ', ucwords($key, '_')),
                            'parent' => null,
                        ],
                        [
                            'payment_gateway_id' => $id,
                            'parent' => null,
                            'key_name' => $keyLabels[$key] ?? str_replace('_', ' ', ucwords($key, '_')),
                            'value' => $key,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            } else {
                // Wallet gateways: keys and relationships per parent
                foreach ($parents as $parentName) {
                    $parentId = $gatewayIds[$parentName];
                    $keys = $requiredKeys[$parentName] ?? [];

                    // Insert keys for this parent
                    foreach ($keys as $key) {
                        DB::table('payment_gateway_keys')->updateOrInsert(
                            [
                                'payment_gateway_id' => $id,
                                'key_name' => $keyLabels[$key] ?? str_replace('_', ' ', ucwords($key, '_')),
                                'parent' => (string) $parentId,
                            ],
                            [
                                'payment_gateway_id' => $id,
                                'parent' => (string) $parentId,
                                'key_name' => $keyLabels[$key] ?? str_replace('_', ' ', ucwords($key, '_')),
                                'value' => $key,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }

                    // Add parent relationship even if no keys
                    if (empty($keys)) {
                        DB::table('payment_gateway_keys')->updateOrInsert(
                            [
                                'payment_gateway_id' => $id,
                                'key_name' => null,
                                'parent' => (string) $parentId,
                            ],
                            [
                                'payment_gateway_id' => $id,
                                'parent' => (string) $parentId,
                                'key_name' => null,
                                'value' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }
            }
        }
    }
}
