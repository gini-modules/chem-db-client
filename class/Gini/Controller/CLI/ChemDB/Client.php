<?php

namespace Gini\Controller\CLI\ChemDB;

class Client extends \Gini\Controller\CLI
{
    public function actionRefreshAllCache()
    {
        $rpc = \Gini\ChemDB\Client::getRPC();

        $data = $rpc->chemDB->searchChemicals([
            'type'=> [
                'hazardous', 'drug_precursor', 'highly_toxic', 'explosive', 'psychotropic', 'narcotic', 'gas'
            ]
        ]);
        $token = $data['token'];
        if (!$token) {
            return;
        }

        $start = 0;
        $perpage = 20;
        $cacher = \Gini\Cache::of('chemdb');
        $cacheTimeout = \Gini\ChemDB\Client::$cacheTimeout ?: 60;
        $myStart = time();
        while (true) {
            $data = $rpc->chemDB->getChemicals($token, $start, $perpage);
            if (!count($data)) {
                break;
            }
            $start += $perpage;
            foreach ($data as $casNO => $chemical) {
                $cacheKey = "chemical[{$casNO}]";
                $cacher->set($cacheKey, $chemical, $cacheTimeout);
            }
        }
        $myEnd = time();

        /* // 增加这个full-cached的状态，是为了避免缓存穿透. 但是，扩容了chemdb的数据库之后，普通化学品也纳入管理范围，但是我们只是缓存了危化品。普通化学品就会无法获取
        $myDiff = max($myEnd - $myStart, 10);

        $cacheTimeout = max($cacheTimeout - $myDiff, 0);
        if ($cacheTimeout) {
            $cacher->set(\Gini\ChemDB\Client::$fullCacheKey, time(), $cacheTimeout);
        }
         */
    }
}
