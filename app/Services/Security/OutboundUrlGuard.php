<?php

namespace App\Services\Security;

/**
 * Validates a user-supplied URL before the server fetches it (SSRF defence).
 *
 * Any endpoint that fetches a URL on behalf of a caller is an SSRF primitive: the request comes
 * from inside the network, so it reaches cloud metadata services, internal admin panels and
 * databases that are firewalled off from the internet. The classic target is
 * http://169.254.169.254/ — the cloud instance-metadata endpoint that hands out IAM credentials.
 *
 * Pure and DB-free so every rule is unit-testable.
 */
class OutboundUrlGuard
{
    /** Only these schemes may ever be fetched. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Blocked destination ranges (CIDR). Covers loopback, RFC1918 private space, link-local
     * (which includes cloud metadata at 169.254.169.254), CGNAT, and reserved/broadcast space.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // private
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local — CLOUD METADATA
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // private
        '100.64.0.0/10',    // CGNAT
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved + broadcast
    ];

    /**
     * @return array{allowed:bool, reason:?string, host:?string, ip:?string}
     */
    public function check(?string $url): array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return $this->deny('empty_url');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $this->deny('malformed_url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            // blocks file://, gopher://, dict://, ftp:// and similar SSRF escalation schemes
            return $this->deny('scheme_not_allowed');
        }

        // Credentials in the URL are a redirect/parser-confusion trick; refuse them outright.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->deny('credentials_in_url');
        }

        $host = (string) $parts['host'];

        // A bare IP is checked directly; a hostname is resolved so DNS cannot point at private space.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            return $this->deny('host_did_not_resolve', $host);
        }

        // EVERY resolved address must be public — a hostname with one public and one private A
        // record must not slip through.
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return $this->deny('destination_is_private_or_reserved', $host, $ip);
            }
        }

        return ['allowed' => true, 'reason' => null, 'host' => $host, 'ip' => $ips[0]];
    }

    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Reject loopback (::1), unique-local (fc00::/7) and link-local (fe80::/10).
            $normalized = strtolower($ip);
            if ($normalized === '::1' || $normalized === '::') {
                return false;
            }
            $first = hexdec(substr(str_replace(':', '', $this->expandIpv6($normalized)), 0, 2));
            if ($first >= 0xfc && $first <= 0xfd) {   // fc00::/7 unique local
                return false;
            }
            if (($first & 0xffc0) === 0xfe80 || str_starts_with($normalized, 'fe80')) {
                return false;
            }
            // IPv4-mapped IPv6 (::ffff:169.254.169.254) must be judged on the embedded v4 address.
            if (preg_match('/::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $normalized, $m)) {
                return $this->isPublicIp($m[1]);
            }
            return true;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        foreach (self::BLOCKED_V4 as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** @return array<int, string> */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return $ips;
    }

    private function expandIpv6(string $ip): string
    {
        $bin = @inet_pton($ip);
        return $bin === false ? $ip : bin2hex($bin);
    }

    private function deny(string $reason, ?string $host = null, ?string $ip = null): array
    {
        return ['allowed' => false, 'reason' => $reason, 'host' => $host, 'ip' => $ip];
    }
}
