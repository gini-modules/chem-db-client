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

        $conf = \Gini\Config::get('app.rpc');
        $url = @$conf['chemdb']['url'];
        if (!$url) {
            $errors[] = '请确认app.rpc已经配置了chemdb.url';
        }

        $errors[] = "请确保cron.yml配置了定时刷新的命令\n\t\tchem-db-client:\n\t\t\tinterval: '* */4 * * *'\n\t\t\tcommand: chemdb client refreshallcache\n\t\t\tcomment: 定时将刷新chemdb的redis缓存";

        if (!empty($errors)) {
            return $errors;
        }
    }
}
