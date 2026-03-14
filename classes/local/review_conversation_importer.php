<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_harpiasurvey\local;

defined('MOODLE_INTERNAL') || die();

/**
 * CSV importer for review-conversation datasets.
 */
class review_conversation_importer {
    /** @var string[] */
    private const REQUIRED_HEADERS = [
        'turn id',
        'model id',
        'role',
        'content',
        'timestamp',
        'message id',
        'parent id',
    ];

    /**
     * Import review conversation CSV for a page.
     *
     * @param int $pageid
     * @param \stored_file $file
     * @param int $uploadedby
     * @return array
     */
    public static function import_for_page(int $pageid, \stored_file $file, int $uploadedby): array {
        global $DB;

        $parsed = self::parse_csv_file($file);
        $messages = $parsed['messages'];
        $threads = $parsed['threads'];
        $contenthash = hash('sha256', (string)$file->get_content());
        $filename = $file->get_filename();

        $existingdataset = $DB->get_record('harpiasurvey_review_datasets', ['pageid' => $pageid]);
        if ($existingdataset && !empty($existingdataset->contenthash) && $existingdataset->contenthash === $contenthash) {
            return [
                'reimported' => false,
                'rows' => count($messages),
                'threads' => count($threads),
                'datasetid' => (int)$existingdataset->id,
                'filename' => $existingdataset->filename,
            ];
        }

        $transaction = $DB->start_delegated_transaction();
        $now = time();

        // Capture current target mappings (user + target id => thread_key) before replacing dataset.
        $targetmapping = [];
        if ($existingdataset) {
            $targetrows = $DB->get_records_sql(
                "SELECT t.id AS targetid, t.userid, rt.thread_key
                   FROM {harpiasurvey_review_targets} t
                   JOIN {harpiasurvey_review_threads} rt ON rt.id = t.threadid
                  WHERE t.pageid = :pageid
                    AND rt.datasetid = :datasetid",
                ['pageid' => $pageid, 'datasetid' => $existingdataset->id]
            );
            foreach ($targetrows as $row) {
                $targetmapping[$row->targetid] = [
                    'userid' => (int)$row->userid,
                    'thread_key' => $row->thread_key,
                ];
            }
        }

        // Replace dataset row.
        if ($existingdataset) {
            $datasetid = (int)$existingdataset->id;
            $existingdataset->uploadedby = $uploadedby;
            $existingdataset->filename = $filename;
            $existingdataset->contenthash = $contenthash;
            $existingdataset->status = 'ready';
            $existingdataset->errormessage = null;
            $existingdataset->timemodified = $now;
            $DB->update_record('harpiasurvey_review_datasets', $existingdataset);

            // Clean old import data for this dataset.
            $DB->delete_records('harpiasurvey_review_message_threads', ['datasetid' => $datasetid]);
            $DB->delete_records('harpiasurvey_review_messages', ['datasetid' => $datasetid]);
            $DB->delete_records('harpiasurvey_review_threads', ['datasetid' => $datasetid]);
        } else {
            $datasetid = (int)$DB->insert_record('harpiasurvey_review_datasets', (object)[
                'pageid' => $pageid,
                'uploadedby' => $uploadedby,
                'filename' => $filename,
                'contenthash' => $contenthash,
                'status' => 'ready',
                'errormessage' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $threadidbykey = [];
        foreach ($threads as $index => $thread) {
            $threadid = (int)$DB->insert_record('harpiasurvey_review_threads', (object)[
                'datasetid' => $datasetid,
                'thread_key' => $thread['thread_key'],
                'root_source_message_id' => $thread['root_source_message_id'],
                'thread_order' => $index,
                'label' => $thread['label'],
            ]);
            $threadidbykey[$thread['thread_key']] = $threadid;
        }

        $messageidbysourceid = [];
        foreach ($messages as $message) {
            $messageid = (int)$DB->insert_record('harpiasurvey_review_messages', (object)[
                'datasetid' => $datasetid,
                'source_message_id' => $message['source_message_id'],
                'source_parent_id' => $message['source_parent_id'],
                'source_turn_id' => $message['source_turn_id'],
                'source_model_id' => $message['source_model_id'],
                'role' => $message['role'],
                'content' => $message['content'],
                'sourcets' => $message['sourcets'],
                'sortindex' => $message['sortindex'],
            ]);
            $messageidbysourceid[$message['source_message_id']] = $messageid;
        }

        foreach ($messages as $message) {
            if (!isset($messageidbysourceid[$message['source_message_id']])) {
                continue;
            }
            if (!isset($threadidbykey[$message['thread_key']])) {
                continue;
            }
            $DB->insert_record('harpiasurvey_review_message_threads', (object)[
                'datasetid' => $datasetid,
                'reviewmessageid' => $messageidbysourceid[$message['source_message_id']],
                'threadid' => $threadidbykey[$message['thread_key']],
            ]);
        }

        // Remap old targets by thread key and remove stale targets.
        $staletargetids = [];
        foreach ($targetmapping as $targetid => $info) {
            if (!isset($threadidbykey[$info['thread_key']])) {
                $staletargetids[] = $targetid;
                continue;
            }
            $DB->update_record('harpiasurvey_review_targets', (object)[
                'id' => $targetid,
                'threadid' => $threadidbykey[$info['thread_key']],
                'timemodified' => $now,
            ]);
        }
        if (!empty($staletargetids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($staletargetids, SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {harpiasurvey_review_targets} WHERE id {$insql}", $inparams);
        }

        $transaction->allow_commit();

        return [
            'reimported' => true,
            'rows' => count($messages),
            'threads' => count($threads),
            'datasetid' => $datasetid,
            'filename' => $filename,
        ];
    }

    /**
     * Parse CSV into normalized messages and threads.
     *
     * @param \stored_file $file
     * @return array
     */
    private static function parse_csv_file(\stored_file $file): array {
        $tmp = $file->copy_content_to_temp();
        $fh = fopen($tmp, 'rb');
        if (!$fh) {
            throw new \moodle_exception('reviewimporterror', 'mod_harpiasurvey');
        }

        $headerline = fgets($fh);
        if ($headerline === false) {
            fclose($fh);
            throw new \moodle_exception('reviewinvalidcsvheaders', 'mod_harpiasurvey');
        }

        $delimiter = self::detect_csv_delimiter($headerline);
        $headers = str_getcsv($headerline, $delimiter);
        if (!is_array($headers)) {
            fclose($fh);
            throw new \moodle_exception('reviewinvalidcsvheaders', 'mod_harpiasurvey');
        }
        $normalizedheaders = array_map(static function($h) {
            $h = (string)$h;
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            $h = preg_replace('/^\x{FEFF}/u', '', $h);
            // Normalize uncommon whitespace to regular spaces and collapse multiples.
            $h = preg_replace('/\x{00A0}/u', ' ', $h);
            $h = preg_replace('/\s+/u', ' ', $h);
            $h = trim($h);
            $h = trim($h, "\"' \t\n\r\0\x0B");
            return \core_text::strtolower($h);
        }, $headers);

        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $normalizedheaders, true)) {
                fclose($fh);
                throw new \moodle_exception('reviewinvalidcsvheaders', 'mod_harpiasurvey');
            }
        }

        $idx = array_flip($normalizedheaders);
        $sourceids = [];
        $rows = [];
        $line = 1;
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $line++;
            if (empty(array_filter($row, static function($value) {
                return trim((string)$value) !== '';
            }))) {
                continue;
            }

            $sourceid = trim((string)($row[$idx['message id']] ?? ''));
            if ($sourceid === '') {
                fclose($fh);
                throw new \moodle_exception('reviewduplicateid', 'mod_harpiasurvey', '', 'line ' . $line);
            }
            if (isset($sourceids[$sourceid])) {
                fclose($fh);
                throw new \moodle_exception('reviewduplicateid', 'mod_harpiasurvey', '', $sourceid);
            }
            $sourceids[$sourceid] = true;

            $role = \core_text::strtolower(trim((string)($row[$idx['role']] ?? 'other')));
            if (!in_array($role, ['user', 'assistant', 'system'], true)) {
                $role = 'other';
            }

            $ts = trim((string)($row[$idx['timestamp']] ?? ''));
            $sourcets = null;
            if ($ts !== '') {
                $parsed = strtotime($ts);
                if ($parsed !== false) {
                    $sourcets = (int)$parsed;
                }
            }

            $turnid = trim((string)($row[$idx['turn id']] ?? ''));
            $modelid = trim((string)($row[$idx['model id']] ?? ''));
            $parentid = trim((string)($row[$idx['parent id']] ?? ''));

            $rows[] = [
                'source_message_id' => $sourceid,
                'source_parent_id' => ($parentid !== '' ? $parentid : null),
                'source_turn_id' => ($turnid !== '' && is_numeric($turnid)) ? (int)$turnid : null,
                'source_model_id' => ($modelid !== '' && is_numeric($modelid)) ? (int)$modelid : null,
                'role' => $role,
                'content' => (string)($row[$idx['content']] ?? ''),
                'sourcets' => $sourcets,
                'sortindex' => count($rows),
            ];
        }
        fclose($fh);

        $rowsbysource = [];
        foreach ($rows as $row) {
            $rowsbysource[$row['source_message_id']] = $row;
        }

        // Resolve roots and detect cycles.
        $threadorder = [];
        foreach ($rows as &$row) {
            $root = self::resolve_root_id($row['source_message_id'], $rowsbysource);
            if (!isset($threadorder[$root])) {
                $threadorder[$root] = count($threadorder);
            }
            $row['thread_key'] = 'root:' . $root;
        }
        unset($row);

        $threads = [];
        foreach ($threadorder as $root => $order) {
            $threads[] = [
                'thread_key' => 'root:' . $root,
                'root_source_message_id' => $root,
                'label' => 'Conversation ' . ($order + 1),
            ];
        }

        return [
            'messages' => $rows,
            'threads' => $threads,
        ];
    }

    /**
     * Detect CSV delimiter from a header line.
     *
     * @param string $headerline
     * @return string
     */
    private static function detect_csv_delimiter(string $headerline): string {
        $candidates = [',', ';', "\t"];
        $bestdelimiter = ',';
        $bestscore = -1;
        foreach ($candidates as $candidate) {
            $score = substr_count($headerline, $candidate);
            if ($score > $bestscore) {
                $bestscore = $score;
                $bestdelimiter = $candidate;
            }
        }
        return $bestdelimiter;
    }

    /**
     * Resolve thread root from message ancestry.
     *
     * @param string $sourceid
     * @param array $rowsbysource
     * @return string
     */
    private static function resolve_root_id(string $sourceid, array $rowsbysource): string {
        $current = $sourceid;
        $seen = [];
        while (isset($rowsbysource[$current])) {
            if (isset($seen[$current])) {
                throw new \moodle_exception('reviewinvalidgraph', 'mod_harpiasurvey');
            }
            $seen[$current] = true;
            $parent = $rowsbysource[$current]['source_parent_id'] ?? null;
            if ($parent === null || $parent === '' || !isset($rowsbysource[$parent])) {
                return $current;
            }
            $current = $parent;
        }
        return $sourceid;
    }
}
