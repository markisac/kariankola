<?php
error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);

/** Location of YT downloader executable installed on computer
    Source/Download: https://github.com/rg3/youtube-dl/ */
$youtube_dl_exe = 'youtube-dl.exe';

/** Youtube Data API for searching videos
    More Information: https://developers.google.com/youtube/v3/ */
$youtube_devkey = 'AIzaSyA-3ajvpkuHuSl5NwVhI-asB-95xCzzEq8';

/** Known qualities for each Format ID on YouTube */
function youtube_quality($format_id){
	switch($format_id){
		# Video
		case 5: return 'LQ FLV 240p';
		case 6: return 'LQ FLV 270p';
		case 17: return 'LQ 3GP 144p';
		case 18: return 'MQ MP4 360p';
		case 22: return 'HQ MP4 720p';
		case 34: return 'MQ FLV 360p';
		case 35: return 'Standard FLV 480p';
		case 36: return 'LQ 3GP 240p';
		case 37: return 'Full HQ MP4 1080p';
		case 38: return 'Original MP4 3072p';
		case 43: return 'MQ WebM 360p';
		case 44: return 'Standard WebM 480p';
		case 45: return 'HQ WebM 720p';
		case 46: return 'Full HQ WebM 1080p';
		case 59: return 'Standard MP4 480p';
		case 78: return 'Standard MP4 480p';
		# 3D Video
		case 82: return '3D MQ MP4 360p';
		case 83: return '3D Standard MP4 480p';
		case 84: return '3D HQ MP4 720p';
		case 85: return '3D Full HQ MP4 1080p';
		case 100: return '3D MQ WebM 360p';
		case 101: return '3D Standard WebM 480p';
		case 102: return '3D HQ WebM 720p';
		# Audio M4A and WebM
		case 140: return 'Audio M4A AAC 128k';
		case 141: return 'Audio M4A AAC 256k';
		case 171: return 'Audio WebM Vorbis 128k';
		case 172: return 'Audio WebM Vorbis 256k';
		case 250: return 'Audio WebM Opus 70k';
		case 251: return 'Audio WebM Opus 160k';
	}
	return false;
}
/** Download an URL using cURL or PHP I/O functions */
function youtube_curl($url) {
	// Always validate input
	if(!(strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
		return "ERROR: Invalid URL: {$url}.";
	}
	if(extension_loaded('curl') && function_exists('curl_exec')) {
		// Use cURL to download
		$ch = curl_init();

		// Setup context
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_HTTPGET => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:31.0) Gecko/20100101 Firefox/31.0',
			CURLOPT_HTTPHEADER => array(
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language: en-US;q=0.8,en;q=0.6',
				'Connection: Close')
			));

		// Query
		$response = curl_exec($ch);

		if($response === false) {
			$errno  = curl_errno($ch);
			$errmsg = curl_error($ch);
			curl_close($ch);
			return "ERROR: Problem reading data from {$url}: {$errno} ({$errmsg})";
		}

		// Close
		curl_close($ch);
	} else {
		// Use PHP I/O functions to download
		@ini_set('track_errors', true);

		// Setup header
		$header  = 'User-Agent: Mozilla/5.0 (Windows NT 6.1; rv:31.0) Gecko/20100101 Firefox/31.0'."\r\n";
		$header .= 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'."\r\n";
		$header .= 'Accept-Language: en-US;q=0.8,en;q=0.6'."\r\n";
		$header .= 'Connection: Close';

		// Setup context
		$context = stream_context_create(array(
			'http' => array(
				'method' => 'GET',
				'header' => $header,
				'protocol_version' => (1.1)
			),
			'ssl' => array(
				'verify_peer' => false
			)));

		// Open and fetch
		$result = @fopen($url, 'r', false, $context);

		if($result === false) {
			return "ERROR: Problem with {$url}, {$php_errormsg}";
		}
		$response = @stream_get_contents($result);

		if($response === false) {
			return "ERROR: Problem reading data from {$url}, {$php_errormsg}";
		}

		// Close
		@fclose($fp);
	}

	// Return content that has been fetched
	return $response;
}
/** Query YouTube API in the classic way for almost every detail (streams won't work for protected content) */
function youtube_classic_query($vid, $json = false) {
	$vid = preg_replace('/[^A-Z0-9_-]/i', '', $vid);
	do {
		$url = 'https://www.youtube.com/get_video_info?&video_id='. $vid;
		$res = youtube_curl($url);

		$info = array();
		@parse_str($res, $info);

		if(is_array($info) && isset($info['title'], $info['thumbnail_url'], $info['url_encoded_fmt_stream_map'])) {
			// The above code has worked. Break and continue parsing.
			break;
		}

		// Access the website to intercept the details from the video
		$url = 'https://www.youtube.com/watch?v='. $vid;
		$res = youtube_curl($url);

		$info = array();
		preg_match('@<script>.*?ytplayer.config\s*=\s*({.+});\s*ytplayer.load.*?</script>@', $res, $info);
		$info = isset($info[1]) ? $info[1] : false;
		$info = $info ? @json_decode($info, true) : false;
		$info = $info ? @$info['args'] : false;
	} while(0);
	if(!is_array($info) || !isset($info['title'], $info['thumbnail_url'], $info['url_encoded_fmt_stream_map'])) {
		// Video is protected. Bail out.
		return false;
	}

	// Initialize some useful variables
	$result = $info;
	$result['fulltitle'] = $info['title'];
	$result['formats'] = array();

	// Parse streams
	$i = 0;
	$my_formats_array = explode(',', $info['url_encoded_fmt_stream_map']);

	foreach($my_formats_array as $format) {
		$parsed_format = $matches = array();
		@parse_str($format, $parsed_format);

		if(isset($parsed_format['s']) || (1 !== preg_match("@[a-z]+/([0-9a-z]+)@i", $parsed_format['type'], $matches))) {
			// Protected or unknown format. Don't save.
			continue;
		}

		// Save this stream including all important details
		$result['formats'][$i] = array();
		$result['formats'][$i]['ext'] = $matches[1];
		$result['formats'][$i]['format'] = "{$parsed_format['itag']} ({$parsed_format['quality']})";
		$result['formats'][$i]['format_id'] = $parsed_format['itag'];
		$result['formats'][$i]['url'] = urldecode($parsed_format['url']);
		$result['formats'][$i]['http_headers'] = array(
				'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; rv:31.0) Gecko/20100101 Firefox/31.0',
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US;q=0.8,en;q=0.6',
				'Accept-Encoding' => 'none',
				'Connection' => 'Close');

		$i++;
	}

	// Return results of classic API
	return ($json ? json_encode($result) : $result);
}
/** Query YouTube API using an advanced tool for a small number of details and streams (works on protected content) */
function youtube_advanced_query($vid, $json = false) {
	global $youtube_dl_exe;

	// Open youtube-dl executable with JSON output and VID argument
	$vid    = preg_replace('/[\x00-\x1f\x7f]/i', '', $vid);
	$handle = popen("{$youtube_dl_exe} -j " . escapeshellarg($vid) . " 2>&1", 'r');

	// Read output
	$message = '';
	while($handle && ($read = fread($handle, 2048))) {
		$message .= $read;
	}
	pclose($handle);

	// Decode output if desired
	if(!$json) {
		$result = @json_decode($message, true);
		if($result) {
			return $result;
		}
	}

	// Return output
	return $message;
}
/** Query YouTube API using all available functions, for all details regarding a video */
function youtube_video_info($vid, $all_formats = false, $json = false) {
	// Query classic API
	$result = youtube_classic_query($vid, false);

	if(!is_array($result)) {
		// Classic API failed, ignore and fill all details from advanced API
		$result = youtube_advanced_query($vid, false);
	} elseif($all_formats || !count($result['formats'])) {
		// Classic API had partial success, fill formats from advanced API
		$advanced_result = youtube_advanced_query($vid, false);
		$result['formats'] = @$advanced_result['formats'];
	}

	// Return complete result
	return ($json ? json_encode($result) : $result);
}
/** Query and parse youtube API using all available functions and return video streams formatted in a friendly way */
function youtube_streams_info($vid, $all_formats = false, $json = false) {
	if(is_array($vid)) {
		// This allows us to receive an information array,
		// no need to query for video info twice if we already have that from somewhere else.
		$result = $vid;
	} else {
		$result = youtube_video_info($vid, $all_formats, false);
	}
	if(!is_array($result)) {
		$output['error'] = ((is_scalar($result) && $result) ? $result : 'ERROR: Unknown error (Code 1).');
		return ($json ? json_encode($output) : $output);
	}
	if(isset($result['error'])) {
		$output['error'] = ((is_scalar($output['error']) && $output['error']) ? $result['error'] : 'ERROR: Unknown error (Code 2).');
		return ($json ? json_encode($output) : $output);
	}
	if(!is_array($result['formats']) || !count($result['formats'])) {
		$output['error'] = 'ERROR: Unknown error (Code 3).';
		return ($json ? json_encode($output) : $output);
	}

	$title = preg_replace('/[^A-Z0-9_-]/i', '', str_replace(' ', '_', $result['fulltitle']));
	$formats = $result['formats'];
	foreach($formats as $f) {
		$url       = "{$f['url']}&title={$title}";
		$filename  = "{$title}.{$f['ext']}";
		$format    = strtoupper($f['ext']) . " {$f['format']}";
		$format_id = $f['format_id'];

		$headers = $f['http_headers'];
		$headers['Accept-Encoding'] = 'none';
		$headers['Connection']      = 'Close';

		$output[] = array('url' => $url, 'filename' => $filename, 'format' => $format, 'itag' => $format_id, 'format_id' => $format_id, 'headers' => $headers);
	}
	uasort($output, create_function('$e1, $e2', 'return ($e1["format_id"] - $e2["format_id"]);'));

	return ($json ? json_encode($output) : $output);
}
function youtube_search($query, $token = '', $maxResults = 18, $json = false) {
	global $youtube_devkey;

	$query = urlencode(preg_replace('/[\x00-\x1f\x7f]/i', '', $query));
	$token = urlencode(preg_replace('/[\x00-\x1f\x7f]/i', '', $token));

	$content = youtube_curl("https://www.googleapis.com/youtube/v3/search?part=snippet&q={$query}" . ($token ? "&pageToken={$token}" : '' ) . "&type=video&maxResults={$maxResults}&key={$youtube_devkey}");

	return ($json ? $content : @json_decode($content, true));
}
/** Download a video encoded in an available format */
function youtube_dl($vid, $format_id) {
	// Query API for streams
	$output = youtube_streams_info($vid, false);

	// Has errored ?
	if(isset($output['error'])) {
		return $output['error'];
	}

	// Resolve URL for the requested VID and Format
	foreach($output as $o) {
		if($o['format_id'] == $format_id) {
			foreach($o['headers'] as $k => $v) {
				$headers[] = "{$k}: {$v}";
			}
			$name = $o['filename'];
			$url = $o['url'];
			break;
		}
	}
	if(!isset($headers, $name, $url)) {
		return "ERROR: A video has not been found for the requested ID and Format.";
	}
	if(!(strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
		return "ERROR: Invalid URL: {$url}.";
	}

	// Stream context
	$context = stream_context_create(array(
		'http' => array(
			'method' => 'GET',
			'header' => implode("\r\n", $headers),
			'protocol_version' => (1.1)
		),
		'ssl' => array(
			'verify_peer' => false
		)));

	// GET
	$handle = @fopen($url, 'rb', false, $context);

	// Check
	if($handle === false) {
		return "ERROR: Problem reading data from {$url}.";
	}

	// Disable output buffering
	if(ob_get_level()) {
		ob_end_clean();
	}

	// Send header to client browser
	header('Content-Description: File Transfer');
	header('Content-Type: application/x-force-download');
	header("Content-Disposition: attachment; filename=\"{$name}\"");

	// Read and flush
	while(!feof($handle)) {
		// Avoid timeout
		@set_time_limit(30);

		// Read from stream and write in response
		echo fread($handle, 9216);
	}

	// Close
	@fclose($fp);

	// Download complete, prevent unexpected output
	exit();
}

if(!defined('IN_WEBSITE')) {
	// Sanitize input parameters for all functionalities
	$vid = preg_replace('/[^A-Z0-9_-]/i', '', @$_REQUEST['vid']);
	$fid = preg_replace('/[^A-Z0-9_-]/i', '', @$_REQUEST['fid']);
	$q = preg_replace('/[\x00-\x1f\x7f]/i', '', @$_REQUEST['q']);
	$t = preg_replace('/[\x00-\x1f\x7f]/i', '', @$_REQUEST['t']);

	if($vid && $fid) {
		// Stream video file from server to client
		echo youtube_dl($vid, $fid);
		exit();
	}
	if($vid) {
		// Video streams information as JSON string
		echo youtube_streams_info($vid, false, true);
		exit();
	}
	if($q) {
		// Search results as JSON string
		echo youtube_search($q, $t, 18, true);
		exit();
	}
}
?>