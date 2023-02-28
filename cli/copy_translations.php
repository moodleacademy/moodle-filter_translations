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
 * @package     filter_translations
 * @copyright   2023 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

// Define the input options.
$longparams = [
    'mode' => '',
    'file' => '',
];

$shortparams = [
    'm' => 'mode',
    'f' => 'file'
];

// Now get cli options.
list($options, $unrecognized) = cli_get_params($longparams, $shortparams);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (empty($options['mode'])) {
    cli_writeln(get_string('clihelptext_copytranslations', 'filter_translations'));
    die();
}

$columnsbytable = [];

foreach ($DB->get_tables(false) as $table) {
    $columnnames = [];

    foreach ($DB->get_columns($table) as $column) {
        $columnnames[] = $column->name;
    }
    foreach ($columnnames as $column) {
        if (!in_array($column . 'format', $columnnames)) {
            continue;
        }

        if (empty($columnsbytable[$table])) {
            $columnsbytable[$table] = [];
        }
        $columnsbytable[$table][] = $column;
    }
}

if ($options['mode'] == 'listcolumns') {
    cli_writeln(json_encode($columnsbytable, JSON_PRETTY_PRINT));
    die();
} else if ($options['mode'] == 'process' || $options['mode'] == 'dryrun') {
    if (empty($options['file'])) {
        cli_writeln(get_string('columndefinitionfileerror', 'filter_translations'));
        die();
    }

    try {
        $file = file_get_contents($options['file']);
        $columnsbytabletoprocess = json_decode($file);
    } catch (Exception $ex) {
        cli_writeln(get_string('columndefinitionfileerror', 'filter_translations'));
        die();
    }

    $transaction = $DB->start_delegated_transaction();
    foreach ($columnsbytabletoprocess as $table => $columns) {
        $filter = new filter_translations(context_system::instance(), []);

        cli_writeln("Started processing table: $table");

        foreach ($columns as $column) {
            if (!isset($columnsbytable[$table]) || !in_array($column, $columnsbytable[$table])) {
                cli_writeln('Unknown column or table.');
                die();
            }

            foreach ($DB->get_records_select($table, "$column IS NOT NULL AND $column <> ''") as $row) {
                // Skip if no translation span tag found.
                if (strpos($row->$column, 'data-translationhash') === false) {
                    continue;
                }

                $formattedcolumn = '';

                // Rendered content may be different.
                // Get rendered version of content.
                if ($column == 'intro') {
                    $cm = get_coursemodule_from_instance($table, $row->id, $row->course, false, MUST_EXIST);

                    $formattedcolumn = format_module_intro($table, $row, $cm->id);
                } else if (strpos($row->$column, '@@PLUGINFILE@@') !== false) {
                    // Need to get actual URIs.
                    // Attempt to generate correct URIs for each plugin.
                    switch ($table) {
                        case 'course_sections':
                            $context = context_course::instance($row->course);

                            $formattedcolumn = file_rewrite_pluginfile_urls($row->summary, 'pluginfile.php', $context->id,
                                'course', 'section', $row->id);
                        break;
                        case 'book_chapters':
                            $cm = get_coursemodule_from_instance('book', $row->bookid, 0, false, MUST_EXIST);
                            $context = context_module::instance($cm->id);

                            $formattedcolumn = file_rewrite_pluginfile_urls($row->content, 'pluginfile.php', $context->id,
                                'mod_book', 'chapter', $row->id);
                        break;
                        case 'page':
                            $cm = get_coursemodule_from_instance($table, $row->id, $row->course, false, MUST_EXIST);
                            $context = context_module::instance($cm->id);

                            $formattedcolumn = file_rewrite_pluginfile_urls($row->content, 'pluginfile.php', $context->id,
                                'mod_page', 'content', $row->revision);
                        break;
                        default:
                        break;
                    }
                }

                // Extract translation hash from content.
                $foundhash = $filter->findandremovehash($row->$column);

                if (empty($formattedcolumn)) {
                    // Generate hash of content.
                    $generatedhash = $filter->generatehash($row->$column);
                } else {
                    // Generate hash of content. Translation span tags are removed from $formattedcolumn.
                    $generatedhash = $filter->generatehash($formattedcolumn);
                }

                // Get all matching translations for this content.
                $foundhashtranslations = [];
                $generatedhashtranslations = [];
                $translations = $DB->get_records_select(
                    'filter_translations',
                    "md5key = '$foundhash' OR lastgeneratedhash = '$generatedhash'",
                    null,
                    "md5key");

                foreach ($translations as $tr) {
                    if ($tr->md5key == $foundhash) {
                        $foundhashtranslations[$tr->targetlanguage] = $tr; // Translations recorded for this content.
                    } else {
                        $generatedhashtranslations[$tr->targetlanguage] = $tr; // Translations matching this content hash.
                    }
                }

                // Copy over any translations not recorded under the found hash of this content.
                if (!empty($generatedhashtranslations)) {
                    cli_writeln("foundhash: $foundhash, content hash: $generatedhash");
                }

                foreach ($generatedhashtranslations as $tr) {
                    if (!isset($foundhashtranslations[$tr->targetlanguage])) {
                        cli_writeln("  + copying translation from md5key: $tr->md5key, lang: $tr->targetlanguage");

                        if ($options['mode'] == 'process') {
                            $record = $tr;
                            $record->md5key = $foundhash;

                            $DB->insert_record('filter_translations', $record);
                        }
                    }
                }
            }
        }

        cli_writeln("Finished processing table: $table");
        cli_writeln('');
    }
    $transaction->allow_commit();

    // Todo: Purge the translation cache.
}
