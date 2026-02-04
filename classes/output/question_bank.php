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
 * Question bank renderable class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_bank implements renderable, templatable {

    /**
     * @var array Questions array
     */
    public $questions;

    /**
     * @var object Context object
     */
    public $context;

    /**
     * @var int Course module ID
     */
    public $cmid;

    /**
     * @var int Experiment ID
     */
    public $experimentid;

    /**
     * Class constructor.
     *
     * @param array $questions
     * @param object $context
     * @param int $cmid
     * @param int $experimentid
     */
    public function __construct($questions, $context, $cmid, $experimentid) {
        $this->questions = $questions;
        $this->context = $context;
        $this->cmid = $cmid;
        $this->experimentid = $experimentid;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $OUTPUT, $PAGE;

        // Prepare questions list.
        $questionslist = [];
        foreach ($this->questions as $question) {
            // Truncate description.
            $description = format_text($question->description, $question->descriptionformat, ['context' => $this->context]);
            $description = strip_tags($description);
            if (strlen($description) > 100) {
                $description = substr($description, 0, 100) . '...';
            }

            // Get type display string.
            $typedisplay = get_string('type' . $question->type, 'mod_harpiasurvey');

            // Action URLs.
            $editurl = new \moodle_url('/mod/harpiasurvey/edit_question.php', [
                'id' => $this->cmid,
                'experiment' => $this->experimentid,
                'question' => $question->id
            ]);
            $deleteurl = new \moodle_url('/mod/harpiasurvey/delete_question.php', [
                'id' => $this->cmid,
                'experiment' => $this->experimentid,
                'question' => $question->id
            ]);

            // Icons.
            $editicon = $OUTPUT->pix_icon('t/edit', get_string('edit'), 'moodle', ['class' => 'icon']);
            $deleteicon = $OUTPUT->pix_icon('t/delete', get_string('delete'), 'moodle', ['class' => 'icon']);

            $questionslist[] = [
                'id' => $question->id,
                'name' => format_string($question->name),
                'type' => $typedisplay,
                'description' => $description,
                'editurl' => $editurl->out(false),
                'deleteurl' => $deleteurl->out(false),
                'editicon' => $editicon,
                'deleteicon' => $deleteicon,
            ];
        }

        // Prepare URLs.
        $questionsurl = new \moodle_url('/mod/harpiasurvey/question_bank.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid
        ]);
        $pagesurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid
        ]);
        $createurl = new \moodle_url('/mod/harpiasurvey/edit_question.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid
        ]);
        $backurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid
        ]);

        return [
            'questionsurl' => $questionsurl->out(false),
            'pagesurl' => $pagesurl->out(false),
            'questionslabel' => get_string('questions', 'mod_harpiasurvey'),
            'pageslabel' => get_string('pages', 'mod_harpiasurvey'),
            'questions' => $questionslist,
            'has_questions' => !empty($questionslist),
            'noquestions' => get_string('noquestions', 'mod_harpiasurvey'),
            'createurl' => $createurl->out(false),
            'createquestionlabel' => get_string('createquestion', 'mod_harpiasurvey'),
            'backurl' => $backurl->out(false),
            'backlabel' => get_string('back', 'mod_harpiasurvey'),
            'questionlabel' => get_string('question', 'mod_harpiasurvey'),
            'typelabel' => get_string('type', 'mod_harpiasurvey'),
            'descriptionlabel' => get_string('description', 'mod_harpiasurvey'),
            'actionslabel' => get_string('actions', 'mod_harpiasurvey'),
            'wwwroot' => $CFG->wwwroot,
            'editingmode' => $PAGE->user_is_editing(),
        ];
    }
}
