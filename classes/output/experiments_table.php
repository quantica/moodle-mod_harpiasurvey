<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_harpiasurvey\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;

/**
 * Experiments table renderable class for course page display.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiments_table implements renderable, templatable {

    /**
     * @var array Experiments array
     */
    public $experiments;

    /**
     * @var object Context object
     */
    public $context;

    /**
     * @var int Course module ID
     */
    public $cmid;

    /**
     * @var bool Whether user can manage experiments
     */
    public $canmanage;

    /**
     * Class constructor.
     *
     * @param array $experiments
     * @param object $context
     * @param int $cmid
     * @param bool $canmanage
     */
    public function __construct($experiments, $context, $cmid, $canmanage) {
        $this->experiments = $experiments;
        $this->context = $context;
        $this->cmid = $cmid;
        $this->canmanage = $canmanage;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $OUTPUT, $PAGE;

        // Prepare experiments list.
        $experimentslist = [];
        $now = time();
        foreach ($this->experiments as $experiment) {
            // Determine status.
            $isavailable = false;
            if ($experiment->availability > 0) {
                if ($experiment->availability <= $now) {
                    if ($experiment->availabilityend == 0 || $experiment->availabilityend >= $now) {
                        $isavailable = true;
                    }
                }
            } else {
                $isavailable = ($experiment->published == 1);
            }

            if ($experiment->status === 'finished') {
                $statusdisplay = get_string('statusfinished', 'mod_harpiasurvey');
            } else if ($isavailable && ($experiment->status === 'running' || $experiment->published == 1)) {
                $statusdisplay = get_string('statusrunning', 'mod_harpiasurvey');
            } else {
                $statusdisplay = get_string('statusdraft', 'mod_harpiasurvey');
            }

            // Max participants display.
            $maxparticipants = !empty($experiment->maxparticipants) ? (int)$experiment->maxparticipants : get_string('unlimited', 'mod_harpiasurvey');

            // URLs.
            $viewurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
                'id' => $this->cmid,
                'experiment' => $experiment->id
            ]);

            $experimentdata = [
                'id' => $experiment->id,
                'name' => format_string($experiment->name),
                'viewurl' => $viewurl->out(false),
                'participants' => (int)$experiment->participants,
                'maxparticipants' => $maxparticipants,
                'status' => $statusdisplay,
            ];

            // Actions (only if can manage).
            if ($this->canmanage) {
                $statsurl = new \moodle_url('/mod/harpiasurvey/stats.php', [
                    'id' => $this->cmid,
                    'experiment' => $experiment->id
                ]);
                $editurl = new \moodle_url('/mod/harpiasurvey/edit_experiment.php', [
                    'id' => $this->cmid,
                    'experiment' => $experiment->id
                ]);
                $deleteurl = new \moodle_url('/mod/harpiasurvey/delete_experiment.php', [
                    'id' => $this->cmid,
                    'experiment' => $experiment->id
                ]);

                $experimentdata['statsurl'] = $statsurl->out(false);
                $experimentdata['editurl'] = $editurl->out(false);
                $experimentdata['deleteurl'] = $deleteurl->out(false);
                $experimentdata['statsicon'] = $OUTPUT->pix_icon('i/stats', get_string('viewstats', 'mod_harpiasurvey'), 'moodle', ['class' => 'icon']);
                $experimentdata['editicon'] = $OUTPUT->pix_icon('t/edit', get_string('edit'), 'moodle', ['class' => 'icon']);
                $experimentdata['deleteicon'] = $OUTPUT->pix_icon('t/delete', get_string('delete'), 'moodle', ['class' => 'icon']);
            }

            $experimentslist[] = $experimentdata;
        }

        // Action URLs (only if can manage).
        $actionurls = [];
        if ($this->canmanage) {
            $createurl = new \moodle_url('/mod/harpiasurvey/edit_experiment.php', ['id' => $this->cmid]);
            $registerurl = new \moodle_url('/mod/harpiasurvey/edit_model.php', ['id' => $this->cmid]);
            $actionurls = [
                'createurl' => $createurl->out(false),
                'createlabel' => get_string('createexperiment', 'mod_harpiasurvey'),
                'registerurl' => $registerurl->out(false),
                'registerlabel' => get_string('registermodel', 'mod_harpiasurvey'),
            ];

            // Question bank is experiment-scoped. On course page, open it for the first available experiment.
            if (!empty($experimentslist)) {
                $firstexperimentid = (int)$experimentslist[0]['id'];
                $questionbankurl = new \moodle_url('/mod/harpiasurvey/question_bank.php', [
                    'id' => $this->cmid,
                    'experiment' => $firstexperimentid
                ]);
                $actionurls['questionbankurl'] = $questionbankurl->out(false);
                $actionurls['questionbanklabel'] = get_string('questionbank', 'mod_harpiasurvey');
                $actionurls['has_questionbank'] = true;
            }
        }

        return [
            'experiments' => $experimentslist,
            'has_experiments' => !empty($experimentslist),
            'noexperiments' => get_string('noexperiments', 'mod_harpiasurvey'),
            'canmanage' => $this->canmanage,
            'editingmode' => $PAGE->user_is_editing(),
            'actionurls' => $actionurls,
            'experimentnamelabel' => get_string('experimentname', 'mod_harpiasurvey'),
            'participantslabel' => get_string('participants', 'mod_harpiasurvey'),
            'maxparticipantslabel' => get_string('maxparticipants', 'mod_harpiasurvey'),
            'statuslabel' => get_string('status', 'mod_harpiasurvey'),
            'actionslabel' => get_string('actions', 'mod_harpiasurvey'),
            'wwwroot' => $CFG->wwwroot,
        ];
    }
}
