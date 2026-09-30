<?php
class Database
{
    private static $pdo;
    public static function connect($config)
    {
        if (!self::$pdo) {
            self::$pdo = new PDO($config['db']['dsn'], $config['db']['username'], $config['db']['password'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ));
        }
        return self::$pdo;
    }
}
