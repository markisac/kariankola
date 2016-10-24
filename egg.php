<?php
define('IN_WEBSITE', true);
require_once('api.php');

$vid  = preg_replace('/[\x00-\x1f\x7f]/i', '', @$_REQUEST['vid']);
$info = youtube_video_info($vid);

if(is_array($info)):
?>
	<h1><?php echo htmlspecialchars($info['fulltitle']); ?></h1>
<?php
	if(@$info['thumbnail_url']): ?>
		<img src="<?php echo htmlspecialchars($info['thumbnail_url']); ?>" /><br />
<?php
	endif;

	$streams = youtube_streams_info($info);
	if(isset($streams['error']))
		exit($streams['error']);

    foreach($streams as $s):
    	if($quality = $s['format']): ?>
			<a href="<?php echo htmlspecialchars($s['url']); ?>"><?php echo htmlspecialchars($quality); ?></a><br /><?php
	endif;
    endforeach;
else: ?>
	<b>Invalid vid</b>
<?php
endif;
?>