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
 * Renderable da lista planos de aprendizado.
 *
 * Contém a classe plan_list, que exibe a lista de planos de aprendizado do bloco.
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_lp_coursecategories\output;
defined('MOODLE_INTERNAL') || die();

use core_competency\api;
use core_competency\external\plan_exporter;

use renderable;
use renderer_base;
use templatable;

/**
 * Classe de lista de planos de aprendizado.
 *
 * Exibe a lista de planos de aprendizado do estudante, agrupados por categoria de curso.
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plan_list implements renderable, templatable {

    /** @var array Planos. */
    protected $plans = array();
    /** @var array Categorias de cursos dos planos. */
    protected $plancoursecategories = array();
    /** @var stdClass O usuário. */
    protected $user;

    /**
     * Construtor.
     *
     * @param stdClass $user O usuário.
     */
    public function __construct($user = null) {
        global $USER;
        if (!$user) {
            $user = $USER;
        }
        $this->user = $user;

        // Obter os planos de aprendizado.
        $this->plans = api::list_user_plans($user->id);

        // Obter as categorias de cursos de cada plano.
        $this->plancoursecategories = $this->get_plan_course_categories($user->id);
    }

    public function export_for_template(renderer_base $output) {
        $plancategories = array();

        foreach ($this->plans as $plan) {
            $planexporter = new plan_exporter($plan, array('template' => $plan->get_template()));
            $exportedplan = $planexporter->export($output);
            $plancategory = $this->plancoursecategories[$exportedplan->id];

            if (!isset($plancategories[$plancategory->categoryid])) {
                $category = new \stdClass();
                $category->categoryname = $plancategory->categoryname;
                $category->categoryid = $plancategory->categoryid;
                $category->plans = array();

                $plancategories[$plancategory->categoryid] = $category;
            }

            $plancategories[$plancategory->categoryid]->plans[] = $exportedplan;
        }

        return array(
            'hasplans' => !empty($this->plans),
            'plancategories' => array_values($plancategories),
            'userid' => $this->user->id
        );
    }

    /**
     * Retorna se há conteúdo na lista de planos.
     *
     * @return boolean
     */
    public function has_content() {
        return !empty($this->plans); //|| $this->planstoreview['count'] > 0 || $this->compstoreview['count'] > 0;
    }

    private function get_plan_course_categories() {
        global $DB;

        return $DB->get_records_sql("
            select p.id planid,
                cc.id categoryid,
                cc.name categoryname,
                cc.sortorder categorysortorder,
                c.sortorder coursesortorder
            from {competency_plan} p
                join {competency_template} t on t.id = p.templateid
                join {competency_templatecomp} tc on tc.templateid = t.id
                join {competency_usercompcourse} ucc on ucc.competencyid = tc.competencyid
                    and ucc.userid = p.userid
                join {course} c on c.id = ucc.courseid
                join {course_categories} cc on cc.id = c.category
            where p.userid = ?
            group by p.id
        ", array($this->user->id));
    }
}
