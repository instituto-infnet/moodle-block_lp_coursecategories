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
 * Página com todas as disciplinas e competências do usuário, organizadas por bloco.
 *
 * Exibe todos os blocos em que o usuário está inscrito, incluindo as disciplinas e suas competências.
 *
 * @package    block_lp_coursecategories
 * @copyright  2017 Instituto Infnet {@link http://infnet.edu.br}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

require_once(__DIR__ . '/../../config.php'); // Para usar algumas variáveis globais que estão neste config, como DB etc.

require_login(); // Exige que a pessoa está logada, é do Moodle

$loggeduserid = $USER->id;

// Parâmetros que esta página espera receber (nome, valor, tipo do parâmetro)
// É um padrão do Moodle alocarmos os parâmetros dentro de um array, daí seguirmos o padrão mesmo só tendo um parâmetro
$params = array('userid' => optional_param('userid', $loggeduserid, PARAM_INT)); 

// Monta a URL do full report a partir da url do nosso moodle, mais a string e mais os parâmetros concatenados
// Este construtor espera um array de parâmetros
$url = new moodle_url('/blocks/lp_coursecategories/full_report.php', $params);

// Guarda o nome do plugin numa variável
$strname = get_string('pluginname', 'block_lp_coursecategories');

// O objeto page é esta página que você está exibindo e que então manipula para as exibições que desejar
$PAGE->set_url($url); // URL da página
$PAGE->navbar->add($strname); // Para barra de navegação

// Para poder aplicar permissões, gerando duas variáveis ao mesmo tempo
// O :: chama uma função estática, não precisando instanciar uma classe dela; só chama uma função que tem no objeto context_user sem querer instanciá-la
$context = $usercontext = context_user::instance($params['userid'], MUST_EXIST); 
$PAGE->set_context($context); // Atribui o contexto à página

// Verifica se o usuário está vendo seu próprio e se tem a capability para ver o relatório de um outro quando for este o caso
// Se um usuário sem a capability abrir o relatório do outro, este usuário vê o seu próprio
if ($params['userid'] !== $loggeduserid && has_capability('moodle/competency:competencymanage', $context)) {
    global $DB;
    $user = $DB->get_record('user', array('id' => $params['userid']), '*', MUST_EXIST); // pega o usuário desejado
} else {
    $params['userid'] = $loggeduserid; // troca para o usuário logado, caso que não tem a capability
    $user = $USER; // o USER em maiúsculas é o usuário logado do moodle
}

// Cabeçalho com o nome do usuário, que aparece lá no alto
$header = fullname($user);

// Define o título da aba
$title = get_string('full_report', 'block_lp_coursecategories');

$PAGE->set_title($title); // Define o título da aba
$PAGE->set_pagelayout('mypublic'); // Usa um certo template de página do tema
$PAGE->set_heading($header); // Conforme acima, o nome da pessoa

// Coloca na variável output o renderer previsto (em classes/output) deste plugin
$output = $PAGE->get_renderer('block_lp_coursecategories');

// Instancia a classe plan_list, que é a classe do histórico em si
$page = new block_lp_coursecategories\output\plan_list($user);

echo $output->header() . $OUTPUT->heading($title); // Este é o header padrão do Moodle
echo $output->render_full_report($page); // Imprime o histórico completo, inclusive com seu cabeçalho, executando o método render_full_report que está dentro do renderer
echo $output->footer(); // Footer padrão do Moodle
