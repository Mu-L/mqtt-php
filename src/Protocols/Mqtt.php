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

namespace Workerman\Mqtt\Protocols;

use Workerman\Connection\ConnectionInterface;

/**
 * Mqtt Protocol.
 *
 * @author    walkor<walkor@workerman.net>
 */
class Mqtt implements ProtocolInterface
{
    /**
     * CONNECT Packet.
     */
    public const CMD_CONNECT = 1;

    /**
     * CONNACK
     */
    public const CMD_CONNACK = 2;

    /**
     * PUBLISH
     */
    public const CMD_PUBLISH = 3;

    /**
     * PUBACK
     */
    public const CMD_PUBACK = 4;

    /**
     * PUBREC
     */
    public const CMD_PUBREC = 5;

    /**
     * PUBREL
     */
    public const CMD_PUBREL = 6;

    /**
     * PUBCOMP
     */
    public const CMD_PUBCOMP = 7;

    /**
     * SUBSCRIBE
     */
    public const CMD_SUBSCRIBE = 8;

    /**
     * SUBACK
     */
    public const CMD_SUBACK = 9;

    /**
     * UNSUBSCRIBE
     */
    public const CMD_UNSUBSCRIBE = 10;

    /**
     * UNSUBACK
     */
    public const CMD_UNSUBACK = 11;

    /**
     * PINGREQ
     */
    public const CMD_PINGREQ = 12;

    /**
     * PINGRESP
     */
    public const CMD_PINGRESP = 13;

    /**
     * DISCONNECT
     */
    public const CMD_DISCONNECT = 14;

    /** @inheritdoc  */
    public static function init(string &$address, array &$options, array &$context): string
    {
        $className = self::class;
        // mqtt version check
        if ($options['protocol_level'] === 5) {
            $className = Mqtt5::class;
        }
        if (!class_exists('\Workerman\Protocols\Mqtt')) {
            class_alias($className, '\Workerman\Protocols\Mqtt');
        }
        // context
        if ($options['bindto']) {
            $context['socket'] = ['bindto' => $options['bindto']];
        }
        if ($options['ssl'] and is_array($options['ssl'])) {
            $context['ssl'] = $options['ssl'];
        }
        // address override
        if (str_starts_with($address, 'mqtts')) {
            $options['ssl'] = empty($options['ssl']) ? true : $options['ssl'];
            $address = str_replace('mqtts', 'mqtt', $address);
        }
        return $className;
    }

    /** @inheritdoc */
    public static function pack(mixed $data, ConnectionInterface $connection): mixed
    {
        return $data;
    }

    /** @inheritdoc */
    public static function unpack(mixed $package, ConnectionInterface $connection): mixed
    {
        return $package;
    }

    /** @inheritdoc  */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        $length = strlen($buffer);
        $body_length = static::getBodyLength($buffer, $head_bytes);
        $total_length = $body_length + $head_bytes;
        if ($length < $total_length) {
            return 0;
        }

        return $total_length;
    }

    /** @inheritdoc  */
    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        $cmd = $data['cmd'];
        switch ($data['cmd']) {
            // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
            case static::CMD_CONNECT:
                $body = self::packString($data['protocol_name']) . chr($data['protocol_level']);
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

                $keepalive = !empty($data['keepalive']) && (int) $data['keepalive'] >= 0 ? (int) $data['keepalive'] : 0;
                $body .= pack('n', $keepalive);

                $body .= static::packString($data['client_id']);
                if (!empty($data['will'])) {
                    $body .= static::packString($data['will']['topic']);
                    $body .= static::packString($data['will']['content']);
                }
                if (!empty($data['username']) || $data['username'] === '0') {
                    $body .= static::packString($data['username']);
                }
                if (!empty($data['password']) || $data['password'] === '0') {
                    $body .= static::packString($data['password']);
                }
                $head = self::packHead($cmd, strlen($body));

                return $head . $body;
                //['cmd'=>2, 'session_present'=>0/1, 'code'=>x]
            case static::CMD_CONNACK:
                $body = !empty($data['session_present']) ? chr(1) : chr(0);
                $code = !empty($data['code']) ? $data['code'] : 0;
                $body .= chr($code);
                $head = static::packHead($cmd, strlen($body));

                return $head . $body;
                // ['cmd'=>3, 'message_id'=>x, 'topic'=>x, 'content'=>x, 'qos'=>0/1/2, 'dup'=>0/1, 'retain'=>0/1]
            case static::CMD_PUBLISH:
                $body = static::packString($data['topic']);
                $qos = $data['qos'] ?? 0;
                if ($qos) {
                    $body .= pack('n', $data['message_id']);
                }
                $body .= $data['content'];
                $dup = $data['dup'] ?? 0;
                $retain = $data['retain'] ?? 0;
                $head = static::packHead($cmd, strlen($body), $dup, $qos, $retain);

                return $head . $body;
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

                return $head . $body;

                // ['cmd'=>8, 'message_id'=>x, 'topics'=>[topic=>qos, ..]]]
            case static::CMD_SUBSCRIBE:
                $id = $data['message_id'];
                $body = pack('n', $id);
                foreach ($data['topics'] as $topic => $qos) {
                    $body .= self::packString($topic);
                    $body .= chr($qos);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);

                return $head . $body;
                // ['cmd'=>9, 'message_id'=>x, 'codes'=>[x,x,..]]
            case static::CMD_SUBACK:
                $codes = $data['codes'];
                $body = pack('n', $data['message_id']) . call_user_func_array('pack', array_merge(['C*'], $codes));
                $head = static::packHead($cmd, strlen($body));

                return $head . $body;

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

                return $head . $body;

                // ['cmd'=>x]
            case static::CMD_PINGREQ:
            case static::CMD_PINGRESP:
            case static::CMD_DISCONNECT:
                return static::packHead($cmd, 0);
            default:
                return '';
        }
    }

    /** @inheritdoc */
    public static function decode(string $buffer, ConnectionInterface $connection): array
    {
        $cmd = static::getCmd($buffer);
        $body = static::getBody($buffer);
        switch ($cmd) {
            case static::CMD_CONNECT:
                $protocol_name = static::readString($body);
                $protocol_level = ord($body[0]);
                $clean_session = ord($body[1]) >> 1 & 0x1;
                $will_flag = ord($body[1]) >> 2 & 0x1;
                $will_qos = ord($body[1]) >> 3 & 0x3;
                $will_retain = ord($body[1]) >> 5 & 0x1;
                $password_flag = ord($body[1]) >> 6 & 0x1;
                $username_flag = ord($body[1]) >> 7 & 0x1;
                $body = substr($body, 2);
                $tmp = unpack('n', $body, $body);
                $keepalive = $tmp[1];
                $body = substr($body, 2);
                $client_id = static::readString($body);
                if ($will_flag) {
                    $will_topic = static::readString($body);
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
                $package = [
                    'cmd'            => $cmd,
                    'protocol_name'  => $protocol_name,
                    'protocol_level' => $protocol_level,
                    'clean_session'  => $clean_session,
                    'will'           => [],
                    'username'       => $username,
                    'password'       => $password,
                    'keepalive'      => $keepalive,
                    'client_id'      => $client_id,
                ];
                if ($will_flag) {
                    $package['will'] = [
                        'qos'     => $will_qos,
                        'retain'  => $will_retain,
                        'topic'   => $will_topic,
                        'content' => $will_content,
                    ];
                } else {
                    unset($package['will']);
                }

                return $package;
            case static::CMD_CONNACK:
                $session_present = ord($body[0]) & 0x01;
                $code = ord($body[1]);

                return ['cmd' => $cmd, 'session_present' => $session_present, 'code' => $code];
            case static::CMD_PUBLISH:
                $dup = ord($buffer[0]) >> 3 & 0x1;
                $qos = ord($buffer[0]) >> 1 & 0x3;
                $retain = ord($buffer[0]) & 0x1;
                $topic = static::readString($body);
                if ($qos) {
                    $message_id = static::readShortInt($body);
                }
                $package = ['cmd' => $cmd, 'topic' => $topic, 'content' => $body, 'dup' => $dup, 'qos' => $qos, 'retain' => $retain];
                if ($qos) {
                    $package['message_id'] = $message_id;
                }

                return $package;
            case static::CMD_PUBACK:
            case static::CMD_PUBREC:
            case static::CMD_PUBREL:
            case static::CMD_UNSUBACK:
            case static::CMD_PUBCOMP:
                $message_id = static::readShortInt($body);

                return ['cmd' => $cmd, 'message_id' => $message_id];
            case static::CMD_SUBSCRIBE:
                $message_id = static::readShortInt($body);
                $topics = [];
                while ($body) {
                    $topic = static::readString($body);
                    $qos = ord($body[0]);
                    $topics[$topic] = $qos;
                    $body = substr($body, 1);
                }

                return ['cmd' => $cmd, 'message_id' => $message_id, 'topics' => $topics];
            case static::CMD_SUBACK:
                $message_id = static::readShortInt($body);
                $tmp = unpack('C*', $body);
                $codes = array_values($tmp);

                return ['cmd' => $cmd, 'message_id' => $message_id, 'codes' => $codes];
            case static::CMD_UNSUBSCRIBE:
                $message_id = static::readShortInt($body);
                $topics = [];
                while ($body) {
                    $topic = static::readString($body);
                    $topics[] = $topic;
                }

                return ['cmd' => $cmd, 'message_id' => $message_id, 'topics' => $topics];
            case static::CMD_PINGREQ:
            case static::CMD_PINGRESP:
            case static::CMD_DISCONNECT:
                return ['cmd' => $cmd];
        }

        return [];
    }

    /**
     * Pack string.
     *
     * @param string $str
     * @return string
     */
    public static function packString(string $str): string
    {
        $len = strlen($str);

        return pack('n', $len) . $str;
    }

    /**
     * Write body length.
     *
     * @param int $len
     * @return string
     */
    protected static function writeBodyLength(int $len): string
    {
        $string = '';
        do {
            $digit = $len % 128;
            $len = $len >> 7;
            // if there are more digits to encode, set the top bit of this digit
            if ($len > 0) {
                $digit = ($digit | 0x80);
            }
            $string .= chr($digit);
        } while ($len > 0);

        return $string;
    }

    /**
     * Get cmd.
     *
     * @param string $buffer
     * @return int
     */
    public static function getCmd(string $buffer): int
    {
        return ord($buffer[0]) >> 4;
    }

    /**
     * Get body length.
     *
     * @param string $buffer
     * @param int|null $head_bytes
     * @return int
     */
    public static function getBodyLength(string $buffer, ?int &$head_bytes): int
    {
        $head_bytes = $multiplier = 1;
        $value = 0;
        do {
            if (!isset($buffer[$head_bytes])) {
                $head_bytes = 0;

                return 0;
            }
            $digit = ord($buffer[$head_bytes]);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $head_bytes++;
        } while (($digit & 128) != 0);

        return (int) $value;
    }

    /**
     * Get body.
     *
     * @param $buffer
     * @return string
     */
    public static function getBody($buffer): string
    {
        $body_length = static::getBodyLength($buffer, $head_bytes);

        return substr($buffer, $head_bytes, $body_length);
    }

    /**
     * Read string from buffer.
     * @param $buffer
     * @return string
     */
    public static function readString(&$buffer): string
    {
        $tmp = unpack('n', $buffer);
        $length = $tmp[1];
        if ($length + 2 > strlen($buffer)) {
            echo 'buffer:' . bin2hex($buffer) . " lenth:$length not enough for unpackString\n";
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
    public static function readShortInt(&$buffer): mixed
    {
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
    public static function packHead($cmd, $body_length, $dup = 0, $qos = 0, $retain = 0): string
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

        return chr($cmd) . static::writeBodyLength($body_length);
    }
}
