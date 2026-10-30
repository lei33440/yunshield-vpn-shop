<?php
return array(
    'app' => array(
        'name' => '小云铺加速器',
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
    'update' => array(
        'repository' => getenv('VPN_UPDATE_REPOSITORY') ?: 'lei33440/yunshield-vpn-shop',
        'branch' => getenv('VPN_UPDATE_BRANCH') ?: 'main',
        'enabled' => getenv('VPN_UPDATE_ENABLED') !== '0',
        'cache_seconds' => (int)(getenv('VPN_UPDATE_CACHE_SECONDS') ?: 900),
        'install_enabled' => getenv('VPN_UPDATE_INSTALL_ENABLED') === '1',
        'queue_enabled' => getenv('VPN_UPDATE_QUEUE_ENABLED') === '1',
        'php_cli' => getenv('VPN_UPDATE_PHP_CLI') ?: '',
        'health_url' => getenv('VPN_UPDATE_HEALTH_URL') ?: '',
        'health_token' => getenv('VPN_UPDATE_HEALTH_TOKEN') ?: '',
        'public_key' => getenv('VPN_UPDATE_PUBLIC_KEY') ?: '',
        // 在线更新安全上限：拒绝超出限制的 manifest/ZIP/迁移描述。
        'max_manifest_bytes' => (int)(getenv('VPN_UPDATE_MAX_MANIFEST_BYTES') ?: 1048576),
        'max_signature_bytes' => (int)(getenv('VPN_UPDATE_MAX_SIGNATURE_BYTES') ?: 16384),
        'max_package_bytes' => (int)(getenv('VPN_UPDATE_MAX_PACKAGE_BYTES') ?: 52428800),
        'max_migration_bytes' => (int)(getenv('VPN_UPDATE_MAX_MIGRATION_BYTES') ?: 1048576),
        'backup_keep' => (int)(getenv('VPN_UPDATE_BACKUP_KEEP') ?: 3),
        'package_name_prefix' => 'yunshield-vpn-shop-',
    ),
);
