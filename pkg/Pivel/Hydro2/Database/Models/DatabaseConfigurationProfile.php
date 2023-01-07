<?php

namespace Package\Pivel\Hydro2\Database\Models;

class DatabaseConfigurationProfile
{
    public function __construct(
        public string $Key,
        public string $Driver,
        public string $Host,
        public ?string $Username = null,
        public ?string $Password = null,
        public ?string $DatabaseSchema = null,
    ) {

    }

    /**
     * @return DatabaseConfigurationProfile[]
     */
    public static function GetAll() : array {
        if (!file_exists(dirname(__FILE__, 2) . '/config.json')) {
            $defaultConfig = new DatabaseConfigurationProfile('primary', 'sqlite', 'default.sqlite3', null, null, null);
            $defaultConfig->Save();
        }

        $raw_config = file_get_contents(dirname(__FILE__, 2) . '/config.json');
        /** @var array[] */
        $config = json_decode($raw_config, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            return [];
        }

        return array_map(function($k) use ($config) : DatabaseConfigurationProfile {
            return new self(
                $k,
                $config[$k]['driver'],
                $config[$k]['host'],
                $config[$k]['username']??null,
                $config[$k]['password']??null,
                $config[$k]['databaseschema']??null,
            );
        }, array_keys($config));
    }

    public static function LoadFromKey(string $key) : ?self {
        if (!file_exists(dirname(__FILE__, 2) . '/config.json')) {
            $defaultConfig = new DatabaseConfigurationProfile('primary', 'sqlite', 'default.sqlite3', null, null, null);
            $defaultConfig->Save();
        }

        $raw_config = file_get_contents(dirname(__FILE__, 2) . '/config.json');
        /** @var array[] */
        $config = json_decode($raw_config, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            return null;
        }

        if (!isset($config[$key]) || $config[$key] === null) {
            return null;
        }

        return new self(
            $key,
            $config[$key]['driver'],
            $config[$key]['host'],
            $config[$key]['username']??null,
            $config[$key]['password']??null,
            $config[$key]['databaseschema']??null,
        );
    }

    public function Save() : void {
        $config = [];
        if (file_exists(dirname(__FILE__, 2) . '/config.json')) {
            $raw_config = file_get_contents(dirname(__FILE__, 2) . '/config.json');
            /** @var array[] */
            $cconfig = json_decode($raw_config, true);
            if (json_last_error() != JSON_ERROR_NONE) {
                $config = [];
            }
        }

        $config[$this->Key] = [
            'driver' => $this->Driver,
            'host' => $this->Host,
            'username' => $this->Username,
            'password' => $this->Password,
            'databasescheme' => $this->DatabaseSchema,
        ];

        file_put_contents(dirname(__FILE__, 2) . '/config.json', json_encode($config));
    }
}