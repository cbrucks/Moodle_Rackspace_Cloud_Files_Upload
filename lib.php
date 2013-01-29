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
 * This plugin is used to access youtube videos
 *
 * @since 2.0
 * @package    repository_youtube
 * @copyright  2010 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php'); //Includes the CloudFiles PHP API.. Ensure the API files are located in your Global includes folder or in the same directory
require_once('cloudfiles.php');


/**
 * repository_youtube class
 *
 * @since 2.0
 * @package    repository_youtube
 * @copyright  2009 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class repository_rackspace_cloud_files extends repository {

    public $cdn;

    /**
     * Youtube plugin constructor
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);

        $this->username = get_config('rackspace_cloud_files', 'username');
        $this->api_key = get_config('rackspace_cloud_files', 'api_key');
        $plugin_name = get_config('rackspace_cloud_files', 'pluginname');
        $this->container_name = (strlen($plugin_name) > 0)? $plugin_name : get_string('default_container', 'repository_rackspace_cloud_files');
        $this->cdn_enable = get_config('rackspace_cloud_files', 'cdn') == 0;

        $this->init_connection();

        $file = 'ver/crab/more/container info.txt';
        $obj = $this->container->create_object($file);
        $obj->write($this->container->__toString());
        $this->container->create_paths($file);
    }

    private function init_connection() {
        // Verify authentication information
        try {
            $this->auth = new CF_Authentication($this->username, $this->api_key);
            $this->auth->authenticate();
        }
        catch (Exception $e) {
            throw new moodle_exception('repo_auth_fail', 'repository_rackspace_cloud_files');
        }

        // Initialize the connection
        $conn = new CF_Connection($this->auth);
        // Get a list of all the available containers
        $containers = $conn->list_containers();
        // See if the container already exists
        $container_exists = in_array($this->container_name, $containers);

        if ($container_exists) {
            // Save a connection to the container
            $this->container = $conn->get_container($this->container_name);
        }
        else {
            // The container specified does not exists so create it.
            $this->container = $conn->create_container($this->container_name);
        }

        if ($this->cdn_enable) {
            // Enable CDN for the container
            $this->container->make_public();
        } else {
            // Disable CDN for the container
            $this->container->make_private();
        }
    }

    public function get_listing($path='', $page = '') {
        global $CFG, $OUTPUT;

        // Check for required information
        if (empty($this->api_key)) {
            throw new moodle_exception('need_api_key', 'repository_rackspace_cloud_files');
        }
        elseif (empty($this->username)) {
            throw new moodle_exception('need_username', 'repository_rackspace_cloud_files');
        }
        elseif (empty($this->container_name)) {
            throw new moodle_exception('need_cont_name', 'repository_rackspace_cloud_files');
        }

        $subfolders = explode('/', $path);
        $nav = array(array('name'=>'root', 'path'=>''));
        $prev_dir = '';
        foreach ($subfolders as $sub) {
            if (empty($sub)) {
                continue;
            }
            if (empty($prev_dir)) {
                $prev_dir = $sub;
            }
            else {
                $prev_dir .= '/'.$sub;
            }
            $nav[] = array('name'=>$sub, 'path'=>$prev_dir);
        }

        $list = array();
        $list['path'] = $nav; //array(array('name'=>'root','path'=>'/'), array('name'=>'subfolder', 'path'=>'/subfolder'));
        $list['manage'] = null;
        $list['nologin'] = true;
        $list['dynload'] = true;
        $list['list'] = $this->get_rcf_object_list($path, $page);

        return $list;
    }

    private function get_rcf_object_list($path = '', $page = ''){
        global $OUTPUT;

        if (empty($this->container)) {
            $this->init_connection();
        }

        // Get all objects in designated path
        $objects = $this->container->get_objects(0, NULL, NULL, $path, '/');

        foreach($objects as $obj) {
            if (preg_match('/^[^.]+\.[a-zA-Z0-9]+$/', $obj->name)) {
                // Add reference as a file
                $dir_list[] = array('title'=>str_replace($path.'/', '', $obj->name), 'date'=>$obj->last_modified, 'size'=>$obj->content_length, 'source'=>$this->container->cdn_uri.'/'.$obj->name, 'url'=>$this->container->cdn_uri.'/'.$obj->name, 'thumbnail'=>$this->container->cdn_uri.'/'.$obj->name);
            }
            else {
                // Add reference as a folder
                $dir_list[] = array('title'=>str_replace($path.'/', '', $obj->name), 'date'=>$obj->last_modified, 'size'=>$obj->content_length, 'path'=>$obj->name, 'children'=>array(), 'thumbnail' => $OUTPUT->pix_url(file_folder_icon(90))->out(false));
            }
        }

        // $dir_list = array(
            // array('title'=>'filename1', 'date'=>'1340002147', 'size'=>'10451213', 'source'=>'http://www.moodle.com/dl.rar'),
            // array('title'=>'folder', 'date'=>'1340002147', 'size'=>'0', 'children'=>array())
        // );

        return $dir_list;
    }

    public function search($search_text, $page = 0) {
        $ret = array();
        $ret['dynload'] = true;
        $ret['nologin'] = true;
        $ret['list'] = array();

        // Initialize the connection if it is not up to date
        if (empty($this->container)) {
            $this->init_connection();
        }

        // Get all objects in designated path
        $objects = $this->container->get_objects();

        // Initialize the results array and the levenshtein array
        $search_res = array();
        $levenshtein = 1.0;

        // Compare each each file name to the search text
        foreach ($objects as $obj) {
            $file_name = end(explode('/', $obj->name));
            $levenshtein = levenshtein($search_text, substr($file_name, 0, strpos($file_name, '.'))) / ((strlen($search_text) > strlen($file_name))? strlen($search_text) : strlen($file_name));
            if ($levenshtein < 0.5) {
                if (preg_match('/^[^.]+\.[a-zA-Z0-9]+$/', $obj->name)) {
                    $search_res[] = array('title'=>$file_name, 'date'=>$obj->last_modified, 'size'=>$obj->content_length, 'source'=>$this->container->cdn_uri.'/'.$obj->name, 'url'=>$this->container->cdn_uri.'/'.$obj->name, 'thumbnail'=>$this->container->cdn_uri.'/'.$obj->name);
                }
            }
        }

        $ret['list'] = $search_res;

        return $ret;

    }

    public function global_search() {
        return false;
    }

    public static function get_type_option_names() {
        return array('username', 'api_key', 'pluginname', 'cdn');
    }

    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform);
        $strrequired = get_string('required');
        //$ah = $mform->addElement('select', 'auth_host', get_string('auth_host','repository_rackspace_cloud_files'), array(get_string('US','repository_rackspace_cloud_files'), get_string('UK','repository_rackspace_cloud_files')));
        //$ah->setMultiple(false);
        //$ah->setSelected(get_string('US','repository_rackspace_cloud_files'));

        //$v = $mform->addElement('select', 'version', get_string('version','repository_rackspace_cloud_files'), array(get_string('v1','repository_rackspace_cloud_files'), get_string('v2','repository_rackspace_cloud_files')));
        //$v->setMultiple(false);
        //$v->setSelected(get_string('v1','repository_rackspace_cloud_files'));

        $cdn = $mform->addElement('select', 'cdn', get_string('cdn','repository_rackspace_cloud_files'), array(get_string('on','repository_rackspace_cloud_files'), get_string('off','repository_rackspace_cloud_files')));
        $cdn->setMultiple(false);
        $cdn->setSelected(get_string('on','repository_rackspace_cloud_files'));

        $mform->addElement('static','spacer','','');

        $mform->addElement('static','auth_error','','');

        $mform->addElement('text', 'username', get_string('username', 'repository_rackspace_cloud_files'));
        $mform->addElement('text', 'api_key', get_string('api_key', 'repository_rackspace_cloud_files'));
        $mform->addElement('static', 'instructions', '', get_string('instruct', 'repository_rackspace_cloud_files'));

        //$mform->addRule('auth_host', $strrequired, 'required', null, 'client');
        //$mform->addRule('version', $strrequired, 'required', null, 'client');
        $mform->addRule('username', $strrequired, 'required', null, 'client');
        $mform->addRule('api_key', $strrequired, 'required', null, 'client');
    }

    public static function type_form_validation($mform, $data, $errors) {
        $api_key = $data['api_key'];
        $username = $data['username'];

        if (!ctype_alnum($api_key) || !is_numeric('0x'.$api_key)) {
        // The API Key is not a hex string.  Throw a moodle error.
            $errors['api_key'] = get_string('invalid_api_key', 'repository_rackspace_cloud_files');
        }
        elseif (strlen(trim($username)) <=0) {
        // The username is blank.  Throw a moodle error.
            $errors['username'] = get_string('invalid_username', 'repository_rackspace_cloud_files');
        } 
        else
        {
            /*
             * Debug form data dictionary as keys and values.
             */
            //$s = '';
            //foreach ($data as $key => $value) {
            //      $s = $s.', '.$key.'->'.$value;
            //}
            //$errors['auth_error'] = $s;

            //Now lets create a new instance of the authentication Class.
            $auth = new CF_Authentication($username, $api_key);

            try {
                //Calling the Authenticate method returns a valid storage token and allows you to connect to the CloudFiles Platform.
                $auth->authenticate();
            } catch (Exception $e) {
                $errors['auth_error'] = get_string('auth_error', 'repository_rackspace_cloud_files').'<br />"'.$e->getMessage().'"';
            }
        }
        return $errors;
    }

    /**
    * file types supported by youtube plugin
    * @return array
    */
    public function supported_filetypes() {
        return '*';
    }

    /**
    * Youtube plugin only return external links
    * @return int
    */
    public function supported_returntypes() {
        return FILE_EXTERNAL;
    }
}
