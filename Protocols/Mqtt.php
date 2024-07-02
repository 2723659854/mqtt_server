<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Protocols;

/**
 * Mqtt Protocol.
 * mqtt 物联网协议
 * @author    walkor<walkor@workerman.net>
 * @note
 * QoS（服務質量）級別：MQTT支持三個服務質量（QoS）級別，提供不同程度的消息傳遞保證：
 *
 * QoS 0: 最多一次（at most once），消息最多傳送一次，不會重傳。
 * QoS 1: 至少一次（at least once），消息至少傳送一次，可能會重傳。
 * QoS 2: 只有一次（exactly once），消息僅傳送一次，確保不重複。
 */
class Mqtt
{

    /**
     * CONNECT Packet.
     * 用於客戶端向服務器發送連接請求。此命令包含客戶端標識、認證信息和其他可選參數。
     */
    const CMD_CONNECT = 1;

    /**
     * CONNACK
     * 服務器對CONNECT請求的確認包。它包含連接狀態（成功或失敗）和其他相關信息。
     */
    const CMD_CONNACK = 2;

    /**
     * PUBLISH
     * 用於發布消息到服務器。消息包含主題（topic）和消息內容，服務器會根據主題將消息分發給訂閱者。
     */
    const CMD_PUBLISH = 3;

    /**
     * PUBACK
     * 用於確認已經成功接收PUBLISH包。在QoS 1級別下使用。
     */
    const CMD_PUBACK = 4;

    /**
     * PUBREC
     * 用於確認已經成功接收PUBLISH包的第一步。在QoS 2級別下使用。
     */
    const CMD_PUBREC = 5;

    /**
     * PUBREL
     * 用於確認PUBREC包。在QoS 2級別下使用。
     */
    const CMD_PUBREL = 6;

    /**
     * PUBCOMP
     * 用於確認PUBREL包，完成QoS 2消息流。
     */
    const CMD_PUBCOMP = 7;

    /**
     * SUBSCRIBE
     * 用於客戶端訂閱一個或多個主題，以接收來自這些主題的消息。
     */
    const CMD_SUBSCRIBE = 8;

    /**
     * SUBACK
     * 服務器對SUBSCRIBE請求的確認包，告知客戶端訂閱請求是否成功。
     */
    const CMD_SUBACK = 9;

    /**
     * UNSUBSCRIBE
     * 用於客戶端取消訂閱一個或多個主題。
     */
    const CMD_UNSUBSCRIBE = 10;

    /**
     * UNSUBACK
     * 服務器對UNSUBSCRIBE請求的確認包，告知客戶端取消訂閱請求是否成功。
     */
    const CMD_UNSUBACK = 11;

    /**
     * PINGREQ
     * 用於客戶端向服務器發送心跳包，確保連接是活動的。
     */
    const CMD_PINGREQ = 12;

    /**
     * PINGRESP
     * 服務器對PINGREQ心跳包的響應，表示連接是活動的。
     */
    const CMD_PINGRESP = 13;

    /**
     * DISCONNECT
     * 用於客戶端通知服務器斷開連接。
     */
    const CMD_DISCONNECT = 14;

    /**
     * Check the integrity of the package.
     *
     * @param string        $buffer
     * @return int
     */
    public static function input($buffer)
    {
        $length       = strlen($buffer);
        $body_length  = static::getBodyLength($buffer, $head_bytes);
        $total_length = $body_length + $head_bytes;
        if ($length < $total_length) {
            return 0;
        }

        return $total_length;
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($data)
    {
        $cmd = $data['cmd'];
        switch ($data['cmd']) {
            // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
            case static::CMD_CONNECT;
                $body = self::packString($data['protocol_name']).chr($data['protocol_level']);
                $connect_flags = 0;
                if (!empty($data['clean_session'])) {
                    $connect_flags |= 1 << 1;
                }
                if (!empty($data['will'])) {
                    $connect_flags |= 1 << 2;
                    $connect_flags |= $data['will']['qos'] << 3;
                    if ($data['will']['retain']) {
                        $connect_flags |= 1 << 5;
                    }
                }
                if (!empty($data['password'])) {
                    $connect_flags |= 1 << 6;
                }
                if (!empty($data['username'])) {
                    $connect_flags |= 1 << 7;
                }
                $body .= chr($connect_flags);

                $keepalive = !empty($data['keepalive']) && (int)$data['keepalive'] >= 0 ? (int)$data['keepalive'] : 0;
                $body .= pack('n', $keepalive);

                $body .= static::packString($data['client_id']);
                if (!empty($data['will'])) {
                    $body .= static::packString($data['will']['topic']);
                    $body .= static::packString($data['will']['content']);
                }
                if(!empty($data['username']) || $data['username'] === '0') {
                    $body .= static::packString($data['username']);
                }
                if(!empty($data['password']) || $data['password'] === '0') {
                    $body .= static::packString($data['password']);
                }
                $head = self::packHead($cmd, strlen($body));
                return $head.$body;
            //['cmd'=>2, 'session_present'=>0/1, 'code'=>x]
            case static::CMD_CONNACK:
                $body = !empty($data['session_present']) ? chr(1) : chr(0);
                $code = !empty($data['code']) ? $data['code'] : 0;
                $body .= chr($code);
                $head = static::packHead($cmd, strlen($body));
                return $head.$body;
            // ['cmd'=>3, 'message_id'=>x, 'topic'=>x, 'content'=>x, 'qos'=>0/1/2, 'dup'=>0/1, 'retain'=>0/1]
            case static::CMD_PUBLISH:
                $body   = static::packString($data['topic']);
                $qos    = isset($data['qos']) ? $data['qos'] : 0;
                if ($qos) {
                    $body .= pack('n', $data['message_id']);
                }
                $body   .= $data['content'];
                $dup    = isset($data['dup']) ? $data['dup'] : 0;
                $retain = isset($data['retain']) ? $data['retain'] : 0;
                $head   = static::packHead($cmd, strlen($body), $dup, $qos, $retain);
                return $head.$body;
            // ['cmd'=>x, 'message_id'=>x]
            case static::CMD_PUBACK:
            case static::CMD_PUBREC:
            case static::CMD_PUBREL:
            case static::CMD_PUBCOMP:
                $body = pack('n', $data['message_id']);
                if ($cmd === static::CMD_PUBREL) {
                    $head = static::packHead($cmd, strlen($body), 0, 1);
                } else {
                    $head = static::packHead($cmd, strlen($body));
                }
                return $head.$body;

            // ['cmd'=>8, 'message_id'=>x, 'topics'=>[topic=>qos, ..]]]
            case static::CMD_SUBSCRIBE:
                $id = $data['message_id'];
                $body = pack('n', $id);
                foreach($data['topics'] as $topic => $qos){
                    $body .= self::packString($topic);
                    $body .= chr($qos);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);
                return $head.$body;
            // ['cmd'=>9, 'message_id'=>x, 'codes'=>[x,x,..]]
            case static::CMD_SUBACK:
                $codes = $data['codes'];
                $body = pack('n', $data['message_id']).call_user_func_array('pack', array_merge(array('C*'), $codes));
                $head = static::packHead($cmd, strlen($body));
                return $head.$body;

            // ['cmd' => 10, 'message_id' => $message_id, 'topics' => $topics];
            case static::CMD_UNSUBSCRIBE:
                $body = pack('n', $data['message_id']);
                foreach ($data['topics'] as $topic) {
                    $body .= static::packString($topic);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);
                return $head . $body;
            // ['cmd'=>11, 'message_id'=>x]
            case static::CMD_UNSUBACK:
                $body = pack('n', $data['message_id']);
                $head = static::packHead($cmd, strlen($body));
                return $head.$body;

            // ['cmd'=>x]
            case static::CMD_PINGREQ;
            case static::CMD_PINGRESP:
            case static::CMD_DISCONNECT:
                return static::packHead($cmd, 0);
        }

    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        $cmd = static::getCmd($buffer);
        $body = static::getBody($buffer);
        switch ($cmd) {
            case static::CMD_CONNECT:
                $protocol_name  = static::readString($body);
                $protocol_level = ord($body[0]);
                $clean_session  = ord($body[1]) >> 1 & 0x1;
                $will_flag      = ord($body[1]) >> 2 & 0x1;
                $will_qos       = ord($body[1]) >> 3 & 0x3;
                $will_retain    = ord($body[1]) >> 5 & 0x1;
                $password_flag  = ord($body[1]) >> 6 & 0x1;
                $username_flag  = ord($body[1]) >> 7 & 0x1;
                $body           = substr($body, 2);
                $tmp            = unpack('n', $body);
                $keepalive      = $tmp[1];
                $body           = substr($body, 2);
                $client_id      = static::readString($body);
                if ($will_flag) {
                    $will_topic   = static::readString($body);
                    $will_content = static::readString($body);
                }
                $username = $password = '';
                if ($username_flag) {
                    $username = static::readString($body);
                }
                if ($password_flag) {
                    $password = static::readString($body);
                }
                // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
                $package = array(
                    'cmd' => $cmd,
                    'protocol_name'  => $protocol_name,
                    'protocol_level' => $protocol_level,
                    'clean_session'  => $clean_session,
                    'will'           => array(),
                    'username'       => $username,
                    'password'       => $password,
                    'keepalive'      => $keepalive,
                    'client_id'      => $client_id,
                );
                if ($will_flag) {
                    $package['will'] = array(
                        'qos'     => $will_qos,
                        'retain'  => $will_retain,
                        'topic'   => $will_topic,
                        'content' => $will_content
                    );
                } else {
                    unset($package['will']);
                }
                return $package;
            case static::CMD_CONNACK:
                $session_present = ord($body[0]) & 0x01;
                $code = ord($body[1]);
                return array('cmd' => $cmd, 'session_present' => $session_present, 'code' => $code);
            case static::CMD_PUBLISH:
                $dup        = ord($buffer[0]) >> 3 & 0x1;
                $qos        = ord($buffer[0]) >> 1 & 0x3;
                $retain     = ord($buffer[0]) & 0x1;
                $topic      = static::readString($body);
                if ($qos) {
                    $message_id = static::readShortInt($body);
                }
                $package = array('cmd' => $cmd, 'topic' => $topic, 'content' => $body, 'dup' => $dup, 'qos' => $qos, 'retain' => $retain);
                if ($qos) {
                    $package['message_id'] = $message_id;
                }
                return $package;
            case static::CMD_PUBACK:
            case static::CMD_PUBREC:
            case static::CMD_PUBREL:
            case static::CMD_PUBCOMP:
                $message_id = static::readShortInt($body);
                return array('cmd' => $cmd, 'message_id' => $message_id);
            case static::CMD_SUBSCRIBE:
                $message_id = static::readShortInt($body);
                $topics = array();
                while ($body) {
                    $topic          = static::readString($body);
                    $qos            = ord($body[0]);
                    $topics[$topic] = $qos;
                    $body           = substr($body, 1);
                }
                return array('cmd' => $cmd, 'message_id' => $message_id, 'topics' => $topics);
            case static::CMD_SUBACK:
                $message_id = static::readShortInt($body);
                $tmp = unpack('C*', $body);
                $codes = array_values($tmp);
                return array('cmd' => $cmd, 'message_id'=> $message_id, 'codes' => $codes);
            case static::CMD_UNSUBSCRIBE:
                $message_id = static::readShortInt($body);
                $topics = array();
                while ($body) {
                    $topic    = static::readString($body);
                    $topics[] = $topic;
                }
                return array('cmd' => $cmd, 'message_id' => $message_id, 'topics' => $topics);
            case static::CMD_UNSUBACK:
                $message_id = static::readShortInt($body);
                return array('cmd' => $cmd, 'message_id' => $message_id);
            case static::CMD_PINGREQ:
            case static::CMD_PINGRESP:
            case static::CMD_DISCONNECT:
                return array('cmd' => $cmd);
        }
        return $buffer;
    }


    /**
     * Pack string.
     *
     * @param $str
     * @return string
     */
    public static function packString($str){
        $len = strlen($str);
        return pack('n', $len).$str;
    }

    /**
     * Write body length.
     *
     * @param $len
     * @return string
     */
    protected static function writeBodyLength($len)
    {
        $string = "";
        do{
            $digit = $len % 128;
            $len   = $len >> 7;
            // if there are more digits to encode, set the top bit of this digit
            if ( $len > 0 )
                $digit = ($digit | 0x80);
            $string .= chr($digit);
        }while ( $len > 0 );
        return $string;
    }

    /**
     * Get cmd.
     *
     * @param $buffer
     * @return int
     */
    public static function getCmd($buffer)
    {
        return ord($buffer[0]) >> 4;
    }

    /**
     * Get body length.
     *
     * @param $buffer
     * @param $head_bytes
     * @return int
     */
    public static function getBodyLength($buffer, &$head_bytes){
        $head_bytes = $multiplier = 1;
        $value = 0;
        do{
            if (!isset($buffer[$head_bytes])) {
                $head_bytes = 0;
                return 0;
            }
            $digit = ord($buffer[$head_bytes]);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $head_bytes++;
        }while (($digit & 128) != 0);
        return $value;
    }

    /**
     * Get body.
     *
     * @param $buffer
     * @return string
     */
    public static function getBody($buffer)
    {
        $body_length = static::getBodyLength($buffer, $head_bytes);
        $buffer      = substr($buffer, $head_bytes, $body_length);
        return $buffer;
    }

    /**
     * Read string from buffer.
     * @param $buffer
     * @return string
     */
    public static function readString(&$buffer) {
        $tmp = unpack('n', $buffer);
        $length = $tmp[1];
        if ($length + 2 > strlen($buffer)) {
            echo "buffer:".bin2hex($buffer)." lenth:$length not enough for unpackString\n";
        }

        $string = substr($buffer, 2, $length);
        $buffer = substr($buffer, $length + 2);
        return $string;
    }

    /**
     * Read unsigned short int from buffer.
     * @param $buffer
     * @return mixed
     */
    public static function readShortInt(&$buffer) {
        $tmp = unpack('n', $buffer);
        $buffer = substr($buffer, 2);
        return $tmp[1];
    }

    /**
     * packHead.
     * @param $cmd
     * @param $body_length
     * @param int $dup
     * @param int $qos
     * @param int $retain
     * @return string
     */
    public static function packHead($cmd, $body_length, $dup = 0, $qos = 0, $retain = 0)
    {
        $cmd = $cmd << 4;
        if ($dup) {
            $cmd |= 1 << 3;
        }
        if ($qos) {
            $cmd |= $qos << 1;
        }
        if ($retain) {
            $cmd |= 1;
        }
        return chr($cmd).static::writeBodyLength($body_length);
    }

}
