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
 * Arquivo contendo a principal classe do bloco.
 *
 * Contém a classe que define o conteúdo do bloco.
 *
 * @package    block_lp_coursecategories
 * @copyright  2016 Instituto Infnet
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Classe do bloco.
 *
 * Define o conteúdo do bloco.
 *
 * @package    block_lp_coursecategories
 * @copyright  2016 Instituto Infnet
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_lp_coursecategories_test extends block_base
{

    /**
     * Retorna os formatos aplicáveis.
     *
     * @return array
     */
    public function applicable_formats()
    {
        return array(
            'site' => true,
            'my' => true,
            'user' => true,
            'blocks-lp_coursecategories-full_report' => true,
            'admin-tool-lp' => true
        );
    }

    /**
     * Inicializa o bloco.
     *
     * @return void
     */
    public function init()
    {
        $this->title = get_string('pluginname', 'block_lp_coursecategories');
    }

    /**
     * Obtém o conteúdo do bloco.
     *
     * @return stdClass
     */
    public function get_content()
    {
        if (isset($this->content)) {
            return $this->content;
        }
        $this->content = new stdClass();

        if (!get_config('core_competency', 'enabled')) {
            return $this->content;
        }

        // Bloco necessita de um usuário válido, que não seja Visitante, para exibir os planos de aprendizado do usuário.
        if (isloggedin() && !isguestuser()) {

            $user = null;
            if (substr($this->page->url->get_path(), -17) === '/user/profile.php') {
                global $DB;
                $user = $DB->get_record('user', array('id' => $this->page->url->get_param('id')), '*', MUST_EXIST);
            }

            $plans = new \block_lp_coursecategories\output\plan_list_test($user);
            if (!$plans->has_content()) {
                return $this->content;
            }

            $renderer = $this->page->get_renderer('block_lp_coursecategories_test');
            $this->content->text = $renderer->render($plans);
            $this->content->footer = '';
        }

        return $this->content;
    }

}
