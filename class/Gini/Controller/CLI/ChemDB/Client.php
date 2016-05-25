<?php

namespace Gini\Controller\CLI\ChemDB;

class Client extends \Gini\Controller\CLI
{
    public function actionRefreshAllCache()
    {
        $rpc = \Gini\ChemDB\Client::getRPC();

        $data = $rpc->chemDB->searchChemicals([]);
        $token = $data['token'];
        if (!$token) return;

        $start = 0;
        $perpage = 20;
        $cacher = \Gini\Cache::of('chemdb');
        while (true) {
            $data = $rpc->chemDB->getChemicals($token, $start, $perpage);
            if (!count($data)) break;
            $start += $perpage;
            foreach ($data as $casNO=>$chemical) {
                $cacheKey = "chemical[{$casNO}]";
                $cacher->set($cacheKey, $chemical, \Gini\ChemDB\Client::$cacheTimeout?:60);
            }
        }
    }
}
