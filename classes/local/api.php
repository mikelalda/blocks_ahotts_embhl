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

class api {

    /**
     * Resolve the endpoint bucket for a language.
     *
     * @param string $language
     * @return string
     */
    public static function language_bucket(string $language): string {
        $language = strtolower($language);

        if (strpos($language, 'eu') === 0) {
            return 'eu';
        }

        if (strpos($language, 'es') === 0) {
            return 'es';
        }

        return 'en';
    }

    /**
     * Get the configured base URL for a language bucket.
     *
     * @param string $language
     * @return string
     */
    public static function base_url(string $language): string {
        switch (self::language_bucket($language)) {
            case 'eu':
                return trim((string) get_config('block_ahotts_embhl', 'apiurl_eu'));
            case 'es':
                return trim((string) get_config('block_ahotts_embhl', 'apiurl_es'));
            default:
                return trim((string) get_config('block_ahotts_embhl', 'apiurl_en'));
        }
    }

    /**
     * Build the custom API URL.
     *
     * @param string $language
     * @param string $pageurl
     * @param string $readid
     * @param string $customparams
     * @param string $audiofilename
     * @return string
     */
    public static function build_href(
        string $language,
        string $pageurl,
        string $readid = '',
        string $customparams = '',
        string $audiofilename = ''
    ): string {
        $baseurl = self::base_url($language);
        if ($baseurl === '') {
            return '';
        }

        $params = [
            'lang' => $language,
            'url' => $pageurl,
        ];

        if ($readid !== '') {
            $params['readid'] = $readid;
        }

        if ($audiofilename !== '') {
            $params['audiofilename'] = $audiofilename;
        }

        $separator = (strpos($baseurl, '?') === false) ? '?' : '&';
        $href = rtrim($baseurl, '?&') . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        if ($customparams !== '') {
            $href .= (strpos($customparams, '&') === 0 || strpos($customparams, '?') === 0)
                ? $customparams
                : '&' . ltrim($customparams, '&');
        }

        return $href;
    }
}