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
//
// This file is part of BasicLTI4Moodle
//
// BasicLTI4Moodle is an IMS BasicLTI (Basic Learning Tools for Interoperability)
// consumer for Moodle 1.9 and Moodle 2.0. BasicLTI is a IMS Standard that allows web
// based learning tools to be easily integrated in LMS as native ones. The IMS BasicLTI
// specification is part of the IMS standard Common Cartridge 1.1 Sakai and other main LMS
// are already supporting or going to support BasicLTI. This project Implements the consumer
// for Moodle. Moodle is a Free Open source Learning Management System by Martin Dougiamas.
// BasicLTI4Moodle is a project iniciated and leaded by Ludo(Marc Alier) and Jordi Piguillem
// at the GESSI research group at UPC.
// SimpleLTI consumer for Moodle is an implementation of the early specification of LTI
// by Charles Severance (Dr Chuck) htp://dr-chuck.com , developed by Jordi Piguillem in a
// Google Summer of Code 2008 project co-mentored by Charles Severance and Marc Alier.
//
// BasicLTI4Moodle is copyright 2009 by Marc Alier Forment, Jordi Piguillem and Nikolas Galanis
// of the Universitat Politecnica de Catalunya http://www.upc.edu
// Contact info: Marc Alier Forment granludo @ gmail.com or marc.alier @ upc.edu.

/**
 * This file contains all necessary code to view a lti activity instance
 *
 * @package mod_lti
 * @copyright  2009 Marc Alier, Jordi Piguillem, Nikolas Galanis
 *  marc.alier@upc.edu
 * @copyright  2009 Universitat Politecnica de Catalunya http://www.upc.edu
 * @author     Marc Alier
 * @author     Jordi Piguillem
 * @author     Nikolas Galanis
 * @author     Chris Scribner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once($CFG->dirroot.'/mod/lti/lib.php');
require_once($CFG->dirroot.'/mod/lti/locallib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$userid = required_param('userid', PARAM_INT); // User ID.
$triggerview = optional_param('triggerview', 1, PARAM_BOOL);

$cm = get_coursemodule_from_id('lti', $id, 0, false, MUST_EXIST);
$lti = $DB->get_record('lti', array('id' => $cm->instance), '*', MUST_EXIST);

$typeid = $lti->typeid;
if (empty($typeid) && ($tool = lti_get_tool_by_url_match($lti->toolurl))) {
    $typeid = $tool->id;
}
if ($typeid) {
    $config = lti_get_type_type_config($typeid);
    if ($config->lti_ltiversion === LTI_VERSION_1P3) {
        if (!isset($SESSION->lti_initiatelogin_status)) {
            echo lti_initiate_login_result($cm->course, $id, $lti, $config, $userid);//die('rerer');
            exit;
        } else {
            unset($SESSION->lti_initiatelogin_status);
        }
    }
}

$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/lti:view', $context);

// Completion and trigger events.
if ($triggerview) {
    lti_view($lti, $course, $cm, $context);
}

$lti->cmid = $cm->id;
lti_launch_tool($lti);


/**
 * Generate the form for initiating a login request for an LTI 1.3 message
 *
 * @param int            $courseid  Course ID
 * @param int            $id        LTI instance ID
 * @param stdClass|null  $instance  LTI instance
 * @param stdClass       $config    Tool type configuration
 * @param string         $messagetype   LTI message type
 * @param string         $title     Title of content item
 * @param string         $text      Description of content item
 * @return string
 */
function lti_initiate_login_result($courseid, $id, $instance, $config, $userid, $messagetype = 'basic-lti-launch-request', $title = '',
        $text = '') {
    global $SESSION;

    $params = lti_build_login_request_result($courseid, $id, $instance, $config, $userid, $messagetype);
    //echo "<pre>"; print_r($params); die;
    $SESSION->lti_message_hint = "{$courseid},{$config->typeid},{$id}," . base64_encode($title) . ',' .
        base64_encode($text);

    $r = "<form action=\"" . $config->lti_initiatelogin .
        "\" name=\"ltiInitiateLoginForm\" id=\"ltiInitiateLoginForm\" method=\"post\" " .
        "encType=\"application/x-www-form-urlencoded\">\n";

    foreach ($params as $key => $value) {
        $key = htmlspecialchars($key);
        $value = htmlspecialchars($value);
        $r .= "  <input type=\"hidden\" name=\"{$key}\" value=\"{$value}\"/>\n";
    }
    $r .= "</form>\n";

    $r .= "<script type=\"text/javascript\">\n" .
        "//<![CDATA[\n" .
        "document.ltiInitiateLoginForm.submit();\n" .
        "//]]>\n" .
        "</script>\n";

    return $r;
}


/**
 * Prepares an LTI 1.3 login request
 *
 * @param int            $courseid  Course ID
 * @param int            $id        LTI instance ID
 * @param stdClass|null  $instance  LTI instance
 * @param stdClass       $config    Tool type configuration
 * @param string         $messagetype   LTI message type
 * @return array Login request parameters
 */
function lti_build_login_request_result($courseid, $id, $instance, $config, $userid, $messagetype) {
    global $USER, $CFG;

    if (!empty($instance)) {
        $endpoint = !empty($instance->toolurl) ? $instance->toolurl : $config->lti_toolurl;
    } else {
        $endpoint = $config->lti_toolurl;
        if (($messagetype === 'ContentItemSelectionRequest') && !empty($config->lti_toolurl_ContentItemSelectionRequest)) {
            $endpoint = $config->lti_toolurl_ContentItemSelectionRequest;
        }
    }
    $endpoint = trim($endpoint);

    // If SSL is forced make sure https is on the normal launch URL.
    if (isset($config->lti_forcessl) && ($config->lti_forcessl == '1')) {
        $endpoint = lti_ensure_url_is_https($endpoint);
    } else if (!strstr($endpoint, '://')) {
        $endpoint = 'http://' . $endpoint;
    }
    //$activity_id = substr($endpoint, strpos($endpoint, "activity=")+9);
    //$endpoint = substr($endpoint, 0, strpos($endpoint, "activity="));
    //$endpoint = $endpoint.'&submission=ABCD';//. base64_encode('result_id=&activity_id='.$activity_id.'&user_id=132');
    $params = array();
    $params['iss'] = $CFG->wwwroot;
    $params['target_link_uri'] = $endpoint;//.'&is_summary=1&student_id='.$userid;
    $params['login_hint'] = $USER->id;
    $params['lti_message_hint'] = $id;
    $params['client_id'] = $config->lti_clientid;
    $params['lti_deployment_id'] = $config->typeid;
    $_SESSION['student_id'] = $userid;
    $_SESSION['is_summary'] = true;
    return $params;
}
