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
 * Models view renderable class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class models_view implements renderable, templatable {

    /**
     * @var array Models array
     */
    public $models;

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
     * @var bool Whether user can manage experiments
     */
    public $canmanage;

    /**
     * Class constructor.
     *
     * @param array $models
     * @param object $context
     * @param int $cmid
     * @param int $experimentid
     * @param bool $canmanage
     */
    public function __construct($models, $context, $cmid, $experimentid, $canmanage = true) {
        $this->models = $models;
        $this->context = $context;
        $this->cmid = $cmid;
        $this->experimentid = $experimentid;
        $this->canmanage = $canmanage;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG;

        // Prepare models list.
        $modelslist = [];
        foreach ($this->models as $model) {
            $editurl = new \moodle_url('/mod/harpiasurvey/edit_model.php', [
                'id' => $this->cmid,
                'model' => $model->id,
                'experiment' => $this->experimentid
            ]);
            $deleteurl = new \moodle_url('/mod/harpiasurvey/delete_model.php', [
                'id' => $this->cmid,
                'model' => $model->id,
                'experiment' => $this->experimentid
            ]);
            
            $modelslist[] = [
                'id' => $model->id,
                'name' => format_string($model->name),
                'model' => format_string($model->model),
                'description' => !empty($model->description) ? format_text($model->description, FORMAT_MOODLE, [
                    'context' => $this->context,
                    'noclean' => false
                ]) : '',
                'endpoint' => !empty($model->endpoint) ? format_string($model->endpoint) : '',
                'enabled' => (bool)$model->enabled,
                'timecreated' => $model->timecreated ? userdate($model->timecreated, get_string('strftimedatetime', 'langconfig')) : '',
                'timemodified' => $model->timemodified ? userdate($model->timemodified, get_string('strftimedatetime', 'langconfig')) : '',
                'editurl' => $editurl->out(false),
                'deleteurl' => $deleteurl->out(false),
            ];
        }

        return [
            'models' => $modelslist,
            'has_models' => !empty($modelslist),
            'nodels' => get_string('nodels', 'mod_harpiasurvey'),
            'canmanage' => (bool)$this->canmanage,
            'wwwroot' => $CFG->wwwroot,
            'namelabel' => get_string('modelname', 'mod_harpiasurvey'),
            'modellabel' => get_string('model', 'mod_harpiasurvey'),
            'descriptionlabel' => get_string('description', 'mod_harpiasurvey'),
            'endpointlabel' => get_string('endpoint', 'mod_harpiasurvey'),
            'statuslabel' => get_string('status', 'mod_harpiasurvey'),
            'timecreatedlabel' => get_string('timecreated', 'mod_harpiasurvey'),
            'timemodifiedlabel' => get_string('timemodified', 'mod_harpiasurvey'),
            'enabledlabel' => get_string('enabled', 'mod_harpiasurvey'),
            'disabledlabel' => get_string('disabled', 'mod_harpiasurvey'),
            'actionslabel' => get_string('actions', 'mod_harpiasurvey'),
            'editlabel' => get_string('edit'),
            'deletelabel' => get_string('delete'),
            'addmodellabel' => get_string('addmodel', 'mod_harpiasurvey'),
            'addmodelurl' => (new \moodle_url('/mod/harpiasurvey/edit_model.php', ['id' => $this->cmid]))->out(false),
        ];
    }
}

