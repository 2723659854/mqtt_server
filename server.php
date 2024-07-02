<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Protocols\Mqtt;

/** 保存所有的客户端 */
static $clients = [];
/** 保存每个客户端订阅的频道 $channels[$topic][$fd]=$fd*/
static $channels;
/** 需要刪除的定時任務 */
$needDeleteJob = [];
/** 创建服务器 */
$worker = new Worker('mqtt://0.0.0.0:1883');
/** 处理客户端逻辑 */
$worker->onMessage = function (TcpConnection $connection, $data) use (&$clients, &$channels, &$needDeleteJob) {
    /** 解析命令 */
    $cmd = $data['cmd'] ?: 0;
    /** 解析消息ID，在mqtt协议中，每一条消息都需要回复，而且必须按照ID回复 */
    $message_id = empty($data['message_id']) ? 0 : $data['message_id'];
    /** 根据命令处理逻辑 */
    switch ($cmd) {
        /** 客户端请求链接 */
        case Mqtt::CMD_CONNECT:
            /** 保存客戶端 */
            $clients[$connection->id] = $connection;
            /** 发送链接成功 */
            $connection->send(['cmd' => Mqtt::CMD_CONNACK]);
            break;
        /** 批量订阅频道 */
        case Mqtt::CMD_SUBSCRIBE:
            /** 需要订阅的频道 */
            $topics = $data['topics'];
            foreach ($topics as $topic => $value) {
                $channels[$topic][$connection->id] = $connection;
            }
            /** 发送订阅确认，要求客户端回复1次ack */
            $connection->send(['cmd' => Mqtt::CMD_SUBACK, 'codes' => [1], 'message_id' => $message_id]);
            break;
        /** 心跳 */
        case Mqtt::CMD_PINGREQ:
            $connection->send(['cmd' => Mqtt::CMD_PINGRESP]);
            break;
        /** 取消订阅 */
        case Mqtt::CMD_UNSUBSCRIBE:
            $topics = $data['topics'];
            foreach ($topics as $topic => $value) {
                if (isset($channels[$topic][$connection->id])) {
                    unset($channels[$topic][$connection->id]);
                }
            }
            $connection->send(['cmd' => Mqtt::CMD_UNSUBACK, 'message_id' => $message_id]);
            break;

        /** 發佈消息 */
        case Mqtt::CMD_PUBLISH:
            /** qos级别 客户端要求回复ack   */
            $qos = $data['qos'];
            /** 需要发送消息的频道 */
            $topic = $data['topic'];
            /** 給訂閱了這個項目的所有客戶端發送這個消息 */
            if (isset($channels[$topic])) {
                /** @var TcpConnection $client */
                foreach ($channels[$topic] as $client) {
                    /**  放到定時任務裡面，循環發送，直到接收到ACK才不再發送 */
                    if ($client->id != $connection->id) {
                        /** 需要回复ack确认 */
                        if ($qos > 0) {
                            /** messageID必定唯一，不会存在重复 ，这个由客户端控制 */
                            if (!isset($needDeleteJob[$client->id][$message_id])) {
                                /** 使用定时任务，一直发送，直到接收到ack才停止 */
                                $needDeleteJob[$client->id][$message_id] = \Workerman\Timer::add(5, function () use (&$client, $data) {
                                    $client->send($data);
                                });
                            }
                        } else {
                            /** 没有服务质量的要求的数据，直接发送一次，不处理ack确认 */
                            $client->send($data);
                        }

                    }
                }
            }

            /** 返回ack給當前客戶端 */
            $connection->send(['cmd' => Mqtt::CMD_PUBACK, 'message_id' => $message_id]);
            break;

        /** 客户端确认已收到消息，删除任务 */
        case Mqtt::CMD_PUBACK:
            /** 刪除任務 */
            if (isset($needDeleteJob[$connection->id][$message_id])) {
                \Workerman\Timer::del($needDeleteJob[$connection->id][$message_id]);
                unset($needDeleteJob[$connection->id][$message_id]);
            }
            break;
        /** 断开链接 */
        case Mqtt::CMD_DISCONNECT:
            /** 首先清理订阅的频道，然后断开链接 */
            $clientId = $connection->id;
            foreach ($channels as $topic => $clients) {
                foreach ($clients as $id => $client) {
                    if ($id == $clientId) {
                        unset($channels[$topic][$id]);
                    }
                }
            }
            /** 删除链接 */
            if (isset($clients[$clientId])) {
                unset($clients[$clientId]);
            }
            /** 关闭链接 */
            $connection->close();
    }
};
/** 客户端接入 */
$worker->onConnect = function (TcpConnection $connection) {
    echo "客户端请求接入\r\n";
};

Worker::runAll();