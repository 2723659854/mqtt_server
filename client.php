<?php

require_once __DIR__.'/vendor/autoload.php';

require __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

$count = 0;
$worker = new Worker();
$worker->onWorkerStart = function(){

    $mqtt = new Workerman\Mqtt\Client('mqtt://127.0.0.1:1883');
    $mqtt->onConnect = function($mqtt) {
        global $count;
        $mqtt->subscribe('test');
        $id = \Workerman\Timer::add(3,function ()use($mqtt,&$id){
            global $count;
            $count++;
            var_dump($count);
           $mqtt->publish('test','我是客户端呢',['qos'=>1]);
            if ($count == 10){
                /** 不再订阅这个频道 */
                $mqtt->unsubscribe('test');
                \Workerman\Timer::del($id);
                var_dump("取消订阅");
            }
        });
    };

    $mqtt->onMessage = function($topic, $content)use($mqtt){
        //var_dump($topic, $content);
    };
    $mqtt->connect();
};
Worker::runAll();