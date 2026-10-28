<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * The course of a Rise 360 hosted ("connected") package, read from Articulate.
 *
 * Such a package only holds indexAPI.html; the course lives at
 * packages.articulateusercontent.com. The browser gets it the way this class
 * does, server side, to build the PDF and the audio of the whole course:
 *
 *  1. indexAPI.html frames /content/<course id>/boot.html#token=<token>.
 *  2. GET /content/<id>/boot.html?bootEnabled=true describes the course,
 *     including the version Rise gives each publication of it.
 *  3. POST /content/<id>/content.html with courseAccessToken=<token> answers
 *     the course page, the hosted equivalent of scormcontent/index.html.
 *
 * Only the course named by the package's own indexAPI.html is read, with its
 * own token, and only from Articulate's hosts. Nothing of the Moodle session
 * is sent.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hosted_course {

    /** @var string[] Hosts the course and its media may be read from. */
    const HOSTS = ['packages.articulateusercontent.com', 'articulateusercontent.com', 'cdn.articulate.com'];

    /** @var int Largest response accepted, in bytes. */
    const MAX_BYTES = 31457280;

    /** @var string The course id. */
    private $courseid;

    /** @var string The package's access token for it. */
    private $token;

    /** @var callable fn(string $method, string $url, array $post): ?array {status, body}. */
    private $http;

    /** @var array Responses by URL; null for a failed one. */
    private $cache = [];

    /** @var string|null|false The course page; false until asked for. */
    private $page = false;

    /** @var string URL relative paths of the course page resolve against. */
    private $base;

    /** @var string What went wrong last, for the logs. */
    private $error = '';

    /**
     * @param string $courseid
     * @param string $token
     * @param callable|null $http fn(string $method, string $url, array $post): ?array {status, body}; Moodle's curl by default.
     */
    public function __construct(string $courseid, string $token, ?callable $http = null) {
        $this->courseid = $courseid;
        $this->token = $token;
        $this->http = $http ?? [self::class, 'moodle_http'];
        $this->base = rise_proxy::UPSTREAM . '/content/' . $courseid . '/';
    }

    /**
     * The hosted course of a package, if its indexAPI.html frames one.
     *
     * @param string $indexapi Content of indexAPI.html, rewritten for the proxy or not.
     * @param string $origin This site's origin.
     * @param callable|null $http
     * @return self|null
     */
    public static function from_package(string $indexapi, string $origin, ?callable $http = null): ?self {
        if (!rise_proxy::is_connected($indexapi, $origin)) {
            return null;
        }
        $id = rise_proxy::course_id($indexapi, $origin);
        $token = self::token($indexapi, $id);
        return $token !== null ? new self($id, $token, $http) : null;
    }

    /**
     * The hosted course of a SCORM activity, if its package is a connected one.
     *
     * @param \context_module $context
     * @return self|null
     */
    public static function for_context(\context_module $context): ?self {
        $file = rise_proxy::package_file($context->id);
        return $file ? self::from_package($file->get_content(), rise_proxy::site_origin()) : null;
    }

    /**
     * The access token indexAPI.html hands its course frame.
     *
     * @param string $indexapi
     * @param string $id
     * @return string|null
     */
    public static function token(string $indexapi, string $id): ?string {
        $pattern = '~/content/' . preg_quote($id, '~') . '/boot\.html[^"\'\s<>#]*#(?:[^"\'\s<>]*&)?token=([^"\'\s<>&]+)~';
        if (!preg_match($pattern, html_entity_decode($indexapi, ENT_QUOTES | ENT_HTML5), $match)) {
            return null;
        }
        $token = rawurldecode($match[1]);
        return preg_match('~^[\x21-\x7e]{1,4096}$~', $token) ? $token : null;
    }

    /**
     * The course id.
     *
     * @return string
     */
    public function course_id(): string {
        return $this->courseid;
    }

    /**
     * What went wrong last, for the logs.
     *
     * @return string
     */
    public function error(): string {
        return $this->error;
    }

    /**
     * The version of the course Articulate serves now.
     *
     * A course republished in Rise keeps its package in Moodle unchanged: this
     * is what tells a new publication apart.
     *
     * @return string|null
     */
    public function version(): ?string {
        $boot = $this->get($this->base . 'boot.html?bootEnabled=true');
        if ($boot === null || !preg_match('~id="__COURSE_INFO__"[^>]*content="([A-Za-z0-9+/=]+)"~', $boot, $match)) {
            return null;
        }
        $info = json_decode((string) base64_decode($match[1], true), true);
        $version = is_array($info) ? (string) ($info['courseVersion'] ?? '') : '';
        return preg_match('~^[A-Za-z0-9_.-]{1,100}$~', $version) ? $version : null;
    }

    /**
     * The course page, as the browser gets it once the token is accepted.
     *
     * @return string|null
     */
    public function page(): ?string {
        if ($this->page === false) {
            $this->page = $this->request('POST', $this->base . 'content.html', ['courseAccessToken' => $this->token]);
            // A <base> in the page moves where its relative paths point.
            if ($this->page !== null && preg_match('~<base\s[^>]*href="([^"]+)"~i', $this->page, $match)) {
                $base = $this->absolute(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), $this->base);
                if ($base !== null) {
                    $this->base = preg_replace('~[^/]*$~', '', $base);
                }
            }
        }
        return $this->page;
    }

    /**
     * Read the course as if it were a package: scormcontent/ is the course.
     *
     * Absolute URLs on Articulate's hosts are read too, for media Rise links
     * to directly.
     *
     * @return callable fn(string $path): ?string
     */
    public function reader(): callable {
        return function(string $path): ?string {
            if (preg_match('~^https://~', $path)) {
                return $this->get($path);
            }
            if ($path === 'scormcontent/index.html') {
                return $this->page();
            }
            if (strpos($path, 'scormcontent/') !== 0) {
                return null;
            }
            $this->page();
            $url = $this->absolute(substr($path, strlen('scormcontent/')), $this->base);
            return $url !== null ? $this->get($url) : null;
        };
    }

    /**
     * GET a URL on Articulate's hosts, once.
     *
     * @param string $url
     * @return string|null
     */
    public function get(string $url): ?string {
        if (!array_key_exists($url, $this->cache)) {
            $this->cache[$url] = $this->request('GET', $url, []);
        }
        return $this->cache[$url];
    }

    /**
     * Resolve a path against a URL, keeping only https URLs on Articulate's hosts.
     *
     * @param string $path
     * @param string $base
     * @return string|null
     */
    private function absolute(string $path, string $base): ?string {
        $path = trim($path);
        if (preg_match('~^https://~i', $path)) {
            $url = $path;
        } else if (strpos($path, '//') === 0) {
            $url = 'https:' . $path;
        } else if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $path)) {
            return null;
        } else {
            $parts = parse_url($base);
            $dir = $path !== '' && $path[0] === '/' ? '' : preg_replace('~[^/]*$~', '', $parts['path'] ?? '/');
            $segments = [];
            foreach (explode('/', $dir . preg_replace('~[?#].*$~', '', $path)) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    array_pop($segments);
                    continue;
                }
                $segments[] = $segment;
            }
            $query = preg_match('~\?([^#]*)~', $path, $match) ? '?' . $match[1] : '';
            $url = 'https://' . $parts['host'] . '/' . implode('/', $segments) . $query;
        }
        return self::allowed($url) ? $url : null;
    }

    /**
     * Whether a URL may be read: https on one of Articulate's hosts.
     *
     * @param string $url
     * @return bool
     */
    public static function allowed(string $url): bool {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https' && empty($parts['user']) && empty($parts['pass'])
            && (empty($parts['port']) || (int) $parts['port'] === 443)
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true);
    }

    /**
     * Make a request, keeping the answer only when it is a complete 200.
     *
     * @param string $method
     * @param string $url
     * @param array $post
     * @return string|null
     */
    private function request(string $method, string $url, array $post): ?string {
        if (!self::allowed($url)) {
            $this->error = 'refused ' . $url;
            return null;
        }
        try {
            $response = ($this->http)($method, $url, $post);
        } catch (\Throwable $e) {
            $response = null;
            $this->error = $method . ' ' . $url . ': ' . $e->getMessage();
        }
        if (!is_array($response) || (int) ($response['status'] ?? 0) !== 200 || !is_string($response['body'] ?? null)
                || strlen($response['body']) > self::MAX_BYTES) {
            if (is_array($response)) {
                $this->error = $method . ' ' . preg_replace('~\?.*$~', '', $url) . ': HTTP ' . (int) ($response['status'] ?? 0);
            }
            return null;
        }
        return $response['body'];
    }

    /**
     * Requests through Moodle's curl, which follows the site's proxy and security settings.
     *
     * No cookie, credential or Referer of this site is sent, and redirects are
     * not followed, so a request cannot leave Articulate's hosts.
     *
     * @param string $method
     * @param string $url
     * @param array $post
     * @return array {status, body}
     */
    public static function moodle_http(string $method, string $url, array $post): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $options = [
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXFILESIZE' => self::MAX_BYTES,
            'CURLOPT_USERAGENT' => 'Mozilla/5.0 (compatible; Moodle block_ahotts_embhl)',
        ];
        $body = $method === 'POST' ? $curl->post($url, http_build_query($post, '', '&'), $options)
            : $curl->get($url, [], $options);
        $info = $curl->get_info();
        return ['status' => (int) ($info['http_code'] ?? 0), 'body' => $curl->get_errno() ? null : $body];
    }
}
