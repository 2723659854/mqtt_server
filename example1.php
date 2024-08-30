<?php


namespace bootstrap;

use Webman\Bootstrap;

/**
 * 测试
 * @link https://www.workerman.net/a/1680
 */
class Mqtt implements Bootstrap
{
    protected static $mqtt = null;

    protected static $connected = false;

    public static function start($worker)
    {
        $mqtt = new \Workerman\Mqtt\Client('mqtt://0.0.0.1:1883', [
            'username' => 'xxx',
            'password' => 'xxx',
            'debug' => true
        ]);
        $mqtt->connect();
        $mqtt->onConnect = function ($mqtt) {
            self::$connected = true;
            if (!empty($mqtt->waitQueue)) {
                foreach ($mqtt->waitQueue as $item) {
                    $mqtt->publish($item[0], $item[1]);
                }
                $mqtt->waitQueue = [];
            }
        };
        static::$mqtt = $mqtt;
    }

    public static function publish($t, $m)
    {
        if (static::$connected === false) {
            static::$mqtt->waitQueue[] = [$t, $m];
            return;
        }
        static::$mqtt->publish($t, $m);
    }
}

// config/bootstap.php

return [

    bootstrap\Mqtt::class,
];

/** ============================================================ */
// process/MqttClient.php


//namespace process;

class MqttClient
{
    protected static $mqtt = null;

    protected static $connected = false;

    public function onWorkerStart()
    {
        $mqtt = new \Workerman\Mqtt\Client('mqtt://0.0.0.1:1883', [
            'username'  => 'xxx',
            'password'  =>  'xxx',
            'debug'     =>  true
        ]);
        $mqtt->connect();
        $mqtt->onConnect = function ($mqtt) {
            self::$connected = true;
        };
        // \Workerman\Timer::add(2, function () use ($mqtt) {
        //     $mqtt->publish('workerman', 'hello workerman mqtt');
        // });
        static::$mqtt = $mqtt;
    }

    public function onMessage($connection, string $data)
    {
        if (self::$connected) {
            // 通过data中的信息动态发布
            $arr = json_decode($data, true);
            self::$mqtt->publish($arr['topic'], $arr['content']);
        }

        $connection->close($data);
    }
}

// config/process.php
//'mqttclient' => [
//    'handler' => process\MqttClient::class,
//    'listen' => 'tcp://0.0.0.0:8789',
//    'count' => 1, // 进程数
//],

/** 推送方式 */
$client = stream_socket_client('tcp://0.0.0.0:8789');

$data = [
    'topic' => $topic,
    'content' => $content,
];
stream_socket_sendto($client, json_encode($data));