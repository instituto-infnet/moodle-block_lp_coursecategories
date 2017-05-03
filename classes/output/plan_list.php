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
    /** @var array Categorias dos planos. */
    protected $plancategories = array();
    /** @var stdClass O usuário. */
    protected $user;
    /** @var string Nome da terceira categoria acima do curso. */
    protected $category3name;

    /**
     * Construtor.
     */
    public function __construct() {
        global $USER;

        $this->user = $USER;
        $userid = $this->user->id;

        // Obter os planos de aprendizado.
        $this->plans = api::list_user_plans($userid);

        // Obter as categorias de cursos de cada plano.
        $this->plancoursecategories = $this->get_plan_course_categories($userid);
    }

    public function export_for_template(renderer_base $output) {
        $this->set_user_data($output);
        $this->set_plan_categories($output);

        return $this->get_exported_data($output);
    }

    /**
     * Retorna se há conteúdo na lista de planos.
     *
     * @return boolean
     */
    public function has_content() {
        return !empty($this->plans);
    }

    private function set_user_data(renderer_base $output) {
        $user = $this->user;

        $user->picture = $output->user_picture($user, array('visibletoscreenreaders' => false));
        $user->profileurl = (
            new \moodle_url('/user/view.php', array('id' => $user->id))
        )->out(false);
        $user->fullname = fullname($user);

        $this->user = $user;
    }

    private function set_plan_categories(renderer_base $output) {
        $plancategories = array();

        foreach ($this->plans as $plan) {
            $exportedplan = (new plan_exporter($plan, array('template' => $plan->get_template())))->export($output);
            $plancategory = $this->plancoursecategories[$exportedplan->id];
            $plancategoryid = $plancategory->categoryid;

            if (!isset($plancategory)) {
                continue;
            } else if (!isset($plancategories[$plancategoryid])) {
                $plancategories[$plancategoryid] = $this->create_plan_category_object($plancategory);
            }

            if (
                $plancategories[$plancategoryid]->categorycomplete === 'categorycomplete'
                && $exportedplan->coursecomplete == 0
            ) {
                $plancategories[$plancategoryid]->categorycomplete = 'categoryincomplete';
            }

            if (isset($plancategory->courseid)) {
                $coursecompetenciespage = new \tool_lp\output\course_competencies_page($plancategory->courseid);
                $exportedplan->coursecompetencies = $coursecompetenciespage->export_for_template($output);
                $exportedplan->planindexincategory = $plancategory->courseindexincategory;
                $exportedplan->category3name = $plancategory->category3name;

                $exportedplan->coursemodules = $this->get_course_competency_activities($exportedplan);
            }

            $plancategories[$plancategoryid]->plans[] = $exportedplan;
        }

        $this->plancategories = $plancategories;
    }

    private function get_plan_course_categories() {
        global $DB;

        return $DB->get_records_sql("
            select p.id planid,
                c.id courseid,
                c.sortorder coursesortorder,
                cc.id categoryid,
                cc.name categoryname,
                cc.sortorder categorysortorder,
                cc3.name category3name,
                (
                    select COUNT(1)
                    from {course} c2
                    where c2.category = c.category
                        and c2.fullname not like '%Ambiente de capacitação%'
                        and c2.sortorder <= c.sortorder
                ) courseindexincategory,
                MIN(ucc.proficiency) coursecomplete
            from {competency_plan} p
                join {competency_template} t on t.id = p.templateid
                join {competency_templatecomp} tc on tc.templateid = t.id
                join {competency_usercompcourse} ucc on ucc.competencyid = tc.competencyid
                    and ucc.userid = p.userid
                join {course} c on c.id = ucc.courseid
                join {course_categories} cc on cc.id = c.category
                    and cc.name like '%[%E%-%E%]%'
                join {course_categories} cc2 on cc2.id = cc.parent
                    and cc2.name like '%[%-%-%]%'
                join {course_categories} cc3 on cc3.id = cc2.parent
            where cc3.id = (
                select cc3_latest.id
                from {course} c_latest
                    join {course_categories} cc_latest on cc_latest.id = c_latest.category
                        and cc_latest.name like '%[%E%-%E%]%'
                    join {course_categories} cc2_latest on cc2_latest.id = cc_latest.parent
                        and cc2_latest.name like '%[%-%-%]%'
                    join {course_categories} cc3_latest on cc3_latest.id = cc2_latest.parent
                    join {context} cx_c on c_latest.id = cx_c.instanceid
                        and cx_c.contextlevel = '50'
                    join {role_assignments} ra on ra.contextid = cx_c.id
                    join {role} r on r.id = ra.roleid
                        and r.archetype = 'student'
                where ra.userid = ucc.userid
                order by ra.timemodified desc
                limit 1
            )
                and p.userid = ?
            group by p.id
        ", array($this->user->id));
    }

    private function create_plan_category_object($plancategoryrecord) {
        $category = new \stdClass();
        $category->categoryid = $plancategoryrecord->categoryid;
        $category->categoryname = $plancategoryrecord->categoryname;
        $category->categoryorder = $plancategoryrecord->categorysortorder;
        $category->categorycomplete = 'categorycomplete';
        $category->plans = array();

        return $category;
    }

    private function get_course_competency_activities($plan) {
        $coursemodules = array();

        foreach ($plan->coursecompetencies->competencies as $competency) {
            $coursemodules = array_map(
                "unserialize",
                array_unique(
                    array_map(
                        "serialize",
                        array_merge(
                            $coursemodules,
                            $competency['coursemodules']
                        )
                    )
                )
            );
        }

        end($coursemodules)->lastitem = true;

        return $coursemodules;
    }

    private function get_exported_data() {
        $sortedcategories = array_values($this->plancategories);
        usort($sortedcategories, array($this, "compare_categories_order"));

        foreach ($sortedcategories as $category) {
            $category->categorycompletestring = get_string($category->categorycomplete, 'block_lp_coursecategories');

            usort($category->plans, array($this, "compare_courses_order"));

            foreach($category->plans as $plan) {
                usort($plan->coursecompetencies->competencies, array($this, "compare_competencies_idnumber"));
            }
        }

        return array(
            'hasplans' => !empty($this->plans),
            'plancategories' => $sortedcategories,
            'category3' => $sortedcategories[0]->plans[0]->category3name,
            'user' => $this->user,
            'fullreporturl' => new \moodle_url('/blocks/lp_coursecategories/full_report.php'),
            'lpbaseurl' => new \moodle_url('/admin/tool/lp/')
        );
    }

    private function compare_categories_order($category1, $category2) {
        if ($category1->categoryorder === $category2->categoryorder) {
            return 0;
        }
        return ($category1->categoryorder < $category2->categoryorder) ? -1 : 1;
    }

    private function compare_courses_order($course1, $course2) {
        if ($course1->planindexincategory === $course2->planindexincategory) {
            return 0;
        }
        return ($course1->planindexincategory < $course2->planindexincategory) ? -1 : 1;
    }

    private function compare_competencies_idnumber($competency1, $competency2) {
        if ($competency1['competency']->idnumber === $competency2['competency']->idnumber) {
            return 0;
        }
        return ($competency1['competency']->idnumber < $competency2['competency']->idnumber) ? -1 : 1;
    }
}
