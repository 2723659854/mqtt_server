<?php

namespace Workerman\Mqtt;

use Workerman\Mqtt\Client;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Ws;
use Workerman\Mqtt\Protocols\Mqtt;
use Workerman\Mqtt\Protocols\Mqtt5;
use Workerman\Mqtt\Consts\MQTTConst;
use Workerman\Timer;

/**
 * @purpose mqtt客户端使用ws协议
 * @link https://www.workerman.net/a/1466
 */
class WsClient extends Client {

    protected $_class_name = Mqtt::class;

    public function __construct($address, array $options = []) {
        $this->setOptions($options);

        $this->_class_name = '\Workerman\Protocols\Mqtt';
        if ((int) $this->_options['protocol_level'] === 5) {
            if (!class_exists($this->_class_name)) {
                $this->_class_name = Mqtt5::class;
            }
        } else {
            if (!class_exists($this->_class_name)) {
                $this->_class_name = Mqtt::class;
            }
        }

        $context = array();
        if ($this->_options['bindto']) {
            $context['socket'] = array('bindto' => $this->_options['bindto']);
        }
        if ($this->_options['ssl'] && is_array($this->_options['ssl'])) {
            $context['ssl'] = $this->_options['ssl'];
        }

        if (strpos($address, 'wss') === 0) {
            if (empty($this->_options['ssl'])) {
                $this->_options['ssl'] = true;
            }
            $address = str_replace('wss', 'ws', $address);
        }

        $this->_remoteAddress = $address;
        $this->_connection = new AsyncTcpConnection($address, $context);
        $this->_connection->websocketType = Ws::BINARY_TYPE_ARRAYBUFFER;
        $this->_connection->WSClientProtocol = 'mqtt';
        $this->onReconnect = array($this, 'onMqttReconnect');
        $this->onMessage = function () {

        };
        if ($this->_options['ssl']) {
            $this->_connection->transport = 'ssl';
        }
    }

    /**
     * connect
     */
    public function connect() {
        $this->_doNotReconnect = false;
        $this->_connection->onConnect = array($this, 'onConnectionConnect');
        $this->_connection->onMessage = array($this, 'onConnectionMessage');
        $this->_connection->onError = array($this, 'onConnectionError');
        $this->_connection->onClose = array($this, 'onConnectionClose');
        $this->_connection->onBufferFull = array($this, 'onConnectionBufferFull');
        $this->_state = static::STATE_CONNECTING;
        $this->_connection->connect();
        $this->setConnectionTimeout($this->_options['connect_timeout']);
        if ($this->_options['debug']) {
            echo "-> Try to connect to {$this->_remoteAddress}", PHP_EOL;
        }
    }

    public function onConnectionConnect() {
        if ($this->_doNotReconnect) {
            $this->close();
            return;
        }
        //['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
        $package = array(
            'cmd' => MQTTConst::CMD_CONNECT,
            'clean_session' => $this->_options['clean_session'],
            'username' => $this->_options['username'],
            'password' => $this->_options['password'],
            'keepalive' => $this->_options['keepalive'],
            'protocol_name' => $this->_options['protocol_name'],
            'protocol_level' => $this->_options['protocol_level'],
            'client_id' => $this->_options['client_id'],
            'properties' => $this->_options['properties'] // MQTT5 中所需要的属性
        );
        if (isset($this->_options['will'])) {
            $package['will'] = $this->_options['will'];
        }
        $this->_state = static::STATE_WAITCONACK;
        $buffer = $this->encode($package);
        $cmd = substr($buffer, 0, 1);
        $body = substr($buffer, 1);
        $this->_connection->send($cmd);
        $this->_connection->send($body);
        if ($this->_options['debug']) {
            echo "-- Tcp connection established", PHP_EOL;
            echo "-> Send CONNECT package client_id:{$this->_options['client_id']} username:{$this->_options['username']} password:{$this->_options['password']} clean_session:{$this->_options['clean_session']} protocol_name:{$this->_options['protocol_name']} protocol_level:{$this->_options['protocol_level']}", PHP_EOL;
        }
    }

    public function onConnectionMessage($connection, $buffer) {
        $data = $this->decode($buffer);
        parent::onConnectionMessage($connection, $data);
    }

    protected function sendPackage($package) {
        if ($this->checkDisconnecting()) {
            return;
        }
        $buffer = $this->encode($package);
        $cmd = substr($buffer, 0, 1);
        $body = substr($buffer, 1);
        $this->_connection->send($cmd);
        $this->_connection->send($body);
    }

    protected function setPingTimer($ping_interval)
    {
        $this->cancelPingTimer();
        $connection = $this->_connection;
        $this->_pingTimer = Timer::add($ping_interval, function()use($connection){
            if (!$this->_recvPingResponse) {
                if ($this->_options['debug']) {
                    echo "<- Recv PINGRESP timeout", PHP_EOL;
                    echo "-> Close connection", PHP_EOL;
                }
                $this->_connection->destroy();
                return;
            }
            if ($this->_options['debug']) {
                echo "-> Send PINGREQ package", PHP_EOL;
            }
            $this->_recvPingResponse = false;
            $connection->send($this->encode(array('cmd' => MQTTConst::CMD_PINGREQ)));
        });
    }

    protected function encode($data) {
        return call_user_func_array([$this->_class_name, 'encode'], [$data]);
    }

    protected function decode($buffer) {
        return call_user_func_array([$this->_class_name, 'decode'], [$buffer]);
    }

}

$client = new WsClient('ws://127.0.0.1:8083/mqtt', ['username' => 'username', 'protocol_level' => 5, 'password' => 'password']);
$client->onConnect = function (WsClient $client) {
    $client->subscribe('/subscribe/');
    $client->publish('/publish/', '{"msg":"Hello Mqtt."}');
};
$client->connect();