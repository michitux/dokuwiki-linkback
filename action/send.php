<?php

/**
 * Send component of the DokuWiki Linkback action plugin.
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
require_once (DOKU_INC . 'inc/template.php');
require_once (DOKU_INC . 'inc/common.php');
require_once (DOKU_INC . 'inc/mail.php');
require_once (DOKU_INC . 'inc/infoutils.php');
require_once (DOKU_INC . 'inc/pageutils.php');
require_once (DOKU_INC . 'inc/IXR_Library.php');
require_once (DOKU_INC . 'inc/form.php');

require_once (DOKU_PLUGIN . 'linkback/http.php');

class action_plugin_linkback_send extends DokuWiki_Action_Plugin {

    /**
     * return some info
     */
    function getInfo() {
        return array (
            'author' => 'Gina Haeussge',
            'email' => 'osd@foosel.net',
            'date' => '2007-04-12',
            'name' => 'Linkback Plugin (send component)',
            'desc' => 'Responsible of sending linkbacks to urls upon saving a linkback enabled wiki page.',
            'url' => 'http://wiki.foosel.net/snippets/dokuwiki/linkback',
        );
    }

    /**
     * Register the eventhandlers.
     */
    function register(& $controller) {
        $controller->register_hook('HTML_EDITFORM_OUTPUT', 'BEFORE', $this, 'handle_editform_output', array ());
        $controller->register_hook('PARSER_HANDLER_DONE', 'AFTER', $this, 'handle_parser_handler_done', array ());
    }

    /**
     * Handler for the PARSER_HANDLER_DONE event
     */
    function handle_parser_handler_done(& $event, $params) {
        global $ID;
        global $ACT;
        global $conf;

        // only perform linkbacks on save of a wikipage
        if ($ACT != 'save')
            return;

        // if guests are not allowed to perform linkbacks, return
        if (!$this->getConf('allow_guests') && !$_SERVER['REMOTE_USER'])
            return;

        // get linkback meta file name
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
        $data['send'] = ($_REQUEST['plugin__linkback_toggle']) ? true : false;

        if (!$data['send'])
            return;

        $meta = p_get_metadata($ID);

        // prepare linkback info
        $linkback_info = array ();
        $linkback_info['title'] = tpl_pagetitle($ID, true);
        $linkback_info['url'] = wl($ID, '', true);
        $linkback_info['blog_name'] = $conf['title'];
        $linkback_info['excerpt'] = $meta['description']['abstract'];

        // get links
        $pages = $this->_parse_instructionlist($event->data->calls);

        $sentpings = array ();
        foreach ($pages as $page) {
            if (!$data['sentpings'][$page]) {
                // try to ping pages not already pinged
                $this->_ping_page($page, $linkback_info);
            }
            $sentpings[$page] = true;
        }
        $data['sentpings'] = $sentpings;

        // save sent ping info
        io_saveFile($file, serialize($data));

        return true;
    }

    /**
     * Parses a given instruction list and extracts external and -- if configured
     * that way -- internal links.
     * 
     * @param  $list  array  instruction list as generated by the DokuWiki parser
     */
    function _parse_instructionlist($list) {
        $pages = array ();

        foreach ($list as $item) {
            if ($item[0] == 'externallink') {
                $pages[] = $item[1][0];
            } else
                if ($item[0] == 'internallink' && $this->getConf('ping_internal')) {
                    $pages[] = wl($item[1][0], '', true);
                }
        }

        return $pages;
    }

    /**
     * Handles HTML_EDITFORM_OUTPUT event.
     */
    function handle_editform_output(& $event, $params) {
        global $ID;
        global $ACT;
        global $INFO;

        // Not in edit mode? Quit
        if ($ACT != 'edit' && $ACT != 'preview')
            return;

        // page not writable? Quit
        if (!$INFO['writable'])
        	return;

        // if guests are not allowed to perform linkbacks, return
        if (!$this->getConf('allow_guests') && !$_SERVER['REMOTE_USER'])
            return;

        // get linkback meta file name
        $file = metaFN($ID, '.linkbacks');
        $data = array (
            'send' => false,
            'receive' => false,
            'display' => false,
            'sentpings' => array (),
            'receivedpings' => array (),
            'number' => 0,
            
        );
        if (@ file_exists($file)) {
            $data = unserialize(io_readFile($file, false));
        } else {
	        $namespaces = explode(',', $this->getConf('enabled_namespaces'));
			$ns = getNS($ID);
			foreach($namespaces as $namespace) {
			    if (strstr($ns, $namespace) == $ns)
			        $data['send'] = true;
			}
        }

		$form = $event->data;
		$pos = $form->findElementById('wiki__editbar');
		$form->insertElement($pos, form_makeOpenTag('div', array('id'=>'plugin__linkback_wrapper')));
		$form->insertElement($pos + 1, form_makeCheckboxField('plugin__linkback_toggle', '1', $this->getLang('linkback_enabledisable'), 'plugin__linkback_toggle', 'edit', (($data['send']) ? array('checked' => 'checked') : array())));
		$form->insertElement($pos + 2, form_makeCloseTag('div'));
    }

    /**
     * Pings a given page with the given info.
     */
    function _ping_page($page, $linkback_info) {
        $range = $this->getConf('range') * 1024;

        $http_client = new LinkbackHTTPClient();
        $http_client->headers['Range'] = 'bytes=0-' . $range;
        $http_client->max_bodysize = $range;
        $http_client->max_bodysize_limit = true;

        $data = $http_client->get($page, true);
        if (!$data)
            return false;

        $order = explode(',', $this->getConf('order'));
        foreach ($order as $type) {
            if ($this->_ping_page_linkback(trim($type), $page, $http_client->resp_headers, $data, $linkback_info))
                return true;
        }

        return false;
    }

    /**
     * Discovers and executes the actual linkback of given type
     * 
     * @param $type string type of linkback to send, can be "pingback" or "trackback"
     * @param $page string URL of the page to ping
     * @param $headers array headers received from page
     * @param $body string first range bytes of the pages body
     * @param $linkback_info array linkback info
     */
    function _ping_page_linkback($type, $page, $headers, $body, $linkback_info) {
        global $conf;

        if (!$this->getConf('enable_' . $type))
            return false;

        switch ($type) {
            case 'trackback' :
                {
                    $pingurl = $this->_autodiscover_trackback($page, $body);
                    if (!$pingurl)
                        return false;
                    return ($this->_ping_page_trackback($pingurl, $linkback_info)) ? true : false;
                }
            case 'pingback' :
                {
                    $xmlrpc_server = $this->_autodiscover_pingback($headers, $body);
                    if (!$xmlrpc_server)
                        return false;
                    return ($this->_ping_page_pingback($xmlrpc_server, $linkback_info['url'], $page)) ? true : false;
                }
        }
    }

    /**
     * Sends a Pingback to the given url, using the supplied data.
     * 
     * @param $xmlrpc_server string URL of remote XML-RPC server
     * @param $source_url string URL from which to ping
     * @param $target_url string URL to ping
     */
    function _ping_page_pingback($xmlrpc_server, $source_url, $target_url) {
        $client = new IXR_Client($xmlrpc_server);
        return $client->query('pingback.ping', $source_url, $target_url);
    }

    /**
     * Sends a Trackback to the given url, using the supplied data.
     * 
     * @param $pingurl string URL to ping
     * @param $trackback_info array Hash containing title, url and blog_name of linking post
     */
    function _ping_page_trackback($pingurl, $linkback_info) {
        $http_client = new DokuHTTPClient();
        $success = $http_client->post($pingurl, $linkback_info);
    }

    /**
     * Autodiscovers a pingback URL in the given HTTP headers and body.
     * 
     * @param $headers array the headers received from to be pinged page.
     * @param $data string the body received from the pinged page.
     */
    function _autodiscover_pingback($headers, $data) {
        if (isset ($headers['X-Pingback']))
            return $headers['X-Pingback'];
        $regex = '!<link rel="pingback" href="([^"]+)" ?/?>!';
        if (!preg_match($regex, $data, $match))
            return false;
        return $match[1];
    }

    /**
     * Autodiscovers a trackback URL for the given page URL and site body.
     * 
     * @param $page string the url of the page to be pinged.
     * @param $data string the body received from the page to be pinged.
     */
    function _autodiscover_trackback($page, $data) {
        $page_anchorless = substr($page, 0, strrpos($page, '#'));

        $regex = '!<rdf:RDF.*?</rdf:RDF>!is';
        if (preg_match_all($regex, $data, $matches)) {
            foreach ($matches[0] as $rdf) {
                if (!preg_match('!dc:identifier="([^"]+)"!is', $rdf, $match_id))
                    continue;
                $perm_link = $match_id[1];
                if (!($perm_link == $page || $perm_link == $page_anchorless))
                    continue;
                if (!(preg_match('!trackback:ping="([^"]+)"!is', $rdf, $match_plink) || preg_match('!about="([^"]+)"!is', $rdf, $match_plink)))
                    continue;
                return $match_plink[1];
            }
        } else {
            // fix for wordpress
            $regex = '!<a href="([^"]*?)" rel="trackback">!is';
            if (preg_match($regex, $data, $match))
                return $match[1];
        }
        return false;
    }

}
