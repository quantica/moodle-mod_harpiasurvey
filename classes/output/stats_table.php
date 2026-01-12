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
use moodle_url;

/**
 * Stats table renderable class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stats_table implements renderable, templatable {

    /**
     * @var array Responses array (includes both regular responses and conversations)
     */
    public $responses;

    /**
     * @var object Context object
     */
    public $context;

    /**
     * Class constructor.
     *
     * @param array $responses
     * @param object $context
     */
    public function __construct($responses, $context) {
        $this->responses = $responses;
        $this->context = $context;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $PAGE;
        
        // Build download URL
        $downloadurl = new moodle_url($PAGE->url, ['download' => 'csv']);
        
        return [
            'responses' => $this->responses,
            'has_responses' => !empty($this->responses),
            'noresponses' => get_string('noresponses', 'mod_harpiasurvey'),
            'userlabel' => get_string('user'),
            'questionlabel' => get_string('question', 'mod_harpiasurvey'),
            'questiontypelabel' => get_string('type', 'mod_harpiasurvey'),
            'conversationtypelabel' => get_string('conversationtype', 'mod_harpiasurvey'),
            'modellabel' => get_string('model', 'mod_harpiasurvey'),
            'evaluatesconversationlabel' => get_string('evaluatesconversation', 'mod_harpiasurvey'),
            'turnidlabel' => get_string('turnid', 'mod_harpiasurvey'),
            'answerlabel' => get_string('answer', 'mod_harpiasurvey'),
            'timelabel' => get_string('time', 'mod_harpiasurvey'),
            'timemodifiedlabel' => get_string('timemodified', 'mod_harpiasurvey'),
            'editedlabel' => get_string('edited', 'mod_harpiasurvey'),
            'downloadurl' => $downloadurl->out(false),
            'wwwroot' => $CFG->wwwroot,
        ];
    }
}

