<?php

namespace App\Services\Network;

use App\Models\Router;
use RuntimeException;

// Dynamic Authorization (RFC 5176) to a NAS over UDP: Disconnect-Request ends a live session,
// CoA-Request changes one in place (e.g. a new speed). Packets are signed with the NAS's RADIUS
// secret, and the answer's Response Authenticator is checked before it is believed.
class RadiusClient
{
    public const DISCONNECT_REQUEST = 40;
    public const DISCONNECT_ACK = 41;
    public const DISCONNECT_NAK = 42;
    public const COA_REQUEST = 43;
    public const COA_ACK = 44;
    public const COA_NAK = 45;

    // attribute name => [type, encoding]
    public const ATTRIBUTES = [
        'User-Name' => [1, 'string'],
        'NAS-IP-Address' => [4, 'ipv4'],
        'Framed-IP-Address' => [8, 'ipv4'],
        'Filter-Id' => [11, 'string'],
        'Acct-Session-Id' => [44, 'string'],
        'Error-Cause' => [101, 'integer'],
    ];

    // vendor attribute name => [vendor id, vendor type, encoding]
    public const VENDOR_ATTRIBUTES = [
        'Mikrotik-Rate-Limit' => [14988, 8, 'string'],
        'Huawei-Input-Average-Rate' => [2011, 2, 'integer'],
        'Huawei-Output-Average-Rate' => [2011, 5, 'integer'],
        'Cisco-AVPair' => [9, 1, 'string'],
        'ERX-Ingress-Policy-Name' => [4874, 10, 'string'],
        'ERX-Egress-Policy-Name' => [4874, 11, 'string'],
        'WISPr-Bandwidth-Max-Up' => [14122, 7, 'integer'],
        'WISPr-Bandwidth-Max-Down' => [14122, 8, 'integer'],
    ];

    // Replaces the UDP round trip in tests: fn (string $host, int $port, string $packet): ?string
    public static $transport = null;

    public function __construct(private Router $nas, private float $timeout = 3.0, private int $retries = 2)
    {
    }

    // true = the NAS ended the session; throws when it refused or didn't answer
    public function disconnect(array $attributes): bool
    {
        return $this->request(self::DISCONNECT_REQUEST, $attributes);
    }

    // true = the NAS applied the change; false when it refused (NAK), throws when no answer
    public function coa(array $attributes): bool
    {
        return $this->request(self::COA_REQUEST, $attributes);
    }

    private function request(int $code, array $attributes): bool
    {
        $secret = (string) $this->nas->radius_secret;
        if ($secret === '') {
            throw new RuntimeException("{$this->nas->name}: no RADIUS secret set.");
        }
        $id = random_int(0, 255);
        $body = self::encodeAttributes($attributes);
        $length = 20 + strlen($body);
        $header = pack('CCn', $code, $id, $length);
        // RFC 5176 2.3: Request Authenticator = MD5(Code + Identifier + Length + 16 zero octets + Attributes + Secret)
        $authenticator = md5($header . str_repeat("\0", 16) . $body . $secret, true);
        $packet = $header . $authenticator . $body;

        $port = (int) ($this->nas->coa_port ?: 3799);
        $response = null;
        for ($try = 0; $try <= $this->retries && $response === null; $try++) {
            $response = self::$transport ? (self::$transport)($this->nas->host, $port, $packet) : $this->udp($this->nas->host, $port, $packet);
        }
        if ($response === null) {
            throw new RuntimeException("{$this->nas->name} ({$this->nas->host}:{$port}) did not answer the " . ($code === self::COA_REQUEST ? 'CoA' : 'Disconnect') . ' request.');
        }

        $reply = self::decodePacket($response);
        if ($reply['id'] !== $id) {
            throw new RuntimeException("{$this->nas->name}: answer for another request.");
        }
        $expected = md5(substr($response, 0, 4) . $authenticator . substr($response, 20) . $secret, true);
        if (! hash_equals($expected, $reply['authenticator'])) {
            throw new RuntimeException("{$this->nas->name}: answer failed the RADIUS secret check (is the secret the same on the NAS?).");
        }
        if (in_array($reply['code'], [self::DISCONNECT_ACK, self::COA_ACK], true)) {
            return true;
        }
        if ($reply['code'] === self::DISCONNECT_NAK) {
            $cause = $reply['attributes']['Error-Cause'] ?? null;
            // 503 Session Context Not Found: already gone, which is what we wanted
            if ($cause === 503) {
                return true;
            }
            throw new RuntimeException("{$this->nas->name} refused the disconnect" . ($cause ? " (Error-Cause {$cause})" : '') . '.');
        }
        return false; // CoA-NAK
    }

    private function udp(string $host, int $port, string $packet): ?string
    {
        $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("Can't reach {$host}:{$port}: {$error}");
        }
        stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1e6));
        fwrite($socket, $packet);
        $response = fread($socket, 4096);
        $timedOut = stream_get_meta_data($socket)['timed_out'];
        fclose($socket);
        return $timedOut || $response === false || strlen($response) < 20 ? null : $response;
    }

    // ['User-Name' => 'x', 'Mikrotik-Rate-Limit' => '5M/10M', 'Cisco-AVPair' => ['a', 'b']]
    public static function encodeAttributes(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $values) {
            foreach ((array) $values as $value) {
                if (isset(self::ATTRIBUTES[$name])) {
                    [$type, $encoding] = self::ATTRIBUTES[$name];
                    $data = self::encodeValue($value, $encoding);
                    $out .= pack('CC', $type, strlen($data) + 2) . $data;
                } elseif (isset(self::VENDOR_ATTRIBUTES[$name])) {
                    [$vendor, $type, $encoding] = self::VENDOR_ATTRIBUTES[$name];
                    $data = self::encodeValue($value, $encoding);
                    $vsa = pack('N', $vendor) . pack('CC', $type, strlen($data) + 2) . $data;
                    $out .= pack('CC', 26, strlen($vsa) + 2) . $vsa;
                } else {
                    throw new RuntimeException("Unknown RADIUS attribute {$name}");
                }
            }
        }
        return $out;
    }

    private static function encodeValue($value, string $encoding): string
    {
        return match ($encoding) {
            'ipv4' => inet_pton((string) $value) ?: throw new RuntimeException("Not an IPv4 address: {$value}"),
            'integer' => pack('N', (int) $value),
            default => substr((string) $value, 0, 253),
        };
    }

    // ['code', 'id', 'authenticator', 'attributes' => [name => value]] (known standard attributes only)
    public static function decodePacket(string $packet): array
    {
        $head = unpack('Ccode/Cid/nlength', substr($packet, 0, 4));
        $attributes = [];
        $names = array_flip(array_map(fn ($a) => $a[0], self::ATTRIBUTES));
        $offset = 20;
        $end = min(strlen($packet), $head['length']);
        while ($offset + 2 <= $end) {
            ['type' => $type, 'len' => $len] = unpack('Ctype/Clen', substr($packet, $offset, 2));
            if ($len < 2) {
                break;
            }
            $raw = substr($packet, $offset + 2, $len - 2);
            if (isset($names[$type])) {
                $name = $names[$type];
                $attributes[$name] = match (self::ATTRIBUTES[$name][1]) {
                    'integer' => unpack('N', str_pad($raw, 4, "\0", STR_PAD_LEFT))[1],
                    'ipv4' => inet_ntop($raw),
                    default => $raw,
                };
            }
            $offset += $len;
        }
        return ['code' => $head['code'], 'id' => $head['id'], 'authenticator' => substr($packet, 4, 16), 'attributes' => $attributes];
    }
}
