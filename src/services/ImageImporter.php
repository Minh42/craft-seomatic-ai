<?php

namespace seomatic\craftai\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\FileHelper;
use seomatic\craftai\helpers\ApiException;
use Throwable;
use yii\base\Component;

/**
 * Downloads a remote image safely and saves it as an asset.
 *
 * - http/https only, ports 80/443/8080/8443 only;
 * - every address the host resolves to must be public (no private,
 *   loopback, link-local, reserved or metadata addresses), and the
 *   connection is pinned to a checked address (no DNS rebinding);
 * - redirects are followed by hand (max 3), each hop checked again;
 * - at most 15 MB, aborted while downloading;
 * - only JPEG, PNG, GIF, WebP and AVIF, checked from the bytes (SVG is
 *   refused: it can carry script).
 */
class ImageImporter extends Component
{
    public const MAX_BYTES = 15728640; // 15 MB
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 20;

    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function import(string $url, AssetsField $field, ElementInterface $element, ?string $alt): Asset
    {
        $tmp = $this->download($url, $mime);
        try {
            $folderId = $field->resolveDynamicPathToFolderId($element);
            $folder = Craft::$app->getAssets()->getFolderById((int)$folderId);
            if (!$folder) {
                throw new ApiException('save_failed', 'the asset field has no upload location', 422);
            }
            $filename = $this->filenameFor($url, $mime);

            $asset = new Asset();
            $asset->tempFilePath = $tmp;
            $asset->setFilename($filename);
            $asset->newFolderId = (int)$folder->id;
            $asset->setVolumeId((int)$folder->volumeId);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            if ($alt !== null && $alt !== '') {
                $asset->alt = $alt;
            }
            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new ApiException('save_failed', 'the asset could not be saved: ' . implode(' ', $asset->getFirstErrors()), 422);
            }
            return $asset;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Downloads to a temp file and returns its path. $mime is set to the
     * sniffed image type.
     */
    public function download(string $url, ?string &$mime = null): string
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('unsupported', 'PHP curl is not available on this server', 400);
        }
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->checkUrl($current);
            $tmp = tempnam(Craft::$app->getPath()->getTempPath(), 'smai');
            if ($tmp === false) {
                throw new ApiException('server_error', 'no temp file', 500);
            }
            $fh = fopen($tmp, 'wb');
            $bytes = 0;
            $tooBig = false;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $current,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_USERAGENT => 'SEOmatic-AI-Craft/1.0 (+https://seomatic.ai)',
                CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1'],
                CURLOPT_HEADER => false,
                CURLOPT_FILE => $fh,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use (&$tooBig) {
                    if ($dlTotal > self::MAX_BYTES || $dlNow > self::MAX_BYTES) {
                        $tooBig = true;
                        return 1; // abort
                    }
                    return 0;
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            } elseif (defined('CURLOPT_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($tooBig) {
                @unlink($tmp);
                throw new ApiException('invalid', 'the image is larger than 15 MB', 400);
            }
            if ($ok === false) {
                @unlink($tmp);
                throw new ApiException('invalid', 'the image could not be downloaded' . ($err ? ' (' . $err . ')' : ''), 400);
            }
            if ($status >= 300 && $status < 400 && is_string($location) && $location !== '') {
                @unlink($tmp);
                $current = $location;
                continue;
            }
            if ($status !== 200) {
                @unlink($tmp);
                throw new ApiException('invalid', 'the image URL answered HTTP ' . $status, 400);
            }
            $size = filesize($tmp);
            if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
                @unlink($tmp);
                throw new ApiException('invalid', $size ? 'the image is larger than 15 MB' : 'the image is empty', 400);
            }
            $mime = $this->sniff($tmp);
            if ($mime === null) {
                @unlink($tmp);
                throw new ApiException('invalid', 'not an image (JPEG, PNG, GIF, WebP or AVIF)', 400);
            }
            return $tmp;
        }
        throw new ApiException('invalid', 'too many redirects', 400);
    }

    /** @return array{0:string,1:int,2:string} host, port, checked IP */
    private function checkUrl(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1f]/', $url)) {
            throw new ApiException('invalid', 'not a valid image URL', 400);
        }
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            throw new ApiException('invalid', 'the image URL must be http or https', 400);
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new ApiException('invalid', 'the image URL must not contain credentials', 400);
        }
        $host = strtolower(trim($p['host'], '[]'));
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            throw new ApiException('invalid', 'the image URL uses a port that is not allowed', 400);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = $this->resolve($host);
        }
        if ($ips === []) {
            throw new ApiException('invalid', 'the image host does not resolve', 400);
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ApiException('invalid', 'the image host is a private or local address', 400);
            }
        }
        return [$host, $port, $ips[0]];
    }

    /** @return string[] */
    private function resolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        // IPv4-mapped / compatible IPv6: check the embedded IPv4.
        if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return self::isPublicIp(inet_ntop(substr($bin, 12)));
        }
        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
            '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        ];
        foreach ($blocked as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;
        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** The image type from the file's own bytes, or null. */
    private function sniff(string $path): ?string
    {
        $mime = null;
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = finfo_file($f, $path) ?: null;
                finfo_close($f);
            }
        }
        if ($mime === null || !isset(self::MIME_EXT[$mime])) {
            $info = @getimagesize($path);
            $mime = is_array($info) ? ($info['mime'] ?? null) : $mime;
        }
        return ($mime !== null && isset(self::MIME_EXT[$mime])) ? $mime : null;
    }

    private function filenameFor(string $url, string $mime): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = pathinfo(rawurldecode($path), PATHINFO_FILENAME);
        $base = FileHelper::sanitizeFilename((string)$base, ['asciiOnly' => true, 'separator' => '-']);
        $base = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? '', '-.');
        if ($base === '') {
            $base = 'image';
        }
        $base = substr($base, 0, 100);
        return $base . '.' . self::MIME_EXT[$mime];
    }
}
