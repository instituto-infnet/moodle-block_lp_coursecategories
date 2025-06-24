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
class plan_list_test implements renderable, templatable {

    /**
     * The period after an assessment's due date, if still ungraded,
     * after which the student will be marked as failed for that course component.
     * Use a string compatible with strtotime(), e.g., '2 months', '60 days'.
     * @var string
     */
    protected $gradingTimeoutPeriodInactivity = '2 months';

    // Constants for grading status
    const GRADING_STATUS_NONE = 'none'; 
    const GRADING_STATUS_PENDING_WITHIN_TIMEOUT = 'pending_within_timeout'; 
    const GRADING_STATUS_PENDING_EXCEEDED_TIMEOUT = 'pending_exceeded_timeout'; 

    /** @var array Resultado da consulta ao banco com dados dos planos. */
    protected $plansqueryresult = array();
    /** @var array Resultado da consulta ao banco com dados dos planos dos cursos de extensão. */
    protected $extensionplansqueryresult = array();
    protected $electiveplansqueryresult = array();
    
    protected $reassessmentplansqueryresult = array();
    /** @var array Categorias dos cursos dos planos. */
    protected $plancategories = array();
    /** @var stdClass O usuário. */
    protected $user;

    protected $yearLimit = 2024;

    /**
     * Construtor.
     */
    public function __construct($user = null) {
        global $USER;
        if (!$user) {
            $user = $USER;
        }
        profile_load_data($user);
        $this->user = $user;

        // Obter as categorias de cursos de cada plano.
        $this->plansqueryresult = $this->get_plan_course_categories($user->id);
          
        // Obter as categorias de cursos de cada plano.
        $this->extensionplansqueryresult = $this->get_plan_extension_course_categories();
        
        // Obter as categorias de cursos eletivos de cada plano.
        $this->electiveplansqueryresult = $this->get_plan_elective_course_categories();        
        
        // Obter as categorias de cursos de reavaliação de cada plano.
        $this->reassessmentplansqueryresult = $this->get_plan_reassessment_course_categories();        
    }

    public function export_for_template(renderer_base $output) {
        $this->set_user_data($output);
        $this->set_plans_data($output);
        $this->set_user_course_competencies($output);

        return $this->get_exported_data($output);
    }

    /**
     * Retorna se há conteúdo na lista de planos.
     *
     * @return boolean
     */
    public function has_content() {
        return !empty($this->plansqueryresult);
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

    private function set_plans_data(renderer_base $output) {
        // Reinicialização da variável porque export_for_template é chamado duas vezes
        $this->plancategories = array();

        foreach ($this->plansqueryresult as $courseplan) {
            $courseplan = $this->set_plan_category($courseplan, $output);

            if ($this->plancategories[$courseplan->category2id]->distance === false) {
                $courseplan = $this->set_attendance_data($courseplan);
            }

            $courseplan = $this->set_completed($courseplan);

            $this->plancategories[$courseplan->category2id]->categories[$courseplan->categoryid]->plans[] = $courseplan;
        }
    }

    private function set_plan_category($courseplan, renderer_base $output) {
        $categoryid = $courseplan->categoryid;
        $category2id = $courseplan->category2id;

        if (!isset($this->plancategories[$category2id])) {
            $this->plancategories[$category2id] = $this->create_plan_category2_object($courseplan);
        }

        if (!isset($this->plancategories[$category2id]->categories[$categoryid])) {
            $this->plancategories[$category2id]->categories[$categoryid] = $this->create_plan_category_object($courseplan);
        }

        if (isset($courseplan->courseid)) {
            if ($courseplan->visible == 1) {
                $coursecompetenciespage = new \tool_lp\output\course_competencies_page($courseplan->courseid, 0);
                $courseplan->coursecompetencies = $coursecompetenciespage->export_for_template($output);
                $courseplan->coursemodules = $this->get_course_competency_activities($courseplan);
            }

            $courseplan->planindexincategory = $courseplan->courseindexincategory;
            $courseplan->distance = $this->plancategories[$category2id]->distance;
        }

        return $courseplan;
    }

    private function set_attendance_data($courseplan) {
        if (isset($courseplan->attendanceid)) {
            $attendanceid = $courseplan->attendanceid;
            $courseplan->attendancecmid = $courseplan->attendancecmid;
            $attendancesummary = new \mod_attendance_summary($attendanceid);
        }

        if (isset($attendancesummary)) {
            $allsessionssummary = $attendancesummary->get_all_sessions_summary_for($this->user->id);
            $courseplan->attendance = $this->get_attendance_percentage($allsessionssummary);
            $courseplan->attendanceformatted = number_format($courseplan->attendance * 100, 1, ',', null) . '%';
        }

        return $courseplan;
    }

    private function check_plagiarism($courseid, $userid){
        global $DB;
        
        $assign_sql = "SELECT id, course, name FROM {assign} where course = :courseid AND name like 'assessment%'";
        // Bind the parameters for the SQL query
        $params = array('courseid' => $courseid);

        // Fetch the data using get_records_sql
        $assign_data = $DB->get_records_sql($assign_sql, $params);
        $assign = array_values($assign_data);
        
        $sql = "SELECT
            u.firstname,
            u.lastname,
            ag.grade,
            grc.id AS rubric_id,
            grc.description AS rubric_description,
            gfl.remark,
            gfl.levelid AS rate_id,
            gfrl.definition AS rate_definition,
            gfrl.score AS rate_score
        FROM {assign_submission} AS asb
        JOIN {user} AS u ON u.id = asb.userid
        JOIN {assign_grades} AS ag ON ag.userid = asb.userid AND ag.assignment = asb.assignment
        JOIN {grading_instances} AS gi ON gi.itemid = ag.id
        JOIN {gradingform_rubric_fillings} AS gfl ON gfl.instanceid = gi.id
        JOIN {gradingform_rubric_criteria} AS grc ON grc.id = gfl.criterionid
        JOIN {gradingform_rubric_levels} AS gfrl ON gfrl.id = gfl.levelid
        WHERE asb.assignment = :assignmentid AND u.id = :userid AND asb.status = 'submitted' AND gi.definitionid = asb.assignment AND gi.status = 0";

        // Bind the parameters for the SQL query
        $params = array('assignmentid' => $assign[0]->id, 'userid' => $userid);
        
        // Fetch the data using get_records_sql
        $data = $DB->get_records_sql($sql, $params);
        
        return $data['Aluno'];
    }

    private function set_completed($courseplan)
    {
        $categoryid = $courseplan->categoryid;
        $category2id = $courseplan->category2id;
        $competenciesok = $courseplan->competenciesok;        
        $userid = $this->user->id;
        $courseid = $courseplan->courseid;

        /* Course competencies string */
        $courseplan->competenciescompletedstring = get_string('competencies_completed_' . $competenciesok, 'block_lp_coursecategories');

        /* Course attendance string */
        $attendanceidentifier = 'course_attendance_';
        if (
            isset($courseplan->attendancecmid)
            && isset($courseplan->attendance)
        ) {
            $attendanceidentifier .= ($courseplan->attendance >= 0.75) ? 'ok' : 'insufficient';
        } else if (isset($courseplan->legacyattendanceok)) {
            $attendanceidentifier .= ($courseplan->legacyattendanceok === '1') ? 'ok' : 'insufficient';
        } else {
            $attendanceidentifier .= 'no_data';
        }

        $courseplan->attendanceidentifier = $attendanceidentifier;
        $courseplan->attendancestring = get_string($attendanceidentifier, 'block_lp_coursecategories');

        // Determine if an assessment is pending grading
        $is_pending_assessment_grading = false;
        if (isset($courseid) && $courseplan->visible == 1 && $courseplan->ongoing == 0 && $competenciesok == 0) {            
            $is_pending_assessment_grading = $this->has_pending_assessment_grading($courseid, $userid);            
        }       

        /* Course passed string and class */
        $coursepassedidentifier = 'course_passed_';

        if (
            $courseplan->visible != 1
            || $courseplan->ongoing == 1 
        ) {
            $coursepassedidentifier .= 'ongoing';
            $courseplan->coursepassedclass = '';
            $courseplan->attendancestring .= ' ' . get_string('course_attendance_so_far', 'block_lp_coursecategories');
        } else if ($is_pending_assessment_grading) { 
            $coursepassedidentifier .= 'ongoing'; 
            $courseplan->coursepassedclass = '';            
        } else if (
            $competenciesok == 1
            && (
                $this->plancategories[$category2id]->distance === true
                || $attendanceidentifier === 'course_attendance_ok'
            )
        ) {
            $coursepassedidentifier .= 'yes';
            $courseplan->coursepassedclass = 'D';
        } else if ($competenciesok == 0) { 
            $coursepassedidentifier .= 'no_competencies';
        } else if ($this->plancategories[$category2id]->distance === false) {
            if ($attendanceidentifier === 'course_attendance_no_data') {
                $coursepassedidentifier = $attendanceidentifier; 
                $courseplan->coursepassedclass = '';
            } else if ($attendanceidentifier === 'course_attendance_insufficient') {
                $coursepassedidentifier .= 'no_attendance';
            }
        }        

        if ($attendanceidentifier !== 'course_attendance_no_data' && !isset($courseplan->legacyattendanceok)) {
            // Check if $courseplan->attendanceformatted is set before using it
            if (isset($courseplan->attendanceformatted)) {
                $courseplan->attendancestring .= ': ' . $courseplan->attendanceformatted;
            }
        }

        $courseplan->coursepassedidentifier = $coursepassedidentifier;        
        
        $courseplan->coursepassedstring = get_string($courseplan->coursepassedidentifier, 'block_lp_coursecategories');

        if (!isset($courseplan->coursepassedclass)) {
            $courseplan->coursepassedclass = 'ND'; 
        }

        /* Category complete string and class */        
        if ($this->plancategories[$category2id]->categories[$categoryid]->categorycomplete !== 'categoryincomplete') {
            if (
                $this->plancategories[$category2id]->distance === false
                && $attendanceidentifier === 'course_attendance_no_data'
                && $this->plancategories[$category2id]->categories[$categoryid]->categorycomplete !== $attendanceidentifier
            ) {
                $this->plancategories[$category2id]->categories[$categoryid]->categorycomplete = $attendanceidentifier;
                $this->plancategories[$category2id]->categories[$categoryid]->categorycompleteclass = '';
            } else if ($coursepassedidentifier !== 'course_passed_yes') {                
                $this->plancategories[$category2id]->categories[$categoryid]->categorycomplete = 'categoryincomplete';
                $this->plancategories[$category2id]->categories[$categoryid]->categorycompleteclass = 'ND';
            }
        }

        // Recupera o curso de Projeto de Bloco
        $mainBlockCourse = $this->getMainBlockCourse($categoryid);
        
        $courseYearLimit = false;
        if ($mainBlockCourse && isset($mainBlockCourse->course_start_date)) {
            $date = \DateTime::createFromFormat('d-m-Y', $mainBlockCourse->course_start_date);
            if ($date) {
                $courseYearLimit = intval($date->format('Y')) >= $this->yearLimit;
            }

            $is_pb_pending_grading = $this->has_pending_assessment_grading($mainBlockCourse->courseid, $userid); 
        }

        $isProjetoDeBloco = false;
        if (isset($courseplan->coursename) && strncmp($courseplan->coursename, 'Projeto de Bloco', 15) === 0) {
            $isProjetoDeBloco = true;
        }

        // Faz checagem se o AT do aluno está marcado como plágio
        // Ensure gradableuserid and courseid are valid before calling check_plagiarism
        $plagiarism = null;
        if (isset($courseplan->courseid) && isset($courseplan->coursecompetencies->gradableuserid)) {
             $plagiarism = $this->check_plagiarism($courseplan->courseid, $courseplan->coursecompetencies->gradableuserid);
        }
        $rubric_plagiarism = get_string('rubric_plagiarism', 'block_lp_coursecategories');

        if ($plagiarism && isset($plagiarism->rate_score) && intval($plagiarism->rate_score) === 0 && isset($plagiarism->rubric_description) && $plagiarism->rubric_description === $rubric_plagiarism) {
            $courseplan->coursepassedclass = 'ND';
            $courseplan->coursepassedidentifier = 'course_passed_plagiarism';
            $courseplan->coursepassedstring = get_string('course_passed_plagiarism', 'block_lp_coursecategories');
            $this->plancategories[$category2id]->categories[$categoryid]->categorycomplete = 'categoryincomplete';
            $this->plancategories[$category2id]->categories[$categoryid]->categorycompleteclass = 'ND';
        }        
       
        if($mainBlockCourse && $courseYearLimit === true && $isProjetoDeBloco === false) {
            if($courseplan->coursepassedidentifier === "course_passed_yes") {
                if((string)$mainBlockCourse->ongoing === '1' || $is_pb_pending_grading === true){
                    $courseplan->coursepassedidentifier = 'course_passed_ongoing_pb';
                    $courseplan->coursepassedstring = get_string($courseplan->coursepassedidentifier, 'block_lp_coursecategories');
                    $courseplan->coursepassedclass = '';
                }

                if(((string)$mainBlockCourse->ongoing === '0' && (string)$mainBlockCourse->competenciesok === '0')&&($is_pb_pending_grading === false)) {
                    $courseplan->coursepassedidentifier = 'course_fail_pb';
                    $courseplan->coursepassedstring = get_string($courseplan->coursepassedidentifier, 'block_lp_coursecategories');
                    $courseplan->coursepassedclass = 'ND';                    
                }
            }
        }

        return $courseplan;
    }
    
    private function getMainBlockCourse(string $categoryId){
        foreach ($this->plansqueryresult as $key => $item) {
            if ((string)$item->categoryid === (string)$categoryId && strncmp($item->coursename, 'Projeto de Bloco', 15) === 0) {
                return $item;
            }
        }
        return null;
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

    private function get_reassessment_external_grade($competencies) {
        $grade = 0;
        $coursepassed = true;

        $extgradescalevalues = array(
            '2' => 50,
            '3' => 75,
            '4' => 100
        );

        foreach ($competencies as $competency) {            

            if ($competency->proficiency !== '1') {
                $coursepassed = false;
            } else {
                $grade += $extgradescalevalues[$competency->grade];
            }
        }

        if(count($competencies)>0){
            $grade /= count($competencies);
        }

        if ($coursepassed === false) {
            $grade *= 0.4;
        }

        return round($grade);
    }
    
    private function get_external_grade($plan) {
        if (
            $plan->coursepassedidentifier === 'course_passed_ongoing'
            || count($plan->coursecompetencies->competencies) === 0
            || (
                $plan->distance !== true
                && $plan->attendanceidentifier !== 'course_attendance_ok'
            )
        ) {
            return get_string('notrated', 'report_competency');
        }

        $grade = 0;
        $coursepassed = true;

        $extgradescalevalues = array(
            '2' => 50,
            '3' => 75,
            '4' => 100
        );

        $competencies = $plan->coursecompetencies->competencies;

        foreach ($competencies as $competency) {
            $usercompetencycourse = $competency['usercompetencycourse'];

            if ($usercompetencycourse->proficiency !== '1') {
                $coursepassed = false;
            } else {
                $grade += $extgradescalevalues[$usercompetencycourse->grade];
            }
        }

        $grade /= count($competencies);

        if ($coursepassed === false) {
            $grade *= 0.4;
        }

        return round($grade);
    }

    private function get_plan_elective_course_categories() {
        global $DB;        
        // Apenas as eletivas com o campo customizado "Infnet/Carga horária total" retornam na consulta abaixo
        $sql = "SELECT 
                    c.id AS courseid,
                    c.shortname AS courseidnumber,
                    c.fullname AS coursename,
                    c.sortorder AS coursesortorder,
                    c.visible,
                    DATE_FORMAT(FROM_UNIXTIME(c.startdate), '%d-%m-%Y') AS course_start_date,
                    DATE_FORMAT(FROM_UNIXTIME(c.enddate), '%d-%m-%Y') AS course_end_date,
                    CONCAT(YEAR(FROM_UNIXTIME(c.startdate)), '.', 
                        CASE 
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 1 AND 3 THEN '1T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 4 AND 6 THEN '2T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 7 AND 9 THEN '3T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 10 AND 12 THEN '4T'
                        END) AS Trimester,
                    c.category AS ccategory,
                    cc.id AS categoryid,
                    cc.name AS categoryname,
                    cc.sortorder AS categorysortorder,
                    cfd.value AS cargahorariatotal
                FROM 
                    mdl_course c
                JOIN 
                    mdl_context cx ON cx.instanceid = c.id
                    AND cx.contextlevel = '50'
                JOIN 
                    mdl_role_assignments ra ON ra.contextid = cx.id
                JOIN 
                    mdl_role r ON r.id = ra.roleid
                    AND r.archetype = 'student'
                JOIN 
                    mdl_course_categories cc ON cc.id = c.category
                JOIN 
                    mdl_course_categories cc2 ON cc2.id = cc.parent                
                JOIN 
                    mdl_course_categories cc3 ON cc3.id = cc2.parent
                    AND cc3.name = 'Eletivas'
                JOIN 
                    mdl_customfield_data cfd ON cfd.instanceid = c.id
                JOIN 
                    mdl_customfield_field cff ON cff.id = cfd.fieldid
                WHERE 
                    ra.userid = ?
                    AND c.fullname NOT LIKE '%Projeto de Bloco I %'
                    AND cff.name = 'Carga horária total'
                GROUP BY 
                    c.id, cfd.value;
        ";
        
        return($DB->get_records_sql($sql, array($this->user->id)));
    }    
    
    private function get_plan_reassessment_course_categories() {
        global $DB;        
        
        $sql = "SELECT 
                    c.id AS courseid,
                    c.shortname AS courseidnumber,
                    c.fullname AS coursename,
                    c.sortorder AS coursesortorder,
                    c.visible,
                    DATE_FORMAT(FROM_UNIXTIME(c.startdate), '%d-%m-%Y') AS course_start_date,
                    DATE_FORMAT(FROM_UNIXTIME(c.enddate), '%d-%m-%Y') AS course_end_date,
                    CONCAT(YEAR(FROM_UNIXTIME(c.startdate)), '.', 
                        CASE 
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 1 AND 3 THEN '1T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 4 AND 6 THEN '2T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 7 AND 9 THEN '3T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 10 AND 12 THEN '4T'
                        END) AS Trimester,
                    cc.id AS categoryid,
                    cc.name AS categoryname,
                    cc.sortorder AS categorysortorder,
                    cc2.id AS category2id,
                    cc2.name AS category2name,
                    cc2.description AS category2description                    
                FROM 
                    mdl_course c
                JOIN 
                    mdl_context cx ON cx.instanceid = c.id
                    AND cx.contextlevel = '50'
                JOIN 
                    mdl_role_assignments ra ON ra.contextid = cx.id
                JOIN 
                    mdl_role r ON r.id = ra.roleid
                    AND r.archetype = 'student'
                JOIN 
                    mdl_course_categories cc ON cc.id = c.category
                JOIN 
                    mdl_course_categories cc2 ON cc2.id = cc.parent
                    AND cc2.name LIKE 'Reavaliação de Disciplinas%'                    
                WHERE 
                    ra.userid = ?
                    AND c.fullname NOT LIKE '%Projeto de Bloco I %'
                GROUP BY 
                    c.id;
        ";
        
        return($DB->get_records_sql($sql, array($this->user->id)));
    }    
    
    private function get_plan_extension_course_categories() {
        global $DB;        
        
        $sql = "SELECT 
                    c.id AS courseid,
                    c.shortname AS courseidnumber,
                    c.fullname AS coursename,
                    c.sortorder AS coursesortorder,
                    c.visible,
                    DATE_FORMAT(FROM_UNIXTIME(c.startdate), '%d-%m-%Y') AS course_start_date,
                    DATE_FORMAT(FROM_UNIXTIME(c.enddate), '%d-%m-%Y') AS course_end_date,
                    CONCAT(YEAR(FROM_UNIXTIME(c.startdate)), '.', 
                        CASE 
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 1 AND 3 THEN '1T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 4 AND 6 THEN '2T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 7 AND 9 THEN '3T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 10 AND 12 THEN '4T'
                        END) AS Trimester,
                    cc.id AS categoryid,
                    cc.name AS categoryname,
                    cc.sortorder AS categorysortorder,
                    cc2.id AS category2id,
                    cc2.name AS category2name,
                    cc2.description AS category2description,
                    cc3.id AS category3id,
                    cc3.name AS category3name,
                    cc4.id AS category4id,
                    cc4.name AS category4name    
                FROM 
                    mdl_course c
                JOIN 
                    mdl_context cx ON cx.instanceid = c.id
                    AND cx.contextlevel = '50'
                JOIN 
                    mdl_role_assignments ra ON ra.contextid = cx.id
                JOIN 
                    mdl_role r ON r.id = ra.roleid
                    AND r.archetype = 'student'
                JOIN 
                    mdl_course_categories cc ON cc.id = c.category
                JOIN 
                    mdl_course_categories cc2 ON cc2.id = cc.parent
                    AND cc2.name LIKE '[EX%'
                JOIN 
                    mdl_course_categories cc3 ON cc3.id = cc2.parent
                JOIN 
                    mdl_course_categories cc4 ON cc4.id = cc3.parent      
                WHERE 
                    ra.userid = ?
                    AND c.fullname NOT LIKE '%Projeto de Bloco I %'
                GROUP BY 
                    c.id;
        ";
        
        return($DB->get_records_sql($sql, array($this->user->id)));
    }    

    private function get_plan_course_categories() {
        global $DB;

        return $DB->get_records_sql("
            select c.id courseid,
                c.shortname courseidnumber,
                c.fullname coursename,
                c.sortorder coursesortorder,
                c.visible,
                DATE_FORMAT(FROM_UNIXTIME(c.startdate), '%d-%m-%Y') AS course_start_date,
                DATE_FORMAT(FROM_UNIXTIME(c.enddate), '%d-%m-%Y') AS course_end_date,
                CONCAT(YEAR(FROM_UNIXTIME(c.startdate)), '.', 
                        CASE 
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 1 AND 3 THEN '1T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 4 AND 6 THEN '2T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 7 AND 9 THEN '3T'
                            WHEN MONTH(FROM_UNIXTIME(c.startdate)) BETWEEN 10 AND 12 THEN '4T'
                        END) AS Trimester,
                cc.id categoryid,
                cc.name categoryname,
                cc.sortorder categorysortorder,
                cc2.id category2id,
                cc2.name category2name,
                cc3.id category3id,
                cc3.name category3name,
                cc4.id category4id,
                cc4.name category4name,
                (
                    select COUNT(1)
                    from {course} c2
                    where c2.category = c.category
                        and c2.fullname not like '%Ambiente de capacitação%'
                        and c2.sortorder <= c.sortorder
                ) courseindexincategory,
                MIN(COALESCE(ucc.proficiency, 0)) competenciesok,
                acga.approved legacyattendanceok,
                cmatt.id attendancecmid,
                att.id attendanceid,
                case
                    when MIN(ucc.grade) is null
                        and GREATEST(MAX(COALESCE(agn.cutoffdate,0)), MAX(COALESCE(q.timeclose,0))) + 10 > UNIX_TIMESTAMP()
                    then 1
                    else 0
                end ongoing
            from {course} c
                join {context} cx on cx.instanceid = c.id
                    and cx.contextlevel = '50'
                join {role_assignments} ra on ra.contextid = cx.id
                join {role} r on r.id = ra.roleid
                    and r.archetype = 'student'
                join {course_categories} cc on cc.id = c.category
                join {course_categories} cc2 on cc2.id = cc.parent
                    and (
                        cc2.name like '[GR%'
                        or cc2.name like '[PG%'
                    )
                join {course_categories} cc3 on cc3.id = cc2.parent
                join {course_categories} cc4 on cc4.id = cc3.parent
                left join {competency_coursecomp} ccc on ccc.courseid = c.id
                left join {competency_usercompcourse} ucc on ucc.competencyid = ccc.competencyid
                    and ucc.userid = ra.userid
                    and ucc.courseid = c.id
                left join {local_autocompgrade_attend} acga on acga.courseid = c.id
                    and acga.userid = ra.userid
                left join (
                    {course_modules} cmatt
                        join {modules} matt on matt.id = cmatt.module
                            and matt.name = 'attendance'
                        join {attendance} att on att.id = cmatt.instance
                ) on cmatt.course = c.id
                    and cmatt.visible = 1
                    and acga.id is null
                left join (
                    {course_modules} cmagn
                        join {modules} magn on magn.id = cmagn.module
                            and magn.name = 'assign'
                        join {assign} agn on agn.id = cmagn.instance
                            and (
                                agn.name like '%assessment%'
                                or agn.name like '%apresentação%'
                                or agn.name like '%entrega%'
                            )
                ) on cmagn.course = c.id
                left join (
                    {course_modules} cmq
                        join {modules} mq on mq.id = cmq.module
                            and mq.name = 'quiz'
                        join {quiz} q on q.id = cmq.instance
                            and q.name like '%assessment%'
                ) on cmq.course = c.id
            where ra.userid = ?
                and c.fullname not like '%Projeto de Bloco I %'
            group by c.id
            ;
        ", array($this->user->id));
    }

    private function create_plan_category2_object($plancategoryrecord) {
        $category2 = new \stdClass();

        $category2->categoryid = $plancategoryrecord->category2id;
        $category2->categoryname = $plancategoryrecord->category2name;
        $category2->category3name = $plancategoryrecord->category3name;
        $category2->category4name = $plancategoryrecord->category4name;
        $category2->distance = (preg_match('/\[GRL/', $category2->categoryname) === 1)
            || (preg_match('/\[PGL/', $category2->categoryname) === 1);

        $category2->categories = array();

        return $category2;
    }

    private function create_plan_category_object($plancategoryrecord) {
        $category = new \stdClass();

        $category->categoryid = $plancategoryrecord->categoryid;
        $category->categoryname = $plancategoryrecord->categoryname;
        $category->category2name = $plancategoryrecord->category2name;
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
    
    private function get_course_grades($coursesdata){
        global $DB;        
        
        foreach($coursesdata as $course){
            $sql = "SELECT 
                        gi.courseid,
                        gi.itemname,
                        gi.itemtype,
                        gg.itemid,
                        gg.userid,
                        gg.finalgrade
                        FROM `mdl_grade_items` gi 
                        LEFT JOIN `mdl_grade_grades` gg ON gi.id = gg.itemid 
                        WHERE (gi.courseid = ? AND gi.itemtype = 'course') 
                        AND gg.userid = ?; 
            ";
            $result = reset($DB->get_records_sql($sql, array($course->courseid,$this->user->id)));
            
            // Define e acrescenta o status do curso 
            $currentDate = new \DateTime();            
            $start_date = \DateTime::createFromFormat('d-m-Y', $course->course_start_date);
            $end_date = \DateTime::createFromFormat('d-m-Y', $course->course_end_date);
            
            if ($currentDate >= $start_date && $currentDate <= $end_date) {
                $status = "Cursando";
                $statusbadge = "blue";
            } elseif ($currentDate < $start_date) {
                $status = "Não iniciado";
                $statusbadge = "blue";
            } else {
                $status = "Encerrado";
                $statusbadge = "green";
            }
            $course->status = $status;
            $course->statusbadge = $statusbadge;

            // Acrescenta o total de horas lançado do curso            
            $course->finalgrade = $result->finalgrade ? intval(floatval($result->finalgrade)) . 'h': '-';
        }
        
        return $coursesdata;
    }
    
    private function get_elective_course_grades($coursesdata){
        global $DB;        
        
        foreach($coursesdata as $course){
            $sql = "SELECT 
                        gi.courseid,
                        gi.itemname,
                        gi.itemtype,
                        gg.itemid,
                        gg.userid,
                        gg.finalgrade,
                        cfd.value AS cargahorariatotal
                    FROM mdl_grade_items gi
                    LEFT JOIN mdl_grade_grades gg ON gi.id = gg.itemid
                    LEFT JOIN mdl_customfield_data cfd ON cfd.instanceid = gi.courseid
                    LEFT JOIN mdl_customfield_field cff ON cff.id = cfd.fieldid
                    WHERE gi.courseid = ?
                    AND gi.itemmodule = 'attendance'
                    AND gg.userid = ?
                    AND cff.name = 'Carga horária total'; 
            ";
            $result = reset($DB->get_records_sql($sql, array($course->courseid,$this->user->id)));
            
            // Define e acrescenta o status do curso 
            $currentDate = new \DateTime();            
            $start_date = \DateTime::createFromFormat('d-m-Y', $course->course_start_date);
            $end_date = \DateTime::createFromFormat('d-m-Y', $course->course_end_date);
            
            // Acrescenta o total de horas lançado do curso            
            $course->finalgrade = $result->finalgrade ? intval(floatval($result->finalgrade)) : '-';

            $course->cargaHorariaTotal = $result->cargahorariatotal ? intval(floatval($result->cargahorariatotal)) : '-';

            if ($currentDate >= $start_date && $currentDate <= $end_date) {
                $status = "Cursando";
                $statusbadge = "blue";
            } elseif ($currentDate < $start_date) {
                $status = "Não iniciado";
                $statusbadge = "blue";
            } elseif ($course->finalgrade >= 75) {
                $status = "Aprovado";
                $statusbadge = "green";
                $course->finalgrade = $course->cargaHorariaTotal;
            } else {
                $status = "Não aprovado";
                $statusbadge = "red";
            }
            $course->status = $status;
            $course->statusbadge = $statusbadge;           
        }
        
        return $coursesdata;
    }
    
    private function sum_extension_hours($coursesdata){         
        $total = 0;        
        foreach($coursesdata as $course){ 
            if($course->statusbadge === 'green')                      
                $total += $course->finalgrade !== '-'? $course->finalgrade: 0;
        }        
        return $total;
    }

    private function get_exported_data($output) {
        $sortedcategories = array();
        foreach ($this->plancategories as $plancategory) {
            // Removido para não separar os blocos por classe,
            // mantido para o caso de ser necessário voltar
            // $sortedcategories = array_values($plancategory->categories);
            // usort($sortedcategories, array($this, "compare_categories_order"));

            foreach ($plancategory->categories as $category) {
                $category->categorycompletestring = get_string($category->categorycomplete, 'block_lp_coursecategories');

                usort($category->plans, array($this, "compare_courses_order"));

                foreach($category->plans as $plan) {
                    if (
                        isset($plan->coursecompetencies)
                        && count($plan->coursecompetencies->competencies) > 0
                    ) {
                        usort($plan->coursecompetencies->competencies, array($this, "compare_competencies_idnumber"));
                    }
                }

                $sortedcategories[] = $category;
            }

            // Removido para não separar os blocos por classe,
            // mantido para o caso de ser necessário voltar
            // $plancategory->categories = $sortedcategories;
            // $sortedcategories2[] = $plancategory;
        }

        usort($sortedcategories, array($this, "compare_categories_order"));

        global $USER;

        // Extension Course
        $extension_plans = array_values($this->extensionplansqueryresult);
        $extension_plans_final = $this->get_course_grades($extension_plans);
        $extension_total_hours = $this->sum_extension_hours($extension_plans_final);
        
        // Elective Course
        $elective_plans = array_values($this->electiveplansqueryresult);        
        $elective_plans_final = $this->get_elective_course_grades($elective_plans);
        $elective_total_hours = $this->sum_extension_hours($elective_plans_final);        
        
        // Reassessment Course (reavaliação)
        $reassessment_plans = array_values($this->reassessmentplansqueryresult);        
        $reassessment_plans_final = $this->get_reassessment_course_grades($reassessment_plans); 
        
        $rootUrl = "https://lms.infnet.edu.br/moodle/mod/assign/view.php?id=";
        foreach ($reassessment_plans_final as $reassessment_plan) {            
            $reassessment_plan->assessment_assign = $this->get_assessment_assignment_data($reassessment_plan->courseid);
            $reassessment_plan->assessment_assign->url = $rootUrl . $reassessment_plan->assessment_assign->cmid;
            $reassessment_plan->externalgrade = $this->get_reassessment_external_grade($reassessment_plan->currentgrades);
        }    
        // var_dump($reassessment_plans_final);exit();
        
        return array(
            'hasplans' => !empty($this->plansqueryresult),
            'hasextensionplans' => !empty($extension_plans),            
            'extensionplans' => $extension_plans_final,
            'haselectiveplans' => !empty($elective_plans),            
            'electiveplans' => $elective_plans_final,
            'hasreassessmentplans' => !empty($reassessment_plans),            
            'reassessment_plans_final' => $reassessment_plans_final,
            'description' => $extension_plans_final[0]->category2description,
            'extensiontotalhours' => $extension_total_hours,
            'electivetotalhours' => $elective_total_hours,
            'plancategories' => $sortedcategories,
            'user' => $this->user,
            'cpf' => $this->format_cpf($this->user->profile_field_matricula),
            'category2name' => $plancategory->categoryname,
            'category3name' => $plancategory->category3name,
            'category4name' => $plancategory->category4name,
            'showstatistics' => $this->user->id === $USER->id,
            'fullreporturl' => new \moodle_url('/blocks/lp_coursecategories/full_report.php', ['userid' => $this->user->id]),
            'lpbaseurl' => new \moodle_url('/admin/tool/lp/')
        );
    }

    /**
     * Fetches the cmid, name, and duedate of the main assessment assignment for a course.
     *
     * @param int $courseid The course ID.
     * @return stdClass|false An object containing cmid, assignname, and duedate, or false if not found.
     */
    private function get_assessment_assignment_data($courseid) {
        global $DB;
        
        if (empty($assessment_assign)) {
            $assessment_assign = $DB->get_record_sql("
                SELECT cm.id AS cmid, a.name AS assignname, a.duedate AS duedate
                FROM {course_modules} cm
                JOIN {modules} m ON cm.module = m.id
                JOIN {assign} a ON cm.instance = a.id
                WHERE cm.course = ?
                AND m.name = 'assign'
                AND cm.visible = 1
                AND (a.name LIKE '%Assessment%' OR a.name LIKE '%Entrega de Projeto%')
                ORDER BY a.duedate ASC
                LIMIT 1
            ", [$courseid]);
        }

        return $assessment_assign;
    }

    function get_current_course_grades($courseid, $userid){
        global $DB;  
        $sql = "
                SELECT 
                    cuc.id AS recordid, 
                    cuc.proficiency as proficiency,
                    cuc.grade AS grade,
                    cuc.courseid AS courseid,
                    cuc.userid AS userid,
                    c.id AS competencyid, 
                    c.shortname AS competencyname
                FROM 
                    {competency_usercompcourse} cuc
                JOIN 
                    {competency} c ON c.id = cuc.competencyid
                WHERE 
                    cuc.courseid = :courseid
                    AND cuc.userid = :userid";

            $params = [
                'courseid' => $courseid,
                'userid' => $userid
            ];

            return $DB->get_records_sql($sql, $params);

    }

    function get_reassessment_course_grades($coursesdata) {              
        
        foreach($coursesdata as $course){            
            $currentgrades = $this->get_current_course_grades($course->courseid, $this->user->id);
            if (!empty($currentgrades) && is_array($currentgrades)) {
                $currentgrades = array_values($currentgrades);
            }
            
            // Define e acrescenta o status do curso 
            $currentDate = new \DateTime();            
            $start_date = \DateTime::createFromFormat('d-m-Y', $course->course_start_date);
            $end_date = \DateTime::createFromFormat('d-m-Y', $course->course_end_date);
            
            // Verifica se reprovou em alguma competência
            if(!empty($currentgrades)){
                $finalgrade = 1;                
                foreach($currentgrades as $grade){                    
                    if($grade->grade == '4'){
                        $grade->gradename = 'DML';
                        $grade->gradebadge = 'green';
                    } elseif($grade->grade == '3'){
                        $grade->gradename = 'DL';
                        $grade->gradebadge = 'green';
                    } elseif($grade->grade == '2'){ 
                        $grade->gradename = 'D';
                        $grade->gradebadge = 'green';
                    } elseif($grade->grade == '1'){
                        $grade->gradename = 'ND';
                        $grade->gradebadge = 'red';
                    } else {
                        $grade->gradename = '-';
                    }
                        
                    if($grade->proficiency === '0' || $grade->proficiency === null){
                        $finalgrade = 0;
                    }
                }                
                $course->finalgrade = $finalgrade;
            }    

            if ($currentDate >= $start_date && $currentDate <= $end_date) {
                $status = "Cursando";
                $statusbadge = "blue";
            } elseif ($currentDate < $start_date) {
                $status = "Não iniciado";
                $statusbadge = "blue";
            } elseif ($course->finalgrade === 1) {
                $status = "Aprovado";
                $statusbadge = "green";                
            } else {
                $status = "Reprovado por aproveitamento";
                $statusbadge = "red";
            }
            $course->status = $status;
            $course->statusbadge = $statusbadge;           
            $course->currentgrades = $currentgrades;
        }
        
        return $coursesdata;
    }    
    
    private function set_user_course_competencies(renderer_base $output) {
        foreach ($this->plancategories as $plancat2key => $plancategories2) {
            foreach ($plancategories2->categories as $plancatkey => $plancategories) {
                foreach ($plancategories->plans as $plankey => $plan) {
                    if (!isset($plan->coursecompetencies)) {
                        continue;
                    }

                    foreach ($plan->coursecompetencies->competencies as $compkey => $competency) {
                        $competencyreport = new \report_competency\output\report($competency['coursecompetency']->courseid, $this->user->id, 0);
                        $exportedusercompetencycourse = $competencyreport->export_for_template($output);

                        foreach ($exportedusercompetencycourse->usercompetencies as $usercompetency) {
                            if ($usercompetency->usercompetencycourse->competencyid === $competency['competency']->id) {
                                $this->plancategories[$plancat2key]->categories[$plancatkey]->plans[$plankey]->coursecompetencies->competencies[$compkey]['usercompetencycourse'] = $usercompetency->usercompetencycourse;
                                $this->plancategories[$plancat2key]->categories[$plancatkey]->plans[$plankey]->coursecompetencies->competencies[$compkey]['gradableuserid'] = $this->user->id;

                                break;
                            }
                        }
                    }

                    $this->plancategories[$plancat2key]->categories[$plancatkey]->plans[$plankey]->externalgrade = $this->get_external_grade($this->plancategories[$plancat2key]->categories[$plancatkey]->plans[$plankey]);
                }
            }
        }
    }

    private function compare_categories_order($category1, $category2) {
        preg_match('/\[(\d\d)E(\d)/', $category1->categoryname, $cat1firsttrimester);
        preg_match('/\[(\d\d)E(\d)/', $category2->categoryname, $cat2firsttrimester);

        $category1year = $cat1firsttrimester[1];
        $category2year = $cat2firsttrimester[1];
        $category1trim = $cat1firsttrimester[2];
        $category2trim = $cat2firsttrimester[2];

        if ($category1year === $category2year) {
            if ($category1trim !== $category2trim) {
                return ($category1trim < $category2trim) ? -1 : 1;
            }
        } else {
            return ($category1year < $category2year) ? -1 : 1;
        }

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

    private function format_cpf($cpf) {
        return substr($cpf, 0, 3) . '.' .
            substr($cpf, 3, 3) . '.' .
            substr($cpf, 6, 3) . '-' .
            substr($cpf, 9, 2)
        ;
    }

    /**
     * Checks if the user has any 'assessment' type activities (assignments or quizzes)
     * in the given course that have been submitted, are past their deadline, but not yet graded.
     *
     * @param int $courseid The ID of the course.
     * @param int $userid The ID of the user (student).
     * @return bool True if there's at least one such pending assessment, false otherwise.
     */
    private function has_pending_assessment_grading($courseid, $userid) {
        global $DB;

        $now = time();
        $pending_grading_found = false;        

        // 1. Check 'assign' activities        
        $assign_sql = "SELECT a.id, a.duedate, a.cutoffdate, a.grade as maxgrade,
                              asub.id as submissionid, asub.status as submissionstatus,
                              ag.id as gradeid, ag.grade as grade 
                       FROM {assign} a
                       JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'assign')
                       LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.userid = :userid AND asub.latest = 1
                       LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = :userid_ag
                       WHERE cm.course = :courseid
                         AND cm.deletioninprogress = 0
                         AND (a.name LIKE :assessmentname1 OR a.name LIKE :assessmentname2 OR a.name LIKE :assessmentname3)";

        $assign_params = [
            'userid' => $userid,
            'userid_ag' => $userid,
            'courseid' => $courseid,
            'assessmentname1' => '%assessment%',
            'assessmentname2' => '%apresentação%',
            'assessmentname3' => '%entrega%',
        ];

        if ($assignments = $DB->get_records_sql($assign_sql, $assign_params)) {
            foreach ($assignments as $assign) {
                // Determine the effective deadline (cutoffdate if set and valid, otherwise duedate)
                $deadline = 0;
                if (!empty($assign->cutoffdate) && $assign->cutoffdate > 0) {
                    $deadline = (int)$assign->cutoffdate;
                } else if (!empty($assign->duedate) && $assign->duedate > 0) {
                    $deadline = (int)$assign->duedate;
                }

                $has_submission = (!empty($assign->submissionid) && $assign->submissionstatus == 'submitted'); 
                $due_date_passed = ($deadline > 0 && $deadline < $now);
                $no_grade_yet = (empty($assign->gradeid) || $assign->grade < 0 ); 

                if ($has_submission && $due_date_passed && $no_grade_yet) {                    
                    if ($assign->maxgrade != 0) {
                        $timeout_grace_period_ends = strtotime("+" . $this->gradingTimeoutPeriodInactivity, $deadline);
                        if ($now < $timeout_grace_period_ends) {
                            // It's pending, but still within the grace period for grading.
                            $pending_grading_found = true;
                        }                         
                        break;
                    }
                }
            }
        }

        if ($pending_grading_found) {
            return true;
        }
        
        return $pending_grading_found;
    }
}
