<?php
return array(
    'app' => array(
        'name' => '云盾 VPN 套餐商城',
        'base_url' => getenv('VPN_BASE_URL') ?: 'http://127.0.0.1:8080',
        'timezone' => 'Asia/Shanghai',
        'debug' => getenv('VPN_APP_DEBUG') === '1',
        'temporary_subscription_url' => getenv('VPN_TEMPORARY_SUBSCRIPTION_URL') ?: '',
    ),
    'db' => array(
        'dsn' => getenv('VPN_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=vpn_shop;charset=utf8mb4',
        'username' => getenv('VPN_DB_USER') ?: 'vpn_shop',
        'password' => getenv('VPN_DB_PASSWORD') ?: '',
    ),
    'security' => array(
        'encryption_key' => getenv('VPN_ENCRYPTION_KEY') ?: '',
        'session_name' => 'vpn_shop_session',
    ),
);
