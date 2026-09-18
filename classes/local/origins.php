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
 * Allow list of origins that may answer the cooperative SCORM bridge.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Parsing and matching of the configured SCORM bridge origins.
 *
 * An entry is an origin: scheme, host and optional port, with no path. A single
 * leading "*." wildcard in the host is accepted and matches sub-domains only.
 */
class origins {

    /** @var string Accepted shape of one entry. */
    const PATTERN = '~^https?://(\*\.)?[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?(:[0-9]{1,5})?$~';

    /**
     * Parse the admin setting into a clean list of origins.
     *
     * Entries may be separated by new lines, commas or spaces. Anything that is
     * not a well formed origin is dropped, so a typo can never widen the list.
     *
     * @param string|null $raw Raw setting value.
     * @return string[] Normalised, unique origins.
     */
    public static function parse(?string $raw): array {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $entries = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($entries as $entry) {
            $origin = self::normalise($entry);
            if ($origin !== '' && !in_array($origin, $out, true)) {
                $out[] = $origin;
            }
        }

        return $out;
    }

    /**
     * Normalise one entry, returning '' when it is not a usable origin.
     *
     * @param string $entry
     * @return string
     */
    public static function normalise(string $entry): string {
        $entry = trim($entry);
        if ($entry === '') {
            return '';
        }

        // Keep scheme://host[:port] only; a path, query or fragment is not part
        // of an origin and would never match event.origin.
        if (preg_match('~^(https?://[^/?#]+)~i', $entry, $matches)) {
            $entry = $matches[1];
        }
        $entry = rtrim($entry, '/');

        $lower = strtolower($entry);
        if (!preg_match(self::PATTERN, $lower)) {
            return '';
        }

        return $lower;
    }

    /**
     * Whether an origin is covered by a list of entries.
     *
     * Mirrors originAllowed() in amd/src/speech.js; keep both in step.
     *
     * @param string $origin Origin to test, e.g. https://scorm.example.org.
     * @param string[] $allowed Parsed allow list.
     * @return bool
     */
    public static function is_allowed(string $origin, array $allowed): bool {
        $origin = self::normalise($origin);
        if ($origin === '' || strpos($origin, '*') !== false) {
            return false;
        }

        foreach ($allowed as $entry) {
            $candidate = self::normalise($entry);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $origin) {
                return true;
            }
            if (preg_match('~^(https?://)\*\.(.+)$~', $candidate, $matches)) {
                $scheme = $matches[1];
                $domain = $matches[2];
                if (strpos($origin, $scheme) !== 0) {
                    continue;
                }
                $host = substr($origin, strlen($scheme));
                // Sub-domains only: "example.org" itself does not match "*.example.org".
                if ($host !== $domain && substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The origins configured by the administrator.
     *
     * @return string[]
     */
    public static function configured(): array {
        return self::parse((string) get_config('block_ahotts_embhl', 'scormorigins'));
    }

    /**
     * Whether the cross-origin bridge client should run at all.
     *
     * @return bool
     */
    public static function bridge_enabled(): bool {
        return (bool) get_config('block_ahotts_embhl', 'scormbridge') && self::configured() !== [];
    }
}
