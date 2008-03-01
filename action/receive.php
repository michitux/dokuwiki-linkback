<?php

/**
 * Receive component of the DokuWiki Linkback action plugin.
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Gina Haeussge <osd@foosel.net>
 * @link       http://wiki.foosel.net/snippets/dokuwiki/linkback
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once (DOKU_PLUGIN . 'action.php');

require_once (DOKU_INC . 'inc/common.php');
require_once (DOKU_INC . 'inc/template.php');

if (!defined('NL'))
    define('NL', "\n");

class action_plugin_linkback_receive extends DokuWiki_Action_Plugin {

    /**
     * return some info
     */
    function getInfo() {
        return array (
            'author' => 'Gina Haeussge',
            'email' => 'osd@foosel.net',
            'date' => '2007-04-12',
            'name' => 'Linkback Plugin (receive component)',
            'desc' => 'Injects autodiscovery-code into output.',
            'url' => 'http://wiki.foosel.net/snippets/dokuwiki/linkback',
        );
    }

    /**
     * Register the eventhandlers.
     */
    function register(& $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handle_act_render', array ());
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handle_metaheader_output', array ());
        $controller->register_hook('ACTION_HEADERS_SEND', 'BEFORE', $this, 'handle_headers_send', array ());
    }

    /**
     * Handler for the TPL_ACT_RENDER event
     */
    function handle_act_render(& $event, $params) {
        global $ID;

        // Action not 'show'? Quit
        if ($event->data != 'show')
            return;

        // Trackbacks disabled? Quit
        if (!$this->getConf('enable_trackback'))
            return;

        // Get linkback metadata
        $file = metaFN($ID, '.linkbacks');
        $data = array (
            'send' => false,
            'receive' => false,
            'display' => false,
            'sentpings' => array (),
            'receivedpings' => array (),
            'number' => 0,
            
        );
        if (@ file_exists($file))
            $data = unserialize(io_readFile($file, false));

        // Does not accept linkbacks? Quit
        if (!$data['receive'])
            return;

        // if trackbacks are enabled, insert RDF definition of trackback into output
        if ($this->getConf('enable_trackback')) {
            echo '<!--<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"' . NL .
            'xmlns:dc="http://purl.org/dc/elements/1.1/"' . NL .
            'xmlns:trackback="http://madskills.com/public/xml/rss/module/trackback/">' . NL .
            '<rdf:Description' . NL .
            'rdf:about="' . wl($ID, '', true) . '"' . NL .
            'dc:identifier="' . wl($ID, '', true) . '"' . NL .
            'dc:title="' . tpl_pagetitle($ID, true) . '"' . NL .
            'trackback:ping="' . DOKU_URL . 'lib/plugins/linkback/exe/trackback.php/' . $ID . '" />' . NL .
            '</rdf:RDF>-->';
        }
    }

    /**
     * Handler for the TPL_METAHEADER_OUTPUT event
     */
    function handle_metaheader_output(& $event, $params) {
        global $ID;

        // Pingbacks disabled? Quit
        if (!$this->getConf('enable_pingback'))
            return;

        // Get linkback metadata
        $file = metaFN($ID, '.linkbacks');
        $data = array (
            'send' => false,
            'receive' => false,
            'display' => false,
            'sentpings' => array (),
            'receivedpings' => array (),
            'number' => 0,
            
        );
        if (@ file_exists($file))
            $data = unserialize(io_readFile($file, false));

        // Does not accept linkbacks? Quit
        if (!$data['receive'])
            return;

        // Add pingback metaheader
        $event->data['link'][] = array (
            'rel' => 'pingback',
            'href' => DOKU_URL . 'lib/plugins/linkback/exe/pingback.php/' . $ID
        );
        return true;
    }

    /**
     * Handler for the ACTION_HEADERS_SEND event
     */
    function handle_headers_send(& $event, $params) {
        global $ID;

        // Pingbacks disabled? Quit
        if (!$this->getConf('enable_pingback'))
            return;

        // Get linkback metadata
        $file = metaFN($ID, '.linkbacks');
        $data = array (
            'send' => false,
            'receive' => false,
            'display' => false,
            'sentpings' => array (),
            'receivedpings' => array (),
            'number' => 0,
            
        );
        if (@ file_exists($file))
            $data = unserialize(io_readFile($file, false));

        // Does not accept linkbacks? Quit
        if (!$data['receive'])
            return;

        // Add pingback header
        $event->data[] = 'X-Pingback: ' . DOKU_URL . 'lib/plugins/linkback/exe/pingback.php/' . $ID;
        return true;
    }
}