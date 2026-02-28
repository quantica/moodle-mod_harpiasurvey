<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

/**
 * Check whether an experiment is finalized for a user.
 *
 * @param int $experimentid
 * @param int $userid
 * @return bool
 */
function mod_harpiasurvey_is_experiment_finalized_for_user(int $experimentid, int $userid): bool {
    global $DB;
    if ($experimentid <= 0 || $userid <= 0 || !$DB->get_manager()->table_exists('harpiasurvey_experiment_finalizations')) {
        return false;
    }
    return $DB->record_exists('harpiasurvey_experiment_finalizations', [
        'experimentid' => $experimentid,
        'userid' => $userid
    ]);
}

/**
 * Get finalization record for a user.
 *
 * @param int $experimentid
 * @param int $userid
 * @return stdClass|false
 */
function mod_harpiasurvey_get_experiment_finalization_record(int $experimentid, int $userid) {
    global $DB;
    if (!$DB->get_manager()->table_exists('harpiasurvey_experiment_finalizations')) {
        return false;
    }
    return $DB->get_record('harpiasurvey_experiment_finalizations', [
        'experimentid' => $experimentid,
        'userid' => $userid
    ]);
}

/**
 * Build applicable evaluation scopes for an AI page.
 *
 * @param stdClass $page
 * @param int $userid
 * @return array
 */
function mod_harpiasurvey_get_page_finalization_scopes(stdClass $page, int $userid): array {
    global $DB;

    $scopes = [];
    $pageid = (int)$page->id;
    $behavior = $page->behavior ?? 'continuous';
    $placeholder = '[New conversation - send a message to start]';

    if (($page->type ?? '') !== 'aichat') {
        return [[
            'scopeid' => 0,
            'turn_id' => null,
            'modelid' => null,
        ]];
    }

    if ($behavior === 'review_conversation') {
        if (!$DB->get_manager()->table_exists('harpiasurvey_review_datasets') ||
                !$DB->get_manager()->table_exists('harpiasurvey_review_threads')) {
            return [];
        }
        $dataset = $DB->get_record('harpiasurvey_review_datasets', [
            'pageid' => $pageid,
            'status' => 'ready'
        ]);
        if (!$dataset) {
            return [];
        }
        $threads = $DB->get_records('harpiasurvey_review_threads', ['datasetid' => $dataset->id], 'thread_order ASC, id ASC');
        foreach ($threads as $thread) {
            $target = $DB->get_record('harpiasurvey_review_targets', [
                'pageid' => $pageid,
                'userid' => $userid,
                'threadid' => $thread->id
            ], 'id', IGNORE_MISSING);
            $scopes[] = [
                'scopeid' => (int)$thread->id,
                'turn_id' => $target ? (int)$target->id : (-(int)$thread->id),
                'threadid' => (int)$thread->id,
                'modelid' => null,
            ];
        }
        return $scopes;
    }

    if ($behavior === 'continuous') {
        $records = $DB->get_records_sql(
            "SELECT id AS scopeid, modelid
               FROM {harpiasurvey_conversations}
              WHERE pageid = :pageid
                AND userid = :userid
                AND role = 'user'
                AND parentid IS NULL
                AND content <> :placeholder
           ORDER BY timecreated ASC",
            ['pageid' => $pageid, 'userid' => $userid, 'placeholder' => $placeholder]
        );
        foreach ($records as $r) {
            $scopes[] = [
                'scopeid' => (int)$r->scopeid,
                'turn_id' => (int)$r->scopeid,
                'modelid' => isset($r->modelid) ? (int)$r->modelid : null,
            ];
        }
        return $scopes;
    }

    if ($behavior === 'qa') {
        $records = $DB->get_records_sql(
            "SELECT turn_id AS scopeid, MAX(modelid) AS modelid, MIN(timecreated) AS firsttime
               FROM {harpiasurvey_conversations}
              WHERE pageid = :pageid
                AND userid = :userid
                AND role = 'user'
                AND turn_id IS NOT NULL
                AND content <> :placeholder
           GROUP BY turn_id
           ORDER BY firsttime ASC",
            ['pageid' => $pageid, 'userid' => $userid, 'placeholder' => $placeholder]
        );
        foreach ($records as $r) {
            $scopes[] = [
                'scopeid' => (int)$r->scopeid,
                'turn_id' => (int)$r->scopeid,
                'modelid' => isset($r->modelid) ? (int)$r->modelid : null,
            ];
        }
        return $scopes;
    }

    if ($behavior === 'turns') {
        $records = $DB->get_records_sql(
            "SELECT DISTINCT turn_id, modelid
               FROM {harpiasurvey_conversations}
              WHERE pageid = :pageid
                AND userid = :userid
                AND turn_id IS NOT NULL
                AND content <> :placeholder
           ORDER BY turn_id ASC, modelid ASC",
            ['pageid' => $pageid, 'userid' => $userid, 'placeholder' => $placeholder]
        );
        foreach ($records as $r) {
            $turnid = (int)$r->turn_id;
            $modelid = isset($r->modelid) ? (int)$r->modelid : null;
            $scopes[] = [
                'scopeid' => $turnid . ':' . ($modelid ?? 0),
                'turn_id' => $turnid,
                'modelid' => $modelid,
            ];
        }
        return $scopes;
    }

    return $scopes;
}

/**
 * Check whether a page question mapping applies to a given scope.
 *
 * @param stdClass $mapping
 * @param stdClass $page
 * @param array $scope
 * @return bool
 */
function mod_harpiasurvey_page_question_applies_to_scope(stdClass $mapping, stdClass $page, array $scope): bool {
    $behavior = $page->behavior ?? 'continuous';
    $turnid = $scope['turn_id'] ?? null;
    $modelid = $scope['modelid'] ?? null;

    if (($page->type ?? '') === 'aichat' && $behavior === 'turns' && $turnid !== null) {
        if (!empty($mapping->show_only_turn) && (int)$mapping->show_only_turn !== (int)$turnid) {
            return false;
        }
        if (!empty($mapping->hide_on_turn) && (int)$mapping->hide_on_turn === (int)$turnid) {
            return false;
        }
    }

    if ($modelid !== null) {
        if (!empty($mapping->show_only_model) && (int)$mapping->show_only_model !== (int)$modelid) {
            return false;
        }
        if (!empty($mapping->hide_on_model) && (int)$mapping->hide_on_model === (int)$modelid) {
            return false;
        }
    } else if (!empty($mapping->show_only_model)) {
        return false;
    }

    return true;
}

/**
 * Check whether a subpage is visible on a turn.
 *
 * @param stdClass $subpage
 * @param int|null $turnid
 * @return bool
 */
function mod_harpiasurvey_subpage_visible_for_turn(stdClass $subpage, ?int $turnid): bool {
    if ($turnid === null) {
        return false;
    }
    $type = $subpage->turn_visibility_type ?? 'all_turns';
    if ($type === 'all_turns') {
        return true;
    }
    if ($type === 'first_turn') {
        return (int)$turnid === 1;
    }
    if ($type === 'specific_turn') {
        return !empty($subpage->turn_number) && (int)$subpage->turn_number === (int)$turnid;
    }
    return true;
}

/**
 * Check whether a subpage question mapping applies on a turn.
 *
 * @param stdClass $mapping
 * @param int|null $turnid
 * @return bool
 */
function mod_harpiasurvey_subpage_question_applies_to_turn(stdClass $mapping, ?int $turnid): bool {
    if ($turnid === null) {
        return false;
    }
    $type = $mapping->turn_visibility_type ?? 'all_turns';
    if ($type === 'all_turns') {
        return true;
    }
    if ($type === 'first_turn') {
        return (int)$turnid === 1;
    }
    if ($type === 'specific_turn') {
        return !empty($mapping->turn_number) && (int)$mapping->turn_number === (int)$turnid;
    }
    return true;
}

/**
 * Build finalization summary for a participant.
 *
 * @param int $experimentid
 * @param int $userid
 * @param context_module|null $context
 * @return array
 */
function mod_harpiasurvey_get_experiment_finalization_summary(int $experimentid, int $userid, $context = null): array {
    global $DB;

    $summary = [
        'answered' => 0,
        'unanswered' => 0,
        'requiredanswered' => 0,
        'requiredunanswered' => 0,
        'canfinalize' => true,
        'pages' => [],
    ];

    $pages = $DB->get_records('harpiasurvey_pages', ['experimentid' => $experimentid, 'available' => 1], 'sortorder ASC');
    if (empty($pages)) {
        return $summary;
    }

    $pageids = array_keys($pages);

    list($pageinsql, $pageinparams) = $DB->get_in_or_equal($pageids, SQL_PARAMS_NAMED, 'p');
    $responses = $DB->get_records_sql(
        "SELECT pageid, questionid, turn_id, response
           FROM {harpiasurvey_responses}
          WHERE userid = :userid
            AND pageid {$pageinsql}",
        array_merge(['userid' => $userid], $pageinparams)
    );

    $responsesindex = [];
    foreach ($responses as $r) {
        $turnkey = ($r->turn_id === null) ? 0 : (int)$r->turn_id;
        $responsesindex[(int)$r->pageid][(int)$r->questionid][$turnkey] = (string)$r->response;
    }

    list($pginsql, $pginparams) = $DB->get_in_or_equal($pageids, SQL_PARAMS_NAMED, 'pg');
    $pagemappings = $DB->get_records_sql(
        "SELECT pq.*, q.enabled AS questionenabled
           FROM {harpiasurvey_page_questions} pq
           JOIN {harpiasurvey_questions} q ON q.id = pq.questionid
          WHERE pq.pageid {$pginsql}
            AND pq.enabled = 1
            AND q.enabled = 1
       ORDER BY pq.sortorder ASC",
        $pginparams
    );
    $pagemappingsbypage = [];
    foreach ($pagemappings as $m) {
        $pagemappingsbypage[(int)$m->pageid][] = $m;
    }

    list($spinsql, $spinparams) = $DB->get_in_or_equal($pageids, SQL_PARAMS_NAMED, 'spg');
    $subpages = $DB->get_records_sql(
        "SELECT *
           FROM {harpiasurvey_subpages}
          WHERE pageid {$spinsql}
            AND available = 1
       ORDER BY sortorder ASC",
        $spinparams
    );
    $subpagesbypage = [];
    $subpageids = [];
    foreach ($subpages as $sp) {
        $subpagesbypage[(int)$sp->pageid][] = $sp;
        $subpageids[] = (int)$sp->id;
    }

    $subpagemappingsbysubpage = [];
    if (!empty($subpageids)) {
        list($sqinsql, $sqinparams) = $DB->get_in_or_equal($subpageids, SQL_PARAMS_NAMED, 'sqp');
        $submappings = $DB->get_records_sql(
            "SELECT sq.*, q.enabled AS questionenabled
               FROM {harpiasurvey_subpage_questions} sq
               JOIN {harpiasurvey_questions} q ON q.id = sq.questionid
              WHERE sq.subpageid {$sqinsql}
                AND sq.enabled = 1
                AND q.enabled = 1
           ORDER BY sq.sortorder ASC",
            $sqinparams
        );
        foreach ($submappings as $sm) {
            $subpagemappingsbysubpage[(int)$sm->subpageid][] = $sm;
        }
    }

    foreach ($pages as $page) {
        $pageid = (int)$page->id;
        $pagesummary = [
            'pageid' => $pageid,
            'title' => format_string($page->title),
            'answered' => 0,
            'unanswered' => 0,
            'requiredunanswered' => 0,
        ];

        $scopes = mod_harpiasurvey_get_page_finalization_scopes($page, $userid);
        if (($page->type ?? '') !== 'aichat') {
            $scopes = [[
                'scopeid' => 0,
                'turn_id' => null,
                'modelid' => null,
            ]];
        }

        $countedinstances = [];

        foreach ($pagemappingsbypage[$pageid] ?? [] as $mapping) {
            if (($page->type ?? '') === 'aichat') {
                foreach ($scopes as $scope) {
                    if (!mod_harpiasurvey_page_question_applies_to_scope($mapping, $page, $scope)) {
                        continue;
                    }
                    $instancekey = 'pq:' . $mapping->id . ':' . ($scope['scopeid'] ?? 0);
                    if (isset($countedinstances[$instancekey])) {
                        continue;
                    }
                    $countedinstances[$instancekey] = true;
                    $turnkey = ($scope['turn_id'] === null) ? 0 : (int)$scope['turn_id'];
                    $value = $responsesindex[$pageid][(int)$mapping->questionid][$turnkey] ?? null;
                    $answered = ($value !== null && trim((string)$value) !== '');
                    if ($answered) {
                        $summary['answered']++;
                        $pagesummary['answered']++;
                        if (!empty($mapping->required)) {
                            $summary['requiredanswered']++;
                        }
                    } else {
                        $summary['unanswered']++;
                        $pagesummary['unanswered']++;
                        if (!empty($mapping->required)) {
                            $summary['requiredunanswered']++;
                            $pagesummary['requiredunanswered']++;
                        }
                    }
                }
            } else {
                $instancekey = 'pq:' . $mapping->id . ':0';
                if (isset($countedinstances[$instancekey])) {
                    continue;
                }
                $countedinstances[$instancekey] = true;
                $value = $responsesindex[$pageid][(int)$mapping->questionid][0] ?? null;
                $answered = ($value !== null && trim((string)$value) !== '');
                if ($answered) {
                    $summary['answered']++;
                    $pagesummary['answered']++;
                    if (!empty($mapping->required)) {
                        $summary['requiredanswered']++;
                    }
                } else {
                    $summary['unanswered']++;
                    $pagesummary['unanswered']++;
                    if (!empty($mapping->required)) {
                        $summary['requiredunanswered']++;
                        $pagesummary['requiredunanswered']++;
                    }
                }
            }
        }

        // Subpage questions (turns mode only).
        if (($page->type ?? '') === 'aichat' && ($page->behavior ?? '') === 'turns' && !empty($scopes)) {
            foreach ($subpagesbypage[$pageid] ?? [] as $subpage) {
                foreach ($scopes as $scope) {
                    $turnid = $scope['turn_id'] ?? null;
                    if (!mod_harpiasurvey_subpage_visible_for_turn($subpage, $turnid)) {
                        continue;
                    }
                    foreach ($subpagemappingsbysubpage[(int)$subpage->id] ?? [] as $sm) {
                        if (!mod_harpiasurvey_subpage_question_applies_to_turn($sm, $turnid)) {
                            continue;
                        }
                        $instancekey = 'sq:' . $sm->id . ':' . ($scope['scopeid'] ?? 0);
                        if (isset($countedinstances[$instancekey])) {
                            continue;
                        }
                        $countedinstances[$instancekey] = true;
                        $turnkey = ($turnid === null) ? 0 : (int)$turnid;
                        $value = $responsesindex[$pageid][(int)$sm->questionid][$turnkey] ?? null;
                        $answered = ($value !== null && trim((string)$value) !== '');
                        if ($answered) {
                            $summary['answered']++;
                            $pagesummary['answered']++;
                            if (!empty($sm->required)) {
                                $summary['requiredanswered']++;
                            }
                        } else {
                            $summary['unanswered']++;
                            $pagesummary['unanswered']++;
                            if (!empty($sm->required)) {
                                $summary['requiredunanswered']++;
                                $pagesummary['requiredunanswered']++;
                            }
                        }
                    }
                }
            }
        }

        $summary['pages'][] = $pagesummary;
    }

    $summary['canfinalize'] = ($summary['requiredunanswered'] === 0);
    return $summary;
}
