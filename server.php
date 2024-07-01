<?php


require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Protocols\Mqtt;

/** 保存所有的客户端 */
static $clients = [];
/** 保存每个客户端订阅的频道 $channels[$topic][$fd]=$fd*/
static  $channels;
/** 需要刪除的定時任務 */
$needDeleteJob = [];

$worker = new Worker('mqtt://0.0.0.0:1883');

$worker->onMessage = function (TcpConnection $connection, $data) use (&$clients, &$channels, &$needDeleteJob) {

    // $data就是客户端传来的数据，数据已经经过JsonNL::decode处理过
    $cmd = $data['cmd'] ?: 0;
    $message_id = empty($data['message_id']) ? 0 : $data['message_id'];
    switch ($cmd) {
        /** 链接 */
        case Mqtt::CMD_CONNECT:
            /** 保存客戶端 */
            $clients[$connection->id] = $connection;
            $connection->send(['cmd' => Mqtt::CMD_CONNACK]);
            break;
        /** 订阅 */
        case Mqtt::CMD_SUBSCRIBE:
            $topics = $data['topics'];
            foreach ($topics as $topic=>$value) {
                $channels[$topic][$connection->id] = $connection;
            }

            $connection->send(['cmd' => Mqtt::CMD_SUBACK, 'codes' => [1], 'message_id' => $message_id]);
            break;
        /** 心跳 */
        case Mqtt::CMD_PINGREQ:
            $connection->send(['cmd' => Mqtt::CMD_PINGRESP]);
            break;
        /** 取消订阅 */
        case Mqtt::CMD_UNSUBSCRIBE:
            $topics = $data['topics'];
            foreach ($topics as $topic =>$value) {
                if (isset($channels[$topic][$connection->id])) {
                    unset($channels[$topic][$connection->id]);
                }
            }
            $connection->send(['cmd' => Mqtt::CMD_UNSUBACK, 'message_id' => $message_id]);
            break;

        case Mqtt::CMD_PUBLISH:


            $topic = $data['topic'];

            //var_dump($channels);
            /** 給訂閱了這個項目的所有客戶端發送這個消息 */
            if (isset($channels[$topic])) {
                /** @var TcpConnection $client */
                foreach ($channels[$topic] as $client) {
                    /** todo 放到定時任務裡面，循環發送，直到接收到ACK才不再發送 */
                    if ($client->id != $connection->id) {
                        if (!isset($needDeleteJob[$client->id][$message_id])){
                            $needDeleteJob[$client->id][$message_id] = \Workerman\Timer::add(5, function () use (&$client, $data) {
                                $client->send($data);
                                var_dump("發送數據給客戶端",$client->id,date('Y-m-d H:i:s'));
                            });
                        }
                    }
                }
            }

            /** 返回ack給當前客戶端 */
            $connection->send(['cmd' => Mqtt::CMD_PUBACK, 'message_id' => $message_id]);
            break;

        case Mqtt::CMD_PUBACK:
            /** 刪除任務 */

            var_dump("客戶端返回了ack",$connection->id);
            if (isset($needDeleteJob[$connection->id][$message_id])) {
                var_dump("接收到了這個客戶端的定時任務，刪除",$needDeleteJob[$connection->id][$message_id]);
                \Workerman\Timer::del($needDeleteJob[$connection->id][$message_id]);
                unset($needDeleteJob[$connection->id][$message_id]);
            }

    }

};

$worker->onConnect = function (TcpConnection $connection) {
    var_dump("有客户端请求链接服务器");
};

Worker::runAll();