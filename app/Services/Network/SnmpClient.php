<?php

namespace App\Services\Network;

use RuntimeException;

// Minimal SNMP v2c client (GET and GETBULK walks) over UDP, in plain PHP so OLT polling needs neither
// the php-snmp extension nor net-snmp tools on the server. Values come back as PHP types: INTEGER,
// counters, gauges and timeticks as int, OCTET STRING as raw bytes, IpAddress dotted, OID as string.
class SnmpClient
{
    private const GET = 0xA0;
    private const RESPONSE = 0xA2;
    private const GETBULK = 0xA5;
    private const END_OF_MIB = 0x82;

    private int $requestId;

    public function __construct(
        protected string $host,
        protected int $port = 161,
        protected string $community = 'public',
        protected float $timeout = 3,
        protected int $retries = 1,
    ) {
        $this->requestId = random_int(1, 0x7fffffff >> 1);
    }

    // [oid => value]; null for an OID the agent does not have
    public function get(array $oids): array
    {
        $out = [];
        foreach ($this->request(self::GET, $oids, 0, 0) as [$oid, $type, $value]) {
            $out[$oid] = $type >= 0x80 ? null : $value;
        }
        return $out;
    }

    // Every [oid => value] below $base, read with GETBULK.
    public function walk(string $base, int $repetitions = 25, int $maxRows = 20000): array
    {
        $base = trim($base, '.');
        $out = [];
        $cursor = $base;
        while (count($out) < $maxRows) {
            $rows = $this->request(self::GETBULK, [$cursor], 0, $repetitions);
            if (! $rows) {
                break;
            }
            foreach ($rows as [$oid, $type, $value]) {
                // left the table, end of the agent's MIB, or an agent going backwards
                if ($type === self::END_OF_MIB || ! str_starts_with($oid, $base . '.') || self::compare($oid, $cursor) <= 0) {
                    return $out;
                }
                $out[$oid] = $value;
                $cursor = $oid;
            }
        }
        return $out;
    }

    // list of [oid, type, value] from the response
    protected function request(int $pdu, array $oids, int $a, int $b): array
    {
        $id = $this->requestId = ($this->requestId % 0x7fffffff) + 1;
        $varbinds = implode('', array_map(fn ($o) => self::tlv(0x30, self::encodeOid($o) . "\x05\x00"), $oids));
        $packet = self::tlv(0x30, self::encodeInt(1) . self::tlv(0x04, $this->community)
            . self::tlv($pdu, self::encodeInt($id) . self::encodeInt($a) . self::encodeInt($b) . self::tlv(0x30, $varbinds)));

        $response = self::decodeMessage($this->exchange($packet, $id));
        if ($response['error']) {
            throw new RuntimeException("SNMP error {$response['error']} from {$this->host}");
        }
        return $response['varbinds'];
    }

    // Sends one packet and returns the response carrying request id $id.
    protected function exchange(string $packet, int $id): string
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;
        $socket = @stream_socket_client("udp://{$host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("SNMP: cannot open UDP to {$this->host}:{$this->port} ({$error})");
        }
        try {
            stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1e6));
            for ($try = 0; $try <= $this->retries; $try++) {
                fwrite($socket, $packet);
                while (($data = fread($socket, 65535)) !== false && $data !== '') {
                    try {
                        if (self::decodeMessage($data)['id'] === $id) {
                            return $data;
                        }
                    } catch (\Throwable) {
                        // not an SNMP answer to us: keep reading
                    }
                }
            }
        } finally {
            fclose($socket);
        }
        throw new RuntimeException("SNMP: no answer from {$this->host}:{$this->port} (check the IP, UDP port, community and that the OLT allows this server)");
    }

    // ---- BER encoding ----

    public static function tlv(int $type, string $value): string
    {
        $len = strlen($value);
        if ($len < 0x80) {
            return chr($type) . chr($len) . $value;
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr($type) . chr(0x80 | strlen($bytes)) . $bytes . $value;
    }

    public static function encodeInt(int $v, int $type = 0x02): string
    {
        $bytes = '';
        do {
            $bytes = chr($v & 0xff) . $bytes;
            $v >>= 8;
        } while (! (($v === 0 && ! (ord($bytes[0]) & 0x80)) || ($v === -1 && (ord($bytes[0]) & 0x80))));
        return self::tlv($type, $bytes);
    }

    public static function encodeOid(string $oid): string
    {
        $arcs = array_map('intval', explode('.', trim($oid, '.')));
        $first = array_shift($arcs) * 40 + (int) array_shift($arcs);
        $out = '';
        foreach (array_merge([$first], $arcs) as $arc) {
            $chunk = chr($arc & 0x7f);
            while ($arc >>= 7) {
                $chunk = chr(0x80 | ($arc & 0x7f)) . $chunk;
            }
            $out .= $chunk;
        }
        return self::tlv(0x06, $out);
    }

    // ---- BER decoding ----

    // ['id' => request id, 'type' => PDU type, 'error' => error status of a response, 'a'/'b' => the two
    // header integers, 'varbinds' => [[oid, type, value], ...]]
    public static function decodeMessage(string $data): array
    {
        $pos = 0;
        [$type, $message] = self::readTlv($data, $pos);
        if ($type !== 0x30) {
            throw new RuntimeException('SNMP: not an SNMP message');
        }
        $pos = 0;
        self::readTlv($message, $pos); // version
        self::readTlv($message, $pos); // community
        [$pduType, $pdu] = self::readTlv($message, $pos);
        $pos = 0;
        $id = self::decodeValue(...self::readTlv($pdu, $pos));
        $a = self::decodeValue(...self::readTlv($pdu, $pos)); // error status / non-repeaters
        $b = self::decodeValue(...self::readTlv($pdu, $pos)); // error index / max-repetitions
        [, $list] = self::readTlv($pdu, $pos);

        $varbinds = [];
        $pos = 0;
        while ($pos < strlen($list)) {
            [, $vb] = self::readTlv($list, $pos);
            $p = 0;
            [, $oid] = self::readTlv($vb, $p);
            [$vtype, $raw] = self::readTlv($vb, $p);
            $varbinds[] = [self::decodeOid($oid), $vtype, self::decodeValue($vtype, $raw)];
        }
        return ['id' => $id, 'type' => $pduType, 'error' => $pduType === self::RESPONSE ? $a : 0, 'a' => $a, 'b' => $b, 'varbinds' => $varbinds];
    }

    // [type, value bytes]; advances $pos
    public static function readTlv(string $s, int &$pos): array
    {
        if ($pos + 2 > strlen($s)) {
            throw new RuntimeException('SNMP: truncated packet');
        }
        $type = ord($s[$pos++]);
        $len = ord($s[$pos++]);
        if ($len & 0x80) {
            $n = $len & 0x7f;
            $len = 0;
            for ($i = 0; $i < $n; $i++) {
                $len = ($len << 8) | ord($s[$pos++]);
            }
        }
        if ($pos + $len > strlen($s)) {
            throw new RuntimeException('SNMP: truncated packet');
        }
        $value = substr($s, $pos, $len);
        $pos += $len;
        return [$type, $value];
    }

    public static function decodeOid(string $raw): string
    {
        $arcs = [];
        $v = 0;
        foreach (str_split($raw) as $byte) {
            $b = ord($byte);
            $v = ($v << 7) | ($b & 0x7f);
            if (! ($b & 0x80)) {
                $arcs[] = $v;
                $v = 0;
            }
        }
        if (! $arcs) {
            return '';
        }
        $first = array_shift($arcs);
        $head = $first < 40 ? [0, $first] : ($first < 80 ? [1, $first - 40] : [2, $first - 80]);
        return implode('.', array_merge($head, $arcs));
    }

    public static function decodeValue(int $type, string $raw): mixed
    {
        switch ($type) {
            case 0x02: // INTEGER, signed
                if ($raw === '') {
                    return 0;
                }
                $v = ord($raw[0]) & 0x80 ? -1 : 0;
                foreach (str_split($raw) as $byte) {
                    $v = ($v << 8) | ord($byte);
                }
                return $v;
            case 0x41: case 0x42: case 0x43: case 0x46: // Counter32, Gauge32, TimeTicks, Counter64
                $v = 0;
                foreach (str_split($raw) as $byte) {
                    $v = ($v << 8) | ord($byte);
                }
                return $v;
            case 0x06:
                return self::decodeOid($raw);
            case 0x40:
                return strlen($raw) === 4 ? implode('.', array_map('ord', str_split($raw))) : bin2hex($raw);
            case 0x05: case 0x80: case 0x81: case 0x82: // NULL, noSuchObject, noSuchInstance, endOfMibView
                return null;
            default: // OCTET STRING, Opaque, …
                return $raw;
        }
    }

    // -1 / 0 / 1 comparing two OIDs arc by arc
    public static function compare(string $a, string $b): int
    {
        $x = explode('.', $a);
        $y = explode('.', $b);
        for ($i = 0, $n = min(count($x), count($y)); $i < $n; $i++) {
            if ((int) $x[$i] !== (int) $y[$i]) {
                return (int) $x[$i] <=> (int) $y[$i];
            }
        }
        return count($x) <=> count($y);
    }
}
