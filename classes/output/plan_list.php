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
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/mod/attendance/classes/summary.php');

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
    public function __construct($user = null) {
        global $USER;
        if (!$user) {
            $user = $USER;
        }
        $this->user = $user;

        $userid = $this->user->id;

        // Obter os planos de aprendizado.
        $this->plans = api::list_user_plans($userid);

        // Obter as categorias de cursos de cada plano.
        $this->plancoursecategories = $this->get_plan_course_categories($userid);
    }

    public function export_for_template(renderer_base $output) {
        $this->set_user_data($output);
        $this->set_plan_data($output);

        global $USER;
        if ($this->user !== $USER) {
            $this->set_user_course_competencies($output);
        }

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

    private function set_plan_data(renderer_base $output) {
        $this->plancategories = array();

        foreach ($this->plans as $plan) {
            $exportedplan = (new plan_exporter($plan, array('template' => $plan->get_template())))->export($output);

            if (!isset($this->plancoursecategories[$exportedplan->id])) {
                continue;
            }

            $exportedplan = $this->set_plan_category($exportedplan, $output);
            $exportedplan = $this->set_attendance_data($exportedplan);
            $exportedplan = $this->set_completed($exportedplan);

            $this->plancategories[$exportedplan->plancategoryid]->plans[] = $exportedplan;
        }
    }

    private function set_plan_category($exportedplan, renderer_base $output) {
        $plancategory = $this->plancoursecategories[$exportedplan->id];
        $plancategoryid = $plancategory->categoryid;
        $exportedplan->plancategoryid = $plancategoryid;

        if (!isset($this->plancategories[$plancategoryid])) {
            $this->plancategories[$plancategoryid] = $this->create_plan_category_object($plancategory);
        }

        if (isset($plancategory->courseid)) {
            $coursecompetenciespage = new \tool_lp\output\course_competencies_page($plancategory->courseid);
            $exportedplan->coursecompetencies = $coursecompetenciespage->export_for_template($output);

            $exportedplan->planindexincategory = $plancategory->courseindexincategory;
            $exportedplan->category3name = $plancategory->category3name;

            $exportedplan->coursemodules = $this->get_course_competency_activities($exportedplan);
        }

        return $exportedplan;
    }

    private function set_attendance_data($exportedplan) {
        $plancourse = $this->plancoursecategories[$exportedplan->id];

        if (isset($plancourse->attendanceid)) {
            $attendanceid = $plancourse->attendanceid;
            $exportedplan->attendancecmid = $plancourse->cmid;
            $attendancesummary = new \mod_attendance_summary($attendanceid);
        }

        if (isset($attendancesummary) && !empty($attendancesummary->get_user_taken_sessions_percentages())) {
            $allsessionssummary = $attendancesummary->get_all_sessions_summary_for($this->user->id);
            $exportedplan->attendance = $this->get_attendance_percentage($allsessionssummary);
            $exportedplan->attendanceformatted = sprintf("%.1f%%", $exportedplan->attendance * 100);
        }

        return $exportedplan;
    }

    private function set_completed($exportedplan) {
        $plancategory = $this->plancoursecategories[$exportedplan->id];
        $plancategoryid = $exportedplan->plancategoryid;
        $competenciesok = $plancategory->competenciesok;

        $exportedplan->competenciescompletedstring = get_string('competencies_completed_' . $competenciesok, 'block_lp_coursecategories');
        $exportedplan->competenciescompletedclass = ($competenciesok) ? 'D' : 'ND';

        $attendanceidentifier = 'course_attendance_';
        if (isset($exportedplan->attendancecmid)) {
            if ($exportedplan->attendance >= 0.75) {
                $attendanceidentifier .= 'ok';
                $exportedplan->attendanceclass = 'D';
            } else {
                $attendanceidentifier .= 'insufficient';
                $exportedplan->attendanceclass = 'ND';
            }
        } else {
            $attendanceidentifier .= 'no_data';
        }

        $exportedplan->attendancestring = get_string($attendanceidentifier, 'block_lp_coursecategories');

        if ($plancategory->competenciesok == 0) {
            $this->plancategories[$plancategoryid]->categorycomplete = 'categoryincomplete';
            $this->plancategories[$plancategoryid]->categorycompleteclass = 'ND';
        }

        if ($this->plancategories[$plancategoryid]->categorycomplete === 'categorycomplete') {
            if (!isset($exportedplan->attendancecmid)) {
            $this->plancategories[$plancategoryid]->categorycomplete = $attendanceidentifier;
            $this->plancategories[$plancategoryid]->categorycompleteclass = '';
        } else if ($exportedplan->attendance < 0.75) {
                $this->plancategories[$plancategoryid]->categorycomplete = 'categoryincomplete';
                $this->plancategories[$plancategoryid]->categorycompleteclass = 'ND';
            }
        }

        return $exportedplan;
    }

    private function get_attendance_percentage($allsessionssummary) {
        $numallsessions = $allsessionssummary->numallsessions;
        $sessionsbyacronym = array_pop($allsessionssummary->userstakensessionsbyacronym);

        $absentsessions = 0;
        $latesessions = 0;

        if (isset($sessionsbyacronym['Au'])) {
            $absentsessions = $sessionsbyacronym['Au'];
        }

        if (isset($sessionsbyacronym['At'])) {
            $latesessions = $sessionsbyacronym['At'];
        }

        return ($numallsessions - $absentsessions - floor($latesessions / 2)) / $numallsessions;
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
                MIN(COALESCE(ucc.proficiency, 0)) competenciesok,
                cm.id cmid,
                a.id attendanceid
            from {competency_plan} p
                join {competency_template} t on t.id = p.templateid
                join {competency_templatecomp} tc on tc.templateid = t.id
                join {competency_coursecomp} ccc on ccc.competencyid = tc.competencyid
                join {course} c on c.id = ccc.courseid
                join {context} cx on cx.instanceid = c.id
                    and cx.contextlevel = '50'
                join {role_assignments} ra on ra.contextid = cx.id
                    and ra.userid = p.userid
                join {course_categories} cc on cc.id = c.category
                    and cc.name like '%[%E%-%E%]%'
                join {course_categories} cc2 on cc2.id = cc.parent
                    and cc2.name like '%[%-%-%]%'
                join {course_categories} cc3 on cc3.id = cc2.parent
                left join {competency_usercompcourse} ucc on ucc.competencyid = tc.competencyid
                    and ucc.userid = p.userid
                    and ucc.courseid = ccc.courseid
                left join (
                    {course_modules} cm
                    join {modules} m on m.id = cm.module
                        and m.name = 'attendance'
                    join {attendance} a on a.id = cm.instance
                ) on cm.course = c.id
                    and cm.visible = 1
            where cc3.id = (
                select cc3_latest.id
                from {course} c_latest
                    join {course_categories} cc_latest on cc_latest.id = c_latest.category
                        and cc_latest.name like '%[%E%-%E%]%'
                    join {course_categories} cc2_latest on cc2_latest.id = cc_latest.parent
                        and cc2_latest.name like '%[%-%-%]%'
                    join {course_categories} cc3_latest on cc3_latest.id = cc2_latest.parent
                    join {context} cx_latest on cx_latest.instanceid = c_latest.id
                        and cx_latest.contextlevel = '50'
                    join {role_assignments} ra_latest on ra_latest.contextid = cx_latest.id
                where ra_latest.userid = p.userid
                order by ra_latest.timemodified desc
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
        $category->categorycompleteclass = 'D';
        $category->plans = array();

        $category->attendanceid = $plancategoryrecord->attendanceid;

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

        if (!empty($coursemodules)) {
            end($coursemodules)->lastitem = true;
        }

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

        global $USER;

        return array(
            'hasplans' => !empty($this->plans),
            'plancategories' => $sortedcategories,
            'category3' => $sortedcategories[0]->plans[0]->category3name,
            'user' => $this->user,
            'showstatistics' => $this->user->id === $USER->id,
            'fullreporturl' => new \moodle_url('/blocks/lp_coursecategories/full_report.php', ['userid' => $this->user->id]),
            'lpbaseurl' => new \moodle_url('/admin/tool/lp/')
        );
    }

    private function set_user_course_competencies(renderer_base $output) {
        foreach ($this->plancategories as $plancatkey => $plancategories) {
            foreach ($plancategories->plans as $plankey => $plan) {
                foreach ($plan->coursecompetencies->competencies as $compkey => $competency) {
                    $competencyreport = new \report_competency\output\report($competency['coursecompetency']->courseid, $this->user->id);
                    $exportedusercompetencycourse = $competencyreport->export_for_template($output);

                    foreach ($exportedusercompetencycourse->usercompetencies as $usercompetency) {
                        if ($usercompetency->usercompetencycourse->competencyid === $competency['competency']->id) {
                            $this->plancategories[$plancatkey]->plans[$plankey]->coursecompetencies->competencies[$compkey]['usercompetencycourse'] = $usercompetency->usercompetencycourse;
                            $this->plancategories[$plancatkey]->plans[$plankey]->coursecompetencies->competencies[$compkey]['gradableuserid'] = $this->user->id;

                            break;
                        }
                    }
                }
            }
        }
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
