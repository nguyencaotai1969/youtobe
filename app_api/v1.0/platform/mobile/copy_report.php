<?php

if (!IS_LOGGED) {

	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '1',
            'error_text' => 'Not logged in'
        )
	);
}

else if (empty($_POST['id']) || !is_numeric($_POST['id']) || $_POST['id'] < 1){

	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '2',
            'error_text' => 'Bad Request, Invalid or missing parameter'
        )
	);
}
else if (empty($_POST['text'])){

	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '2',
            'error_text' => 'Bad Request, Invalid or missing parameter (text)'
        )
	);
}

else{

	$video_id    = PT_Secure($_POST['id']);
	$video_data  = $db->where('id',$video_id)->getOne(T_VIDEOS);
	$user_id     = $user->id;
	if (!empty($video_data)) {
		$text    = PT_Secure($_POST['text']);
		$re_data = array(
			'user_id' => $user->id,
			'video_id' => $video_id,
			'time' => time(),
			'text' => $text,
		);

		if ($db->insert(T_COPYRIGHT,$re_data)) {
			$response_data     = array(
			    'api_status'   => '200',
			    'api_version'  => $api_version,
			    'success_type' => 'copy_report',
			    'message'      => 'video successfully reported'
			);
		}
	}
	else{
		$response_data    = array(
		    'api_status'  => '400',
		    'api_version' => $api_version,
		    'errors' => array(
	            'error_id' => '3',
	            'error_text' => 'video not found'
	        )
		);
	}

}