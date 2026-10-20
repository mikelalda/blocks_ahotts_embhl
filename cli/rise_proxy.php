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
 * Serve hosted Rise 360 ("connected") SCORM packages from this site, so the block can read them.
 *
 *   sudo -u www-data php blocks/ahotts_embhl/cli/rise_proxy.php --apache   Print the Apache configuration
 *   sudo -u www-data php blocks/ahotts_embhl/cli/rise_proxy.php --status   List the hosted packages
 *   sudo -u www-data php blocks/ahotts_embhl/cli/rise_proxy.php --enable   Point them at the proxy
 *   sudo -u www-data php blocks/ahotts_embhl/cli/rise_proxy.php --disable  Point them back at Articulate
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use block_ahotts_embhl\local\rise_proxy;

[$options] = cli_get_params([
    'apache' => false,
    'status' => false,
    'enable' => false,
    'disable' => false,
    'no-check' => false,
    'help' => false,
], ['h' => 'help']);

$actions = array_filter(['apache', 'status', 'enable', 'disable'], function($name) use ($options) {
    return !empty($options[$name]);
});
if ($options['help'] || count($actions) !== 1) {
    cli_writeln(<<<USAGE
Serve hosted Rise 360 ("connected") SCORM packages from this site, so the reading block can read them.

  --apache    Print the Apache configuration for this site, and create the list of allowed courses
              Apache reads. Install it first: inside the HTTPS VirtualHost, after
              a2enmod proxy proxy_http ssl rewrite headers substitute.
  --status    List the hosted Rise packages and whether they go through the proxy.
  --enable    Point every hosted package at the proxy, once the proxy answers for its course,
              and keep doing so for packages uploaded later.
  --disable   Point every package back at Articulate and empty the list of allowed courses.
  --no-check  With --enable: rewrite without asking the proxy first.

Run it as the web server user, for example: sudo -u www-data php blocks/ahotts_embhl/cli/rise_proxy.php --status
USAGE);
    exit($options['help'] ? 0 : 1);
}

/**
 * Where a package lives, for the report.
 *
 * @param stored_file $file
 * @return string
 */
$describe = function(stored_file $file): string {
    $context = context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
    if (!$context || $context->contextlevel != CONTEXT_MODULE) {
        return 'context ' . $file->get_contextid();
    }
    $cm = get_coursemodule_from_id('scorm', $context->instanceid, 0, false, IGNORE_MISSING);
    return $cm ? 'cmid ' . $cm->id . ' ' . format_string($cm->name) : 'cmid ' . $context->instanceid;
};

if ($options['apache']) {
    if (!is_file(rise_proxy::allowlist_path())) {
        // Apache refuses to start when a RewriteMap file is missing.
        rise_proxy::write_allowed([]);
    }
    echo rise_proxy::apache_config();
    exit(0);
}

$origin = rise_proxy::site_origin();
$counts = [];

foreach (rise_proxy::package_files() as $file) {
    $html = $file->get_content();
    if (!rise_proxy::is_connected($html, $origin)) {
        continue;
    }
    if ($options['status']) {
        $proxied = strpos($html, rise_proxy::UPSTREAM) === false;
        $status = $proxied ? 'proxied' : 'articulate';
        cli_writeln(sprintf('%-10s %s  %s', $status, rise_proxy::course_id($html, $origin), $describe($file)));
    } else if ($options['enable']) {
        $result = rise_proxy::enable_file($file, !$options['no-check']);
        $status = $result['status'];
        if ($status === 'already') {
            // Make browsers that cached an earlier copy fetch the package again.
            rise_proxy::bump_revision($file->get_contextid());
        }
        cli_writeln(sprintf('%-10s %s  %s', $status, $result['courseid'], $describe($file)));
    } else {
        $result = rise_proxy::disable_file($file);
        $status = $result['status'];
        cli_writeln(sprintf('%-10s %s  %s', $status, $result['courseid'], $describe($file)));
    }
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}

if ($options['enable']) {
    set_config('riseproxy', 1, 'block_ahotts_embhl');
    if (!empty($counts['noproxy'])) {
        cli_problem('The proxy did not answer for ' . $counts['noproxy'] . ' package(s): they still load from Articulate.'
            . ' Check the Apache configuration (--apache) and run --enable again.');
    }
} else if ($options['disable']) {
    set_config('riseproxy', 0, 'block_ahotts_embhl');
    rise_proxy::write_allowed([]);
}

$summary = [];
foreach ($counts as $status => $count) {
    $summary[] = $status . ': ' . $count;
}
cli_writeln($summary ? implode(', ', $summary) : 'No hosted Rise packages found.');
exit(!empty($counts['noproxy']) ? 2 : 0);
