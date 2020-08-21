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
 * Renderable dos resultados do aluno.
 *
 * Contém a classe plan_list, que obtém os dados de competências, frequências e aproveitamento de um user
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 // Declaramos o namespace de que faz parte
namespace block_lp_coursecategories\output;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/attendance/locallib.php'); // para ler sessões individuais de frequência
require_once($CFG->dirroot . '/mod/attendance/classes/summary.php'); // para ler resultado final de frequência, calculado pelo plugin de frequência

// Define que usa neste arquivo esta classe api e plan_exporter que estão no namespace core_competency
// Não está muito claro de onde o código sabe que existe esse namespace
use core_competency\api; 
use core_competency\external\plan_exporter;

// Usa classes do Moodle genéricas, que não estão dentro de namespace algum
use renderable;
use renderer_base;
use templatable;

/**
 * Classe de lista de cursos.
 *
 * Exibe a lista de cursos (ou disciplinas) do estudante, agrupados por categoria de curso.
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// respeita o mesmo contrato das classes renderable e templatable, logo pode ser 
//chamada para o renderer e templatable é que usa o mustache, podendo ser chamada por funções que esperam classes que usam o mustache
class plan_list implements renderable, templatable { 

    /** @var array Resultado da consulta ao banco com dados dos planos(agora cursos). */
    protected $plansqueryresult = array(); // Protect para só poderem ser acessadas por outras classes desse namespace
    /** @var array Categorias dos cursos dos planos. */
    protected $plancategories = array(); 
    /** @var stdClass O usuário. */
    protected $user; 

    /**
     * Construtor.
     */
    public function __construct($user = null) {
        global $USER;
        if (!$user) {
            $user = $USER;
        }
        profile_load_data($user);
        $this->user = $user; // Atribui a variável que até então era local da função a uma propriedade da classe

        // Obter todas as informações que o relatório vai usar a partir do banco, função definida adiante
        $this->plansqueryresult = $this->get_plan_course_categories($user->id);
    }

    // Exporta os dados desejados para um determinado template; o renderer vai decidir para qual template, se Mustache ou não o renderer é que vai decidir
    // Chamando três funções que estão mais abaixo neste arquivo
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
        return !empty($this->plansqueryresult); // retorna um boleano, só diz se tem conteúdo ou não, usando o empty que é do PHP
    }

    // Define uma propriedade de dados do usuário, validando que o $output é da classe rendererbase
    private function set_user_data(renderer_base $output) {
        $user = $this->user;

        // Define a foto do perfil
        $user->picture = $output->user_picture($user, array('visibletoscreenreaders' => false));
        // Monta a url do perfil, que fica junto à imagem
        $user->profileurl = (
            new \moodle_url('/user/view.php', array('id' => $user->id))
        )->out(false);
        // Executa função do Moodle que traz o fullname
        $user->fullname = fullname($user);

        // Atualiza o usuário em questão com os dados coletados na função
        $this->user = $user;
    }

    // Organiza os dados obtidos para montar o relatório
    private function set_plans_data(renderer_base $output) {
        // Reinicialização da variável porque export_for_template é chamado duas vezes na mesma tela
        $this->plancategories = array();

        foreach ($this->plansqueryresult as $courseplan) {
            $courseplan = $this->set_plan_category($courseplan, $output);

            // Atualmente não verifica a frequência para o EAD
            if ($this->plancategories[$courseplan->category2id]->distance === false) {
                $courseplan = $this->set_attendance_data($courseplan);
            }

            $courseplan = $this->set_completed($courseplan);

            $this->plancategories[$courseplan->category2id]->categories[$courseplan->categoryid]->plans[] = $courseplan;
        }
    }

    /* Usamos uma nomenclatura de variáveis que reproduz a estrutura de categorias a partir do número de 
     * níveis de diferença em relação ao curso. Então categoryid refere-se à categoria do curso, no caso, o bloco da graduação.
     * Já category2id refere-se à classe em que está o curso.
     * 
     * set_plan_category pega um curso e coloca dentro de um objeto de categoria de bloco 
    */
    private function set_plan_category($courseplan, renderer_base $output) {
        $categoryid = $courseplan->categoryid; // bloco em que está o curso
        $category2id = $courseplan->category2id; // classe em que está o curso

        // Se a classe ainda não existir, ele a cria dentro da propriedade plancategories
        if (!isset($this->plancategories[$category2id])) {
            $this->plancategories[$category2id] = $this->create_plan_category2_object($courseplan); // cria objeto da classe para usar no relatóri
        }

        // Se o bloco ainda não existir, ele cria dentro da propriedade plancategories, dentro da classe de que ela faz parte
        if (!isset($this->plancategories[$category2id]->categories[$categoryid])) {
            $this->plancategories[$category2id]->categories[$categoryid] = $this->create_plan_category_object($courseplan);
        }

        // Este if parece redundante, pois não estamos usando planos atualmente
        // Então o conteúdo do if sempre é executado
        if (isset($courseplan->courseid)) { 
            if ($courseplan->visible == 1) { // Verifica se o curso não está oculto
                
                // Trata-se de uma pasta do Moodle que tem funções de learning plans, neste caso pegando os dados de competência do Moodle
                $coursecompetenciespage = new \tool_lp\output\course_competencies_page($courseplan->courseid);
                // Função genérica de renderer que puxa os dados para jogar num template
                $courseplan->coursecompetencies = $coursecompetenciespage->export_for_template($output);
                // Função desta classe que pega as atividades associadas às competências
                $courseplan->coursemodules = $this->get_course_competency_activities($courseplan);
            }

            // Proavelmente hoje em dia isto é reduntante, mas quando usávamos planos isso fazia a correspondência entre planos e curso, que eram 1 para 1
            // Indexcategory é a DR1, DR2 etc, para definir a ordem em que aparecem na categoria do Moodle
            // A ordem que os cursos estão dentro da categoria é a respeitada
            $courseplan->planindexincategory = $courseplan->courseindexincategory;

            // Lê a partir do SQL, advinhando a partir do nome da classe, se é a distância ou não
            // Não está claro se no nível da categoria podemos por propriedades customizadas
            // Existe uma tabela de propriedades customizadas, mas não sabemos se é a mesma para cursos e para usuários - achamos que não
            $courseplan->distance = $this->plancategories[$category2id]->distance;

            // Chama uma função que deve gerar um array com o nome da disciplina e o id, separando/limpando a informação
            // Gera um objeto com duas propriedades, só o id do curso e só o nome
            $coursenameidsplit = $this->get_course_name_id_split($courseplan->coursename);
            // Aqui a gente pega só o nome sem o id em um objeto
            $courseplan->coursenamewithoutid = $coursenameidsplit->coursenamewithoutid;
            
            // Somente para os cursos após um certo ponto em que nós passamos a colocar o ID da disciplina no nome do curso
            if (isset($coursenameidsplit->courseidnumber)) {
                // O getstring pega no pacote de idiomas do plugin a string "Código da disciplina" e a exibe com o código em seguida.
                $courseplan->courseidnumber = get_string('course_id_number', 'block_lp_coursecategories') . ': ' . $coursenameidsplit->courseidnumber;
            }
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

    private function set_completed($courseplan) {
        $categoryid = $courseplan->categoryid;
        $category2id = $courseplan->category2id;
        $competenciesok = $courseplan->competenciesok;

        /* Course competencies string */
        $courseplan->competenciescompletedstring = get_string('competencies_completed_' . $competenciesok, 'block_lp_coursecategories');

        /* Course attendance string */
        $attendanceidentifier = 'course_attendance_';
        if (
            isset($courseplan->attendancecmid)
            && isset($courseplan->attendance)
        ) {
            $attendanceidentifier .= ($courseplan->attendance >= 0.75) ? 'ok' : 'insufficient';
        } else {
            $attendanceidentifier .= 'no_data';
        }

        $courseplan->attendanceidentifier = $attendanceidentifier;
        $courseplan->attendancestring = get_string($attendanceidentifier, 'block_lp_coursecategories');

        /* Course passed string and class */
        $coursepassedidentifier = 'course_passed_';

        if (
            $courseplan->visible != 1
            || $courseplan->ongoing == 1
        ) {
            $coursepassedidentifier .= 'ongoing';
            $courseplan->coursepassedclass = '';

            $courseplan->attendancestring.= ' ' . get_string('course_attendance_so_far', 'block_lp_coursecategories');
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

        if ($attendanceidentifier !== 'course_attendance_no_data') {
            $courseplan->attendancestring .= ': ' . $courseplan->attendanceformatted;
        }

        $courseplan->coursepassedidentifier = $coursepassedidentifier;
        $courseplan->coursepassedstring = get_string($coursepassedidentifier, 'block_lp_coursecategories');

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

        return $courseplan;
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

    private function get_plan_course_categories() {
        global $DB;

        return $DB->get_records_sql("
            select c.id courseid,
                c.fullname coursename,
                c.sortorder coursesortorder,
                c.visible,
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
                    and cc2.name like '[GR%'
                join {course_categories} cc3 on cc3.id = cc2.parent
                join {course_categories} cc4 on cc4.id = cc3.parent
                left join {competency_coursecomp} ccc on ccc.courseid = c.id
                left join {competency_usercompcourse} ucc on ucc.competencyid = ccc.competencyid
                    and ucc.userid = ra.userid
                    and ucc.courseid = c.id
                left join (
                    {course_modules} cmatt
                        join {modules} matt on matt.id = cmatt.module
                            and matt.name = 'attendance'
                        join {attendance} att on att.id = cmatt.instance
                ) on cmatt.course = c.id
                    and cmatt.visible = 1
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
        $category2->distance = (preg_match('/\[GRL/', $category2->categoryname) === 1);

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

    private function get_exported_data() {
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

        return array(
            'hasplans' => !empty($this->plansqueryresult),
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

    private function set_user_course_competencies(renderer_base $output) {
        foreach ($this->plancategories as $plancat2key => $plancategories2) {
            foreach ($plancategories2->categories as $plancatkey => $plancategories) {
                foreach ($plancategories->plans as $plankey => $plan) {
                    if (!isset($plan->coursecompetencies)) {
                        continue;
                    }

                    foreach ($plan->coursecompetencies->competencies as $compkey => $competency) {
                        $competencyreport = new \report_competency\output\report($competency['coursecompetency']->courseid, $this->user->id);
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

    private function get_course_name_id_split($coursename) {
        preg_match('/^\[([^\s]+)\] (.*)/', $coursename, $regexresult);

        $coursenameidsplit = new \stdClass();

        if (!empty($regexresult)) {
            $coursenameidsplit->courseidnumber = $regexresult[1];
            $coursenameidsplit->coursenamewithoutid = $regexresult[2];
        } else {
            $coursenameidsplit->coursenamewithoutid = $coursename;
        }

        return $coursenameidsplit;
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
}
