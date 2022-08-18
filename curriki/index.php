<?php
/**
 * The gradebook curriki report
 *
 * @package   gradereport_curriki
 * @copyright 2007 Moodle Pty Ltd (http://moodle.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/user/renderer.php');
require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/report/curriki/lib.php');

if (isset($_GET['activityRedirectUrl']) && isset($_GET['studentid'])) {
    $_SESSION['student_id'] = $_GET['studentid'];
    $_SESSION['is_summary'] = true;
    header("Location: " . urldecode($_GET['activityRedirectUrl']));
    die();
}

$courseid      = required_param('id', PARAM_INT);        // course id
$page          = optional_param('page', 0, PARAM_INT);   // active page
$edit          = optional_param('edit', -1, PARAM_BOOL); // sticky editting mode

$sortitemid    = optional_param('sortitemid', 0, PARAM_ALPHANUM); // sort by which grade item
$action        = optional_param('action', 0, PARAM_ALPHAEXT);
$move          = optional_param('move', 0, PARAM_INT);
$type          = optional_param('type', 0, PARAM_ALPHA);
$target        = optional_param('target', 0, PARAM_ALPHANUM);
$toggle        = optional_param('toggle', null, PARAM_INT);
$toggle_type   = optional_param('toggle_type', 0, PARAM_ALPHANUM);

$currikireportsifirst  = optional_param('sifirst', null, PARAM_NOTAGS);
$currikireportsilast   = optional_param('silast', null, PARAM_NOTAGS);

$PAGE->set_url(new moodle_url('/grade/report/curriki/index.php', array('id'=>$courseid)));
$PAGE->set_pagelayout('report');
$PAGE->requires->js_call_amd('gradereport_curriki/stickycolspan', 'init');

// basic access checks
if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('invalidcourseid');
}
require_login($course);
$context = context_course::instance($course->id);

// The report object is recreated each time, save search information to SESSION object for future use.
if (isset($currikireportsifirst)) {
    $SESSION->gradereport["filterfirstname-{$context->id}"] = $currikireportsifirst;
}
if (isset($currikireportsilast)) {
    $SESSION->gradereport["filtersurname-{$context->id}"] = $currikireportsilast;
}

require_capability('gradereport/curriki:view', $context);
require_capability('moodle/grade:viewall', $context);

// return tracking object
$gpr = new grade_plugin_return(
    array(
        'type' => 'report',
        'plugin' => 'curriki',
        'course' => $course,
        'page' => $page
    )
);

// last selected report session tracking
if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}
$USER->grade_last_report[$course->id] = 'curriki';

// Build editing on/off buttons.
$buttons = '';

$PAGE->set_other_editing_capability('moodle/grade:edit');

if ($PAGE->user_allowed_editing() && (property_exists($PAGE->theme, 'haseditswitch') && !$PAGE->theme->haseditswitch)) {
    if ($edit != - 1) {
        $USER->editing = $edit;
    }

    // Page params for the turn editing on button.
    $options = $gpr->get_options();
    $buttons = $OUTPUT->edit_button(new moodle_url($PAGE->url, $options), 'get');
}

$gradeserror = array();

// Handle toggle change request
if (!is_null($toggle) && !empty($toggle_type)) {
    set_user_preferences(array('grade_report_show'.$toggle_type => $toggle));
}

// Perform actions
if (!empty($target) && !empty($action) && confirm_sesskey()) {
    grade_report_curriki::do_process_action($target, $action, $courseid);
}

$reportname = get_string('pluginname', 'gradereport_curriki');

// Do this check just before printing the grade header (and only do it once).
grade_regrade_final_grades_if_required($course);

// Print header
print_grade_page_head($COURSE->id, 'report', 'curriki', $reportname, false, $buttons);

//Initialise the curriki report object that produces the table
//the class grade_report_curriki_ajax was removed as part of MDL-21562
$report = new grade_report_curriki($courseid, $gpr, $context, $page, $sortitemid);
$numusers = $report->get_numusers(true, true);

// make sure separate group does not prevent view
if ($report->currentgroup == -2) {
    echo $OUTPUT->heading(get_string("notingroup"));
    echo $OUTPUT->footer();
    exit;
}

$warnings = [];
$isediting = has_capability('moodle/grade:edit', $context) && isset($USER->editing) && $USER->editing;
if ($isediting && ($data = data_submitted()) && confirm_sesskey()) {
    // Processing posted grades & feedback here.
    $warnings = $report->process_data($data);
}

// Final grades MUST be loaded after the processing.
if (class_exists('\core_user\fields')) {
    $report->load_users();    
} else {
    $report->load_users_legacy();
}

$report->load_final_grades();
echo $report->group_selector;

// User search
$url = new moodle_url('/grade/report/curriki/index.php', array('id' => $course->id));
$firstinitial = $SESSION->gradereport["filterfirstname-{$context->id}"] ?? '';
$lastinitial  = $SESSION->gradereport["filtersurname-{$context->id}"] ?? '';
$totalusers = $report->get_numusers(true, false);
$renderer = $PAGE->get_renderer('core_user');
echo $renderer->user_search($url, $firstinitial, $lastinitial, $numusers, $totalusers, $report->currentgroupname);

//show warnings if any
foreach ($warnings as $warning) {
    echo $OUTPUT->notification($warning);
}

$studentsperpage = $report->get_students_per_page();
// Don't use paging if studentsperpage is empty or 0 at course AND site levels
if (!empty($studentsperpage)) {
    echo $OUTPUT->paging_bar($numusers, $report->page, $studentsperpage, $report->pbarurl);
}

$displayaverages = true;
if ($numusers == 0) {
    $displayaverages = false;
}

$reporthtml = $report->get_grade_table($displayaverages);

// print submit button
if (!empty($USER->editing) && ($report->get_pref('showquickfeedback') || $report->get_pref('quickgrading'))) {
    echo '<form action="index.php" enctype="application/x-www-form-urlencoded" method="post" id="gradereport_curriki">'; // Enforce compatibility with our max_input_vars hack.
    echo '<div>';
    echo '<input type="hidden" value="'.s($courseid).'" name="id" />';
    echo '<input type="hidden" value="'.sesskey().'" name="sesskey" />';
    echo '<input type="hidden" value="'.time().'" name="timepageload" />';
    echo '<input type="hidden" value="curriki" name="report"/>';
    echo '<input type="hidden" value="'.$page.'" name="page"/>';
    echo $gpr->get_form_fields();
    echo $reporthtml;
    echo '<div class="submit"><input type="submit" id="currikisubmit" class="btn btn-primary"
        value="'.s(get_string('savechanges')).'" /></div>';
    echo '</div></form>';
} else {
    echo $reporthtml;
}

// prints paging bar at bottom for large pages
if (!empty($studentsperpage) && $studentsperpage >= 20) {
    echo $OUTPUT->paging_bar($numusers, $report->page, $studentsperpage, $report->pbarurl);
}

$event = \gradereport_curriki\event\grade_report_viewed::create(
    array(
        'context' => $context,
        'courseid' => $courseid,
    )
);
$event->trigger();

echo $OUTPUT->footer();
