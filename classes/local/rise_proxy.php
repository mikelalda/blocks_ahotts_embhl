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

/**
 * Rise 360 hosted ("connected") SCORM packages served from this site's origin.
 *
 * Such a package only holds indexAPI.html, which frames the course from
 * packages.articulateusercontent.com: another origin, so the block cannot read
 * it. With the reverse proxy described by apache_config() in front of Moodle,
 * the same course is served from this origin instead, and this class points the
 * stored indexAPI.html of each package at it.
 *
 * Only the courses of this site's own packages are proxied. Rise lets authors
 * embed code in a course, and any course served from this origin runs with the
 * session of the Moodle user who opens it; an open proxy would let anyone with
 * an Articulate account run code here. The courses allowed are listed in a file
 * Apache reads as a RewriteMap, which this class keeps up to date.
 *
 * A package is only rewritten once the proxy has been seen answering for its
 * course, so a site without the proxy keeps working as before.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Rewrites connected Rise packages and keeps the proxy's list of courses.
 */
class rise_proxy {

    /** @var string Where Articulate hosts the courses and the LMS driver. */
    const UPSTREAM = 'https://packages.articulateusercontent.com';

    /** @var string Articulate's static CDN, which the course runtime loads with CORS. */
    const CDN = 'https://cdn.articulate.com';

    /** @var string Path the CDN is served under on this site. */
    const CDN_PREFIX = '/articulate-cdn';

    /** @var string[] The CDN folders a Rise course uses; nothing else of it is proxied. */
    const CDN_FOLDERS = ['rise', 'assets', 'fontawesome', 'mondrian', 'ai-scenario'];

    /** @var string A course id, as it appears in its URL. */
    const ID_PATTERN = '[A-Za-z0-9_-]{8,64}';

    /**
     * Whether new packages are rewritten as they are uploaded.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('block_ahotts_embhl', 'riseproxy');
    }

    /**
     * This site's origin: scheme, host and port of wwwroot, with no path.
     *
     * @return string
     */
    public static function site_origin(): string {
        global $CFG;
        $parts = parse_url($CFG->wwwroot);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * The file listing the courses Apache may proxy, one "id 1" per line.
     *
     * @return string
     */
    public static function allowlist_path(): string {
        global $CFG;
        return $CFG->dataroot . '/block_ahotts_embhl/rise-courses.txt';
    }

    /**
     * The Articulate course a package frames, whether or not it has been rewritten.
     *
     * @param string $html Content of indexAPI.html.
     * @param string $origin This site's origin.
     * @return string|null
     */
    public static function course_id(string $html, string $origin): ?string {
        foreach ([self::UPSTREAM, $origin] as $host) {
            $pattern = '~' . preg_quote($host, '~') . '/content/(' . self::ID_PATTERN . ')/boot\.html~';
            if (preg_match($pattern, $html, $match)) {
                return $match[1];
            }
        }
        return null;
    }

    /**
     * Point a package at this site's proxy.
     *
     * Everything indexAPI.html takes from Articulate goes through the proxy:
     * host.js, the course frame and REMOTE_PACKAGES_CDN_URL, which host.js uses
     * both to load the LMS driver and to check where the course's messages come from.
     *
     * @param string $html
     * @param string $origin
     * @return string
     */
    public static function rewrite(string $html, string $origin): string {
        return str_replace(self::UPSTREAM, $origin, $html);
    }

    /**
     * Undo rewrite().
     *
     * @param string $html
     * @param string $origin
     * @return string
     */
    public static function restore(string $html, string $origin): string {
        return str_replace($origin . '/content/', self::UPSTREAM . '/content/',
            str_replace('"REMOTE_PACKAGES_CDN_URL":"' . $origin . '"',
                '"REMOTE_PACKAGES_CDN_URL":"' . self::UPSTREAM . '"', $html));
    }

    /**
     * Whether a package is a connected Rise package, rewritten or not.
     *
     * @param string $html
     * @param string $origin
     * @return bool
     */
    public static function is_connected(string $html, string $origin): bool {
        return strpos($html, 'REMOTE_PACKAGES_CDN_URL') !== false && self::course_id($html, $origin) !== null;
    }

    /**
     * The stored indexAPI.html of every SCORM activity.
     *
     * @return \stored_file[]
     */
    public static function package_files(): array {
        global $DB;
        $fs = get_file_storage();
        $records = $DB->get_records('files', [
            'component' => 'mod_scorm',
            'filearea' => 'content',
            'filepath' => '/',
            'filename' => 'indexAPI.html',
        ]);
        return array_map(function($record) use ($fs) {
            return $fs->get_file_instance($record);
        }, array_values($records));
    }

    /**
     * The stored indexAPI.html of one SCORM activity.
     *
     * @param int $contextid
     * @return \stored_file|null
     */
    public static function package_file(int $contextid): ?\stored_file {
        $file = get_file_storage()->get_file($contextid, 'mod_scorm', 'content', 0, '/', 'indexAPI.html');
        return $file ?: null;
    }

    /**
     * Give the SCORM activity of a package a new revision.
     *
     * Moodle serves package files as immutable, under a URL holding the
     * activity's revision: without a new one, browsers that opened the course
     * before keep their copy of indexAPI.html and never see the change.
     *
     * @param int $contextid The package's context.
     */
    public static function bump_revision(int $contextid): void {
        global $DB;
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('scorm', $context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $DB->execute('UPDATE {scorm} SET revision = revision + 1 WHERE id = ?', [$cm->instance]);
        }
    }

    /**
     * Replace a stored file's content, keeping where it lives.
     *
     * @param \stored_file $file
     * @param string $content
     * @return \stored_file
     */
    protected static function replace_content(\stored_file $file, string $content): \stored_file {
        $record = [
            'contextid' => $file->get_contextid(),
            'component' => $file->get_component(),
            'filearea' => $file->get_filearea(),
            'itemid' => $file->get_itemid(),
            'filepath' => $file->get_filepath(),
            'filename' => $file->get_filename(),
            'timemodified' => time(),
        ];
        $file->delete();
        $stored = get_file_storage()->create_file_from_string($record, $content);
        self::bump_revision($record['contextid']);
        return $stored;
    }

    /**
     * The courses currently allowed.
     *
     * @return string[]
     */
    public static function allowed(): array {
        $path = self::allowlist_path();
        if (!is_readable($path)) {
            return [];
        }
        $ids = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $id = strtok(trim($line), " \t");
            if ($id !== false && preg_match('~^' . self::ID_PATTERN . '$~', $id)) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Write the list of allowed courses, atomically: Apache may read it at any time.
     *
     * @param string[] $ids
     */
    public static function write_allowed(array $ids): void {
        $path = self::allowlist_path();
        check_dir_exists(dirname($path));
        $ids = array_values(array_unique(array_filter($ids, function($id) {
            return (bool) preg_match('~^' . self::ID_PATTERN . '$~', $id);
        })));
        sort($ids);
        $content = "# Rise 360 courses served from this site by the reverse proxy. Written by block_ahotts_embhl.\n";
        foreach ($ids as $id) {
            $content .= $id . " 1\n";
        }
        $temp = $path . '.' . getmypid() . '.tmp';
        file_put_contents($temp, $content);
        @chmod($temp, 0644);
        rename($temp, $path);
    }

    /**
     * Ask this site's own proxy for a course, without going out to the network.
     *
     * The request goes to 127.0.0.1 with the site's host name, because a server
     * often cannot reach its own public address.
     *
     * @param string $id
     * @return bool Whether the proxy served the course and rewrote it to this origin.
     */
    public static function proxy_serves(string $id): bool {
        $origin = self::site_origin();
        $parts = parse_url($origin);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $handle = curl_init($origin . '/content/' . $id . '/boot.html');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':127.0.0.1'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return $status === 200 && is_string($body)
            && strpos($body, $origin . '/content/' . $id . '/') !== false;
    }

    /**
     * Point one package at the proxy, if it is a connected Rise package.
     *
     * @param \stored_file $file Its indexAPI.html.
     * @param bool $check Ask the proxy first, and leave the package alone if it does not answer.
     * @return array {status: notconnected|already|rewritten|noproxy, courseid}
     */
    public static function enable_file(\stored_file $file, bool $check = true): array {
        $origin = self::site_origin();
        $html = $file->get_content();
        if (!self::is_connected($html, $origin)) {
            return ['status' => 'notconnected', 'courseid' => null];
        }
        $id = self::course_id($html, $origin);
        self::write_allowed(array_merge(self::allowed(), [$id]));
        if (strpos($html, self::UPSTREAM) === false) {
            return ['status' => 'already', 'courseid' => $id];
        }
        if ($check && !self::proxy_serves($id)) {
            return ['status' => 'noproxy', 'courseid' => $id];
        }
        self::replace_content($file, self::rewrite($html, $origin));
        return ['status' => 'rewritten', 'courseid' => $id];
    }

    /**
     * Point one package back at Articulate.
     *
     * @param \stored_file $file
     * @return array {status: notconnected|already|restored, courseid}
     */
    public static function disable_file(\stored_file $file): array {
        $origin = self::site_origin();
        $html = $file->get_content();
        if (!self::is_connected($html, $origin)) {
            return ['status' => 'notconnected', 'courseid' => null];
        }
        $id = self::course_id($html, $origin);
        $restored = self::restore($html, $origin);
        if ($restored === $html) {
            return ['status' => 'already', 'courseid' => $id];
        }
        self::replace_content($file, $restored);
        return ['status' => 'restored', 'courseid' => $id];
    }

    /**
     * Rewrite a SCORM activity's package after it was saved, when the feature is on.
     *
     * @param int $cmid
     * @return array|null What enable_file() did, or null when there was nothing to do.
     */
    public static function package_saved(int $cmid): ?array {
        if (!self::enabled()) {
            return null;
        }
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        $file = $context ? self::package_file($context->id) : null;
        return $file ? self::enable_file($file) : null;
    }

    /**
     * The Apache configuration that serves the allowed courses from this origin.
     *
     * It goes inside the site's HTTPS VirtualHost and needs mod_proxy,
     * mod_proxy_http, mod_ssl, mod_rewrite, mod_headers and mod_substitute.
     *
     * @return string
     */
    public static function apache_config(): string {
        $origin = self::site_origin();
        $folders = implode('|', self::CDN_FOLDERS);
        $upstream = self::UPSTREAM;
        $cdn = self::CDN;
        $prefix = self::CDN_PREFIX;
        $allowlist = self::allowlist_path();
        $id = self::ID_PATTERN;
        return <<<APACHE
# block_ahotts_embhl: Rise 360 hosted ("connected") courses served from {$origin},
# so the reading block can read them. Goes at the end of the HTTPS VirtualHost, after
# any other <Location> section, so its cache headers win.
# Needs: a2enmod proxy proxy_http ssl rewrite headers substitute
# Generated by: php blocks/ahotts_embhl/cli/rise_proxy.php --apache

SSLProxyEngine on
SSLProxyVerify require
SSLProxyVerifyDepth 10
SSLProxyCheckPeerName on
SSLProxyCACertificateFile /etc/ssl/certs/ca-certificates.crt
ProxyRequests off

RewriteEngine on
# Courses of this site's own packages only, kept by the plugin. Any other course
# served from this origin could run code with the session of whoever opens it.
RewriteMap ahottsrise "txt:{$allowlist}"

RewriteCond "\${ahottsrise:\$1|no}" "!=no"
RewriteRule "^/content/({$id})/(.*)$" "{$upstream}/content/\$1/\$2" [P,L]
RewriteRule "^/content/" "-" [F,L]

# The SCORM driver Rise loads next to the course.
RewriteRule "^/rise-lms-driver/([0-9A-Za-z._-]+/[0-9A-Za-z._-]+)$" "{$upstream}/rise-lms-driver/\$1" [P,L]
RewriteRule "^/rise-lms-driver/" "-" [F,L]

# The course runtime, which Articulate's CDN only lets its own origins load.
RewriteRule "^{$prefix}/((?:{$folders})/.*)$" "{$cdn}/\$1" [P,L]
RewriteRule "^{$prefix}/" "-" [F,L]

<LocationMatch "^/(content|rise-lms-driver|articulate-cdn)/">
    # Articulate's CDN answers by host name: send its own, whatever the VirtualHost says.
    ProxyPreserveHost off
    # Never hand this site's session or credentials to Articulate, nor take its cookies.
    RequestHeader unset Cookie
    RequestHeader unset Authorization
    Header unset Set-Cookie
    Header always unset Set-Cookie
    # Nor where in Moodle the request comes from.
    RequestHeader unset Origin
    RequestHeader unset Referer
    # Plain responses, so the absolute URLs in them can be pointed back here.
    RequestHeader set Accept-Encoding "identity"
    AddOutputFilterByType SUBSTITUTE text/html text/css text/javascript application/javascript application/json
    SubstituteMaxLineLength 16m
    Substitute "s|{$upstream}|{$origin}|nq"
    Substitute "s|{$cdn}|{$origin}{$prefix}|nq"
    # A course updated in Rise keeps its URLs: browsers must check for a new version.
    <IfModule mod_expires.c>
        ExpiresActive off
    </IfModule>
    Header set Cache-Control "no-cache"
</LocationMatch>

APACHE;
    }
}
