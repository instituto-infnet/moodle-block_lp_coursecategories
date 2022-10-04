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
 * Renderer do bloco de planos por categoria de curso.
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_lp_coursecategories\output;
defined('MOODLE_INTERNAL') || die();

use plugin_renderer_base;
use renderable;

/**
 * Classe de renderer do bloco de planos por categoria de curso.
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {

    /**
     * Delegar ao template.
     * @param renderable $planlist
     * @return string
     */
    public function render_plan_list(plan_list $planlist) {
        $data = $planlist->export_for_template($this);
        return parent::render_from_template('block_lp_coursecategories/summary', $data);
    }


    /**
     * Delegar ao template.
     * @param renderable $planlist
     * @return string
     */
    public function render_full_report(plan_list $planlist) {
        $data = $planlist->export_for_template($this);
        return parent::render_from_template('block_lp_coursecategories/full_report', $data);
    }

    /**
     * Delegar ao template, versão para o RCA.
     * @param renderable $planlist
     * @return string
     */
    public function render_full_report_rca(plan_list $planlist) {
        $data = $planlist->export_for_template($this);
        return parent::render_from_template('block_lp_coursecategories/full_report_rca', $data);
    }
}
