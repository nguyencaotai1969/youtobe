<?php
// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.playtubescript.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: wowondersocial@gmail.com   
// +------------------------------------------------------------------------+
// | PlayTube - The Ultimate Video Sharing Platform
// | Copyright (c) 2017 PlayTube. All rights reserved.
// +------------------------------------------------------------------------+

$list_id   = (!empty($_GET['list_id'])) ? PT_Secure($_GET['list_id']) : 0;

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
else if (empty($_GET['list_id'])) {

	$response_data    = array(
	    'api_status'  => '400',
	    'api_version' => $api_version,
	    'errors' => array(
            'error_id' => '2',
            'error_text' => 'Bad Request, Invalid or missing parameter'
        )
	);
}
else{
	$list = $db->where('user_id', $pt->user->id)->where('list_id', $list_id)->get(T_LISTS);
	if (empty($list)) {
		$response_data    = array(
		    'api_status'  => '400',
		    'api_version' => $api_version,
		    'errors' => array(
	            'error_id' => '2',
	            'error_text' => 'Bad Request, Invalid or missing parameter'
	        )
		);
	}
	else{
		$response_data     = array(
	        'api_status'   => '200',
	        'api_version'  => $api_version,
	    );
		$offset  = (!empty($_GET['offset']) && is_numeric($_GET['offset'])) ? $_GET['offset'] : 0;
		$limit   = (!empty($_GET['limit']) && is_numeric($_GET['limit'])) ? $_GET['limit'] : 10;
		$db->where('list_id',$list_id);
		
		if ($offset) {
			#offset list by id
			$db->where('id',$offset,'>');
		}
		$play_list    = $db->get(T_PLAYLISTS, $limit);
		$play_list->videos = array();

		foreach ($play_list as $row) {
			$video = PT_GetVideoByID($row->video_id,0,0,2);
			$video->playlist_link = $pt->config->site_url.'/watch/'.PT_Slug($video->title, $video->video_id).'/list/'.$list_id; 
			$row->videos = $video;
		}

		$response_data['data']   = $play_list;
	}
}
