<?php

namespace Gini\ChemDB;

class Client
{
    private static function getDriver()
    {
        $driver = \Gini\Config::get('app.chemdbclient_driver');
        if (!$driver || !in_array($driver, [
            'rpc',
            'database'
        ])) return 'rpc';
        return $driver;
    }

    public static function __callStatic($methodName, $arguments)
    {
        $driver = self::getDriver();
        $className = "\\Gini\\ChemDB\\Driver\\{$driver}";
        return call_user_func_array([$className, $methodName], $arguments);
    }

}
