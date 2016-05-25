<?php

namespace Gini\Module;

class ChemDBClient
{
    public static function setup()
    {
    }

    public static function diagnose()
    {
        $errors = [];

        $conf = \Gini\Config::get('chem-db.rpc');
        $url = $conf['url'];
        if (!$url) {
            $errors[] = '请确认chem-db.rpc已经配置了url';
        }

        if (!empty($errors)) return $errors;
    }
}
