<?php 

function ltisource_submission_before_launch($lti, $endpoint, $requestparams ) {
    $result = [];
    if(isset($_SESSION['is_summary'])){
        $result['custom_is_summary']=1;
        unset($_SESSION['is_summary']);
    }
    if(isset($_SESSION['student_id'])){
        $result['custom_student_id']=$_SESSION['student_id'];    
        unset($_SESSION['student_id']);
    }    

    return $result;
}
?>