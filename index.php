<?php



//-----------------------------------------------------------
// CONFIG
//-----------------------------------------------------------

$MUSIC_DIR_PATH				= __DIR__ . "/tracks";	// /home/pi/jkbox/tracks
$TMP_PATH					= __DIR__ . "/tmp";		// /home/pi/jkbox/tmp
$MUSIC_DIR_MAX_MEGABYTES	= 500;
set_time_limit( 60 );		//seconds this script is allowed to run

//-----------------------------------------------------------
//-----------------------------------------------------------





//Parse the json request.
//Every request will always have
//	1. an op
//	2. arbitrary data the op might need
$params = json_decode(file_get_contents('php://input'), true);

//What's the op? We'll return different stuff depending on the value of this.
$op = $params["op"];

//Get any associated data this op might need.
$d = $params["d"];


//Notes:
//	Track names are always encoded, and only ever decoded for display or
//	to send commands to the music player. Currently using base64, but could be whatever.


include_once 'session.php';

if($op == "getState")
{
	$MODEL = array();

	//Volume.
	$output = trim(shell_exec("mpc volume"));
	$output = trim(explode(":", $output)[1]);
	$output = substr($output, 0, -1);	//remove last character "%"
	$MODEL["volume"] = intval($output);

	//Info about the currently playing track.
	$MODEL["current"] = GetCurrentTrackInfo();

	//Playlist.
	$MODEL["playlist"] = GetPlaylistTracks();

	//Recent list.
	$MODEL["recent"] = GetRecentTracks();

	Respond($MODEL);
}

else if($op == "setVolume")
{
    if (!$isAdmin) {
        NotAuthorizedException();
    }
	$volumeVal = intval($d);

	if($volumeVal<0)
		{$volumeVal=0;}
	else if($volumeVal>100)
		{$volumeVal=100;}

	$output = trim(shell_exec("mpc volume " . $volumeVal ));
	Respond($volumeVal);	//just echo it back. todo: parse mpc's output
}

else if($op == "seek")
{
    if (!$isAdmin) {
        NotAuthorizedException();
    }
	$trackName = trim($d[0]);
	$pct = intval( round($d[1] * 100) );

	if($pct<0)
		{$pct=0;}
	else if($pct>100)
		{$pct=100;}

	$currentTrackInfo = GetCurrentTrackInfo();

	if($currentTrackInfo==null)
	{
		//Nothing is currently playing, or other error.
		Respond(null);
	}

	if($currentTrackInfo["name"] != $trackName)
	{
		//Trying to seek on a track different from what's currently playing.
		//So just ignore this request.
		Respond(null);
	}

	//Seek!
	$output = trim(shell_exec("mpc seek " . $pct . "%"));

	//Respond with updated info on the current track.
	Respond( GetCurrentTrackInfo() );
}

else if($op == "selectTrack")
{
    if (!$isAdmin) {
        NotAuthorizedException();
    }
	$trackName = trim($d);
	$trackNameDecoded = base64_decode($trackName);

	//Update this track's timestamp.
	$filepath = GetAbsoluteFilepath( $trackNameDecoded );
	touch( $filepath );

	//Is this track already in the playlist?
	$idx = array_search( $trackName, GetPlaylistTracks() );
	if( is_int($idx) )
	{
		//Play it right now.
		$playListNum = $idx + 1;
		$output = trim(shell_exec("mpc play " . $playListNum));
		Respond("play track from playlist: " . $trackNameDecoded);
	}

	//Is this track in the recent list?
	$idx = array_search( $trackName, GetRecentTracks() );
	if( is_int($idx) )
	{
		//Add it to the playlist.
		$output = trim(shell_exec("mpc add " . escapeshellarg($trackNameDecoded) . ""));
		$output = trim(shell_exec("mpc play"));
		Respond("add to playlist playlist: " . $trackNameDecoded);
	}

	//The requested track wasn't found anywhere. This shouldn't ever happen.
	Respond("track not found: " . $trackNameDecoded);
}

else if($op == "deleteTrack")
{
    if (!$isAdmin) {
        NotAuthorizedException();
    }
	$trackName = trim($d);
	$trackNameDecoded = base64_decode($trackName);

	//Is this track in the playlist?
	$idx = array_search( $trackName, GetPlaylistTracks() );
	if( is_int($idx) )
	{
		//Remove from the playlist.
		$playListNum = $idx + 1;
		$output = trim(shell_exec("mpc del " . $playListNum));
		Respond("remove track from playlist: " . $trackNameDecoded);
	}

	//Is this track in the recent list?
	$idx = array_search( $trackName, GetRecentTracks() );
	if( is_int($idx) )
	{
		//Delete from disk.
		$filepath = GetAbsoluteFilepath($trackNameDecoded);
		unlink($filepath);

		//Update the music library.
		$output = trim(shell_exec("mpc update --wait"));

		Respond("delete track: " . $trackNameDecoded);
	}

	Respond("track not found: " . $trackNameDecoded);
}
else if ($op == "search")
{
    $apiKey = trim(file_get_contents(__DIR__.'/youtube_api_v3'));
    $queryStr = trim($d);
    $link = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'key' => $apiKey,
        'q' => $queryStr,
        'part' => 'snippet',
        'type' => 'video',
    ]);
    $found = json_decode(file_get_contents($link), true);
    Respond($found);
}
else if($op == "fetch")
{
	$queryStr = trim($d);
	$queryStrDecoded = base64_decode($queryStr);

	//Assemble a response containing:
	//	1. The exact query submitted.
	//	2. The name of whatever track we actually found.
	$response = array();
	$response["query"] = $queryStr;
	$response["name"] = "";	//fill this in later

	//Delete old tracks first, to make room for this one.
	PruneOldTracks($MUSIC_DIR_MAX_MEGABYTES);

	//Search for and download the track.
	$debugData;
	$trackNameDecoded = Fetch($queryStrDecoded, $debugData);
	$response["debug"] = $debugData;
	if(!$trackNameDecoded)
		{Respond($response);}

	//Update mpd's db.
	$output = shell_exec( "mpc update --wait" );

	//If not currently playing anything, clear the playlist.
	$output = trim(shell_exec( "mpc current" ));
	if(!$output)
	{
		$output = shell_exec( "mpc clear" );
	}

    //Add to playlist.
	$output = shell_exec( "mpc add " . escapeshellarg($trackNameDecoded) );

	//Ensure consume mode is on. This removes tracks from the playlist
	//after they've been played.
	$output = shell_exec( "mpc consume on" );

	//Play!
	$output = shell_exec( "mpc play" );

	//Update the response.
	$response["name"] = base64_encode($trackNameDecoded);

	Respond($response);
}

else if($op == false)
{
	//No op was provided.
	//Flow down to display HTML.
    header("Access-Control-Allow-Origin: *");
}

else
{
	//An op was provided, but it's not supported.
	Respond("invalid op");
}



//---------------------------------------------
// Utilities.
//---------------------------------------------

function Respond($data)
{
	echo( json_encode($data) );
	exit();
}

function NotAuthorizedException()
{
    header('HTTP/1.0 403 Forbidden');
    die('You are not allowed to access this file.');
}

function GetPlaylistTracks()
{
	$tracks = array();
	$ploutput = trim(shell_exec("mpc playlist"));
	$lines = explode("\n", $ploutput);
	foreach($lines as &$line)
	{
		$line = trim($line);
		if($line)
		{
			$tracks[] = base64_encode($line);
		}
	}
	return $tracks;
}

function GetRecentTracks()
{
	global $MUSIC_DIR_PATH;

	$tracks = array();
	$rloutput = trim(shell_exec("ls -t " . $MUSIC_DIR_PATH . "/"));
	$lines = explode("\n", $rloutput);
	foreach($lines as &$line)
	{
		$line = trim($line);
		if($line)
		{
			$tracks[] = base64_encode($line);
		}
	}
	return $tracks;
}

function GetCurrentTrackInfo()
{
	$info = array();
	$output = trim(shell_exec("mpc -v"));

	//---------------------------------------------------------------------
	//When a song is playing, output looks like this:
	//Bing_Crosby_-_Tobermory_Bay.m4a
	//[playing] #1/1   0:37/3:21 (18%)
	//volume:  5%   repeat: off   random: off   single: off   consume: on

	//When no song is playing, output looks like this:
	//volume:  5%   repeat: off   random: off   single: off   consume: on
	//---------------------------------------------------------------------


	$lines = explode("\n", $output);

	//Always remove the last line.
	array_pop( $lines );

	//If number of lines is zero, nothing is playing.
	if(count($lines) == 0)
		{return null;}

	//If number of lines is anything other than two, this is an error.
	if(count($lines) != 2)
		{return null;}

	//Exactly two lines remain.

	//First line is always the track name.
	$info["name"] = base64_encode($lines[0]);

	//Second line contains a bunch of stuff, but we care only about the last two.
	$parts = explode(" ", trim($lines[1]));
	$timeStr = $parts[ count($parts)-2 ];
	$parts = explode("/", $timeStr);

	$elapsedStr = $parts[0];
	$totalStr = $parts[1];

	$info["elapsedSecs"] = TimeStrToSeconds($elapsedStr);
	$info["totalSecs"] = TimeStrToSeconds($totalStr);

	return $info;
}

function Fetch($searchStr, &$debugData)
{
	global $MUSIC_DIR_PATH;
	global $TMP_PATH;

	//Initiate the debug data container as an array.
	$debugData = array();

	//This will be the return value.
	$trackNameDecoded = "";

	//Generate a unique tmp dir and ensure it exists.
	//To get a unique name, we'll build up some overkill entropy
	//to give to md5().
	$entropyStr =	$searchStr
				. " " . microtime()
				. " " . random_int(PHP_INT_MIN, PHP_INT_MAX)
				. " " . $_SERVER['REMOTE_ADDR']
				. " " . $_SERVER['REMOTE_PORT'];
	$uniqueTmpPath = $TMP_PATH . "/" . md5($entropyStr);
	mkdir($uniqueTmpPath, $mode = 0777, $recursive = true );

	$debugData["entropyStr"] = $entropyStr;
	$debugData["uniqueTmpPath"] = $uniqueTmpPath;

	//Ensure the tmp dir and all dirs inside of it are accessible to all users.
	exec( "find " . escapeshellarg($TMP_PATH) . " -type d -exec chmod 0777 {} +" );

	/*
	//Update 1: The youtube-dl tool sometimes needs to cache stuff, but it fails
	//to write its cache as apache user "www-data", so we need to
	//provide an actual writable directory.
	$ytdlCachePath = $TMP_PATH . "/cache";
	if( !is_dir($ytdlCachePath) )
		{mkdir($ytdlCachePath, $mode = 0777, $recursive = true );}
	chmod($ytdlCachePath, 0777);
	$debugData["ytdlCachePath"] = $ytdlCachePath;
	// youtube-dl flag: "--cache-dir $ytdlCachePath"

	//Update 2: User youtube-dl's --no-cache-dir flag instead. This will make
	//each song lookup slightly slower, but overall more reliable.
	*/

	//Download audio of first search result from youtube.
	//I was going to add more sources, but this seems totally sufficient.
	$cmd =	"youtube-dl"
			. " --verbose -f 'bestaudio'"
			. " " . escapeshellarg($searchStr)
			. " --no-playlist --ignore-errors --no-color --max-downloads 1"
			. " --no-call-home --no-progress --restrict-filenames"
			. " --no-cache-dir"
			. " -o " . escapeshellarg($uniqueTmpPath . "/%(title)s.%(ext)s")
			. " 2>&1";

	exec($cmd, $outputLines, $returnValue);

	$debugData["ytdl cmd"] = $cmd;
	$debugData["ytdl output"] = $outputLines;
	$debugData["ytdl returnValue"] = $returnValue;

	if($returnValue != 0)
		{goto cleanEnd;}


	//Search the output for the destination file.
	$needle = "[download] Destination:";
	$downloadPath = "";
	foreach($outputLines as $line)
	{
		if( strpos($line, $needle) === false )
			{continue;}	//not this line. keep looking

		//Found it.
		$downloadPath = trim( substr($line, strlen($needle)) );
		break;
	}

	$debugData["downloadPath"] = $downloadPath;

	//Verify that the download path begins with the unique dir.
	//For example:  /home/pi/jkbox/tmp/blahblahblah
	if( strpos($downloadPath, $uniqueTmpPath) !== 0 )
	{
		//Something's wrong. Fail out.
		$downloadPath = "";
		goto cleanEnd;
	}

	//Verify the download path is an actual file.
	if( !is_file($downloadPath) )
	{
		//Something's wrong. Fail out.
		$downloadPath = "";
		goto cleanEnd;
	}

	//Set the timestamp to right now.
	touch($downloadPath);

	//Allow anyone to access it.
	chmod($downloadPath, 0666);

	//Generate the final file path.
	$trackNameDecoded = basename($downloadPath);
	$dstPath = $MUSIC_DIR_PATH . "/" . $trackNameDecoded;
	$debugData["dstPath"] = $dstPath;

	//If a file with the same name already exists, delete it.
	if( file_exists($dstPath) )
	{
		unlink($dstPath);
	}

	//Move it to the music dir.
	rename($downloadPath, $dstPath);

	//Clean up and return.
	cleanEnd:	////////////

	//Remove the unique tmp dir.
	exec( "rm -rf " . escapeshellarg($uniqueTmpPath) );

	/*
	//It's possible that youtube-dl wrote stuff to the cache dir.
	//Change the permissions there to ensure our "pi" user has access.
	exec( "find " . escapeshellarg($ytdlCachePath) . " -type d -exec chmod 0777 {} +" );
	exec( "find " . escapeshellarg($ytdlCachePath) . " -type f -exec chmod 0666 {} +" );
	*/

	//Return the track name.
	return $trackNameDecoded;
}

function TimeStrToSeconds($timeStr)
{
	//Input is time strings like "0:01" or "01:24:59"
	//Output is integer seconds.

	$parts = explode(":", $timeStr);
	if(count($parts)==3)
	{
		//Good! We want 3 parts.
	}
	else if(count($parts)==2)
	{
		//Stick some hours on the front.
		$timeStr = "0:" . $timeStr;
	}
	else
	{
		//Unknown.
		return 0;
	}

	sscanf($timeStr, "%d:%d:%d", $hours, $minutes, $seconds);
	$s = $hours * 3600 + $minutes * 60 + $seconds;
	return intval($s);
}

function GetAbsoluteFilepath($trackNameDecoded)
{
	global $MUSIC_DIR_PATH;
	return $MUSIC_DIR_PATH . "/" . $trackNameDecoded;
}

function PruneOldTracks($maxTotalMegabytes)
{
	//Never delete more than this many files, even if we're still
	//not below the max megabyte limit.
	$deleteCountMax = 3;

	//As long as the music library is too large, delete the oldest track.
	$deleteCount = 0;
	while( GetMusicDirSizeMegabytes() > $maxTotalMegabytes )
	{
		$oldestFilePath = GetOldestTrackFilePath();
		if( is_file($oldestFilePath) == false )
			{break;}

		unlink( $oldestFilePath );
		$deleteCount++;
		if( $deleteCount >= $deleteCountMax )
			{break;}
	}
	return $deleteCount;
}

function GetMusicDirSizeMegabytes()
{
	global $MUSIC_DIR_PATH;
	$output = trim(shell_exec( "du -m " . escapeshellarg($MUSIC_DIR_PATH) ));
	$mb = trim( explode("\t", $output)[0] );
	return intval($mb);
}

function GetOldestTrackFilePath()
{
	global $MUSIC_DIR_PATH;
	$output = trim( shell_exec( "ls -t " . escapeshellarg($MUSIC_DIR_PATH) . " | tail -1" ) );
	if($output=="")
		{return "";}
	return GetAbsoluteFilepath($output);
}



?>








<!DOCTYPE html>
<html>

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>JK Box</title>
	</head>

	<script>

		MODEL = {};
		MODEL["volume"] = 0;
		MODEL["playlist"] = [];
		MODEL["current"] = null;
		MODEL["recent"] = [];

		QUERIES = {};

		TIMESTAMP_CURRENT_START = -1;

		window.onload = function()
		{
			Refresh();

			setInterval(PeriodicRefreshCurrentTrack, 1000);
		}

		function isAdmin()
        {
            return <?php echo($isAdmin ? 'true' : 'false'); ?>;
        }

		function Refresh()
		{
			Cmd("getState", null);
		}

		function PeriodicRefreshCurrentTrack()
		{
			if(MODEL["current"]==null)
				{return;}


			//Update the labels.
			document.querySelector('#currentElapsedLabel').value = SecondsToHhMmSs(MODEL["current"]["elapsedSecs"]);
			document.querySelector('#currentTotalLabel').value = SecondsToHhMmSs(MODEL["current"]["totalSecs"]);


			//This will be negative until server responds.
			if(TIMESTAMP_CURRENT_START<0)
				{return;}


			//Keep the elapsed seconds ticking up.
			MODEL["current"]["elapsedSecs"] = (Date.now() - TIMESTAMP_CURRENT_START) / 1000;


			//Update the slider.
			document.querySelector('#seekControl').value = MODEL["current"]["elapsedSecs"];
			document.querySelector('#seekControl').max = MODEL["current"]["totalSecs"];


			//Is the track over? Check if elapsed is longer than total.
			if( MODEL["current"]["elapsedSecs"] > MODEL["current"]["totalSecs"] )
			{
				//Track is done! Refresh.
				Refresh();
			}
		}

		function GetTrackIndex(trackName, trackList)
		{
			for(let i=0; i < trackList.length; i++)
			{
				if(trackList[i]==trackName)
				{
					return i;
				}
			}
			return -1;
		}

		function OnSearchQuery()
        {
            var queryStr = document.querySelector('#queryInput').value.trim();
            if (queryStr.startsWith('http')) {
                OnSubmitQuery(queryStr);
            } else {
                document.querySelector('#queryInput').value = "";
                if(!queryStr)
                {return;}
                //Replace any curly "smart" quotes with plain straight quotes.
                queryStr = queryStr
                    .replace(/[\u2018\u2019]/g, "'")
                    .replace(/[\u201C\u201D]/g, '"');
                //Replace any other non printable ascii chars with spaces.
                queryStr = queryStr.replace(/[^ -~]+/g, " ");
                Cmd("search", queryStr);
            }
        }

		function OnSubmitQuery(queryStr)
		{
			document.querySelector('#queryInput').value = "";
			document.querySelector('#queryResult').innerHTML = '';
			if(!queryStr)
				{return;}

			//Replace any curly "smart" quotes with plain straight quotes.
			queryStr = queryStr
						.replace(/[\u2018\u2019]/g, "'")
						.replace(/[\u201C\u201D]/g, '"');

			//Replace any other non printable ascii chars with spaces.
			queryStr = queryStr.replace(/[^ -~]+/g, " ");

			//Encode and add to list of queries.
			queryStr = Base64Encode(queryStr);
			QUERIES[queryStr] = {};

			OnModelUpdate();

			Cmd("fetch", queryStr);
		}

		function OnSetVolume(value)
		{
			MODEL["volume"] = value;
			document.querySelector('#volumeControl').value = MODEL["volume"];
			document.querySelector('#volumeLabel').value = MODEL["volume"];

			Cmd("setVolume", value);
		}

		function OnSelectTrack(trackName)
		{
			trackNameDecoded = Base64Decode(trackName);
			Cmd("selectTrack", trackName);
		}

		function OnSeekScrub(seconds)
		{
			//This event fires off like crazy as the slider
			//moves around, so don't try and do too much here.
			//Wait for the commit to actually make a request
			//to the server, for example.

			if(MODEL["current"]==null)
				{return;}

			//Update the model.
			MODEL["current"]["elapsedSecs"] = seconds;
			TIMESTAMP_CURRENT_START = -1; //pause until we hear back

			PeriodicRefreshCurrentTrack();
		}

		function OnSeekCommit(seconds)
		{
			if(MODEL["current"]==null)
				{return;}

			//Do the scrub behavior first.
			OnSeekScrub(seconds);

			//Update the server.
			var pct = seconds / MODEL["current"]["totalSecs"];
			Cmd("seek", [MODEL["current"]["name"], pct] );
		}

		function OnDeleteTrack(trackName)
		{
			trackNameDecoded = Base64Decode(trackName);
			Cmd("deleteTrack", trackName);
		}

		function OnModelUpdate()
		{

			//Fill in the volumes.
			document.querySelector('#volumeControl').value = MODEL["volume"];
			document.querySelector('#volumeLabel').value = MODEL["volume"];

			//Fill in the playlist.
			elem = document.querySelector('#playlist');
			elem.innerHTML = "";
			for(let i=0; i < MODEL["playlist"].length; i++)
			{
				trackName = MODEL["playlist"][i];

				elem.innerHTML = GenerateTrackDiv(trackName, i) + elem.innerHTML;
			}

			//Fill in pending queries.
			for(var queryStr in QUERIES)
			{
				labelEncoded = Base64Encode( "acquiring: " + Base64Decode(queryStr) );
				elem.innerHTML = GenerateTrackDiv(labelEncoded, -1) + elem.innerHTML;
			}

			//Hide the playlist?
			hide = MODEL["playlist"].length==0 && Object.entries(QUERIES).length===0;
			Hide("playlistContainer", hide);


			//Current track stuff updates in a seperate function.
			OnModelUpdate_Current();


			//Fill in recent list.
			elem = document.querySelector('#recent');
			elem.innerHTML = "";
			for(let i=0; i < MODEL["recent"].length; i++)
			{
				trackName = MODEL["recent"][i];

				//Is this track in the playlist? If so, don't add it again here.
				if( GetTrackIndex(trackName, MODEL["playlist"]) >= 0 )
					{continue;}

				//Add to the list.
				elem.innerHTML = elem.innerHTML + GenerateTrackDiv(trackName, -1);
			}

			//Hide the recent list?
			hide = MODEL["recent"].length==0;
			Hide("recentContainer", hide);


			//Adjust visibility.
			Hide("loaderContainer", true);
			Hide("volumeContainer", false);
			Hide("searchContainer", false);
		}

		function OnModelUpdate_Current()
		{
			//If a track is currently playing, record its starting timestamp.
			if(MODEL["current"])
			{
				TIMESTAMP_CURRENT_START = Date.now() - MODEL["current"]["elapsedSecs"] * 1000;

				//Run the periodic refresher immediately.
				PeriodicRefreshCurrentTrack();
			}
			else
			{
				TIMESTAMP_CURRENT_START = -1;
			}
		}

		function UpdateSearch(data)
        {
            var innerHtml = '';
            data.items.forEach((item) => {
                innerHtml += '<button onclick="OnSubmitQuery(\''+item.id.videoId+'\')" style="margin-right: 5px;" class="searchInput">'+item.snippet.title+'</button>';
            });
            const queryResult = document.querySelector('#queryResult');
            queryResult.innerHTML = innerHtml;
        }

		function Cmd(op, data)
		{

			fetch(".", {
				method: "post",
				body: JSON.stringify({ "op": op, "d": data })
				})

				.then(response => response.json())
				.then(data => {

					if(op=="getState")
					{
						MODEL = data;
						OnModelUpdate();
					}

					else if(op=="search")
					{
					    UpdateSearch(data);
                    }

					else if(op=="selectTrack")
					{
						Refresh();
					}

					else if(op=="seek")
					{
						if(!data)
						{
							//Track is no longer playing.
							MODEL["current"] = null;
							Refresh();
							return;
						}

						if(MODEL["current"]["name"] != data["name"])
						{
							//A different track is now playing.
							MODEL["current"] = null;
							Refresh();
							return;
						}

						//Refresh only current track stuff.
						MODEL["current"] = data;
						OnModelUpdate_Current();

					}

					else if(op=="deleteTrack")
					{
						Refresh();
					}

					else if(op=="fetch")
					{
						//Remove this query.
						var queryStr = data["query"];
						delete QUERIES[queryStr];

						var trackName = data["name"];

						//Refresh!
						Refresh();
					}

				})

				.catch(error => console.error(error))
		}


		//HTML UTILS

		function Hide(elemId, hide)
		{
			if(hide)
			{
				document.querySelector('#' + elemId).style.display = "none";
			}
			else
			{
				document.querySelector('#' + elemId).style.display = "block";
			}
		}

		function GenerateTrackDiv(trackNameEnc, trackIndex = -1)
		{
			var html = "";
			var isCurrent = false;

			classStr = "track";
			if(MODEL["current"])
			{
				if(trackNameEnc == MODEL["current"]["name"])
				{
					isCurrent = true;
					classStr += " trackCurrent";
				}
			}
			if(trackIndex < 0)
			{
				classStr += " trackQuery";
			}


			html += '<div>';
			html += '<table style="width:100%">';
			html += 	'<tr>';
			html += 		'<td>';


				//Label div.
				html += '<div class="' + classStr + '"';

				if (!isAdmin())
                {
                    html += ' style="cursor: default;"';
                }

				if(!isCurrent && isAdmin())
				{
					html += ' onclick="OnSelectTrack(' + "\'" + trackNameEnc + "\'" + ')';
				}

				html += '">';
				if( trackIndex >= 0 )
				{
					html += (trackIndex+1) + ' - ';
				}
				html += GetTrackNameForDisplay(trackNameEnc);


				if(isCurrent)
				{
					html += '<div>';

					var totalSecs = 0;
					var elapsedSecs = 0;

					html += '<div>';
					html += '<input id="seekControl" type="range" min="0" max="'
							+ totalSecs + '" value="' + elapsedSecs
							+ '" step="1" oninput="OnSeekScrub(value)" onmouseup="OnSeekCommit(value)" ontouchend="OnSeekCommit(value)" style="width:100%; margin-top:15px;"';
					if (!isAdmin()) {
					    html += ' disabled';
                    }
					html += ' />';
					html += '</div>';

					html += '<div style="text-align:right;">';
					html += '<output for="seekControl" id="currentElapsedLabel">' + SecondsToHhMmSs(elapsedSecs) + '</output> / ';
					html += '<output for="seekControl" id="currentTotalLabel">' + SecondsToHhMmSs(totalSecs) + '</output>';
					html += '</div>';



					html += '</div>';
				}

				html += '</div>';


			html += 		'</td>';

			/*
			html += 		'<td style="width:60px">';

				//Download div.
				html += '<div style="text-align: center;" class="trackOption">';
				html += '<a href="./tracks/' + Base64Decode(trackNameEnc) + '">dl</a>';
				html += '</div>';

			html += 		'</td>';
			*/
            if (isAdmin()) {
                html += 		'<td style="width:60px">';

                    //Delete div.
                    html += '<div style="text-align: center;" class="trackOption"';
                    html += ' onclick="OnDeleteTrack(' + "\'" + trackNameEnc + "\'" + ')';
                    html += '">';
                    html += 'x';
                    html += '</div>';

                html += 		'</td>';
            }

			html += 	'</tr>';
			html += '</table>';



			html += '</div>';

			return html;
		}

		function SecondsToHhMmSs(secs)
		{
			var sec_num = parseInt(secs, 10)
			var hours   = Math.floor(sec_num / 3600)
			var minutes = Math.floor(sec_num / 60) % 60
			var seconds = sec_num % 60

			return [hours,minutes,seconds]
			.map(v => v < 10 ? "0" + v : v)
			.filter((v,i) => v !== "00" || i > 0)
			.join(":")
		}

		function RemoveFilenameExtension(filename)
		{
			return filename.substr(0, filename.lastIndexOf('.')) || filename;
		}

		function GetTrackNameForDisplay(trackNameEncoded)
		{
			name = Base64Decode(trackNameEncoded);
			name = RemoveFilenameExtension(name);
			name = name.replace(/_/g, " ")
			return name;
		}

		function Base64Encode(str)
		{
			out = "";
			try
			{
				out = btoa(str);
			}
			catch (e)
			{
				alert("Base64Encode(): " + e);
			}
			return out;
		}

		function Base64Decode(str)
		{
			out = "";
			try
			{
				out = atob(str);
			}
			catch (e)
			{
				alert("Base64Decode(): " + e);
			}
			return out;
		}

	</script>







	<style>
	<!--
		* {
				box-sizing: border-box;
			}

		body {
				background-color: #000000;
				font-family: Tahoma, Verdana, Segoe, sans-serif;
				font-size: 20px;
				color: #eeeeee;
				margin: 0px;
			}

		input[type=range] {
				background: transparent;
			}

		input[type=range]:focus {
				outline: none;
			}

		input[type=range]::-moz-range-track {
				width: 100%;
				height: 5.0px;
				background: #666666;
				border-radius: 1.0px;
				border: 0px;
				outline: none;
			}

		input[type=range]::-moz-range-thumb {
				height: 20px;
				width: 20px;
				border-radius: 5px;
				background: #bbbbbb;
				cursor: pointer;
				border: 0px;
				outline: none;
			}

		.searchInput {
				font-size: 25px;
				border: 0px;
				padding: 2px 10px 2px;
				margin: 5px 0px 0px;
				border-radius: 5px;
                cursor: pointer;
				color: #eeeeee;
				background-color: #555555;
			}

		.gridcontainer {

				display: grid;

				grid-gap: 1em;

				width: 100%;

				margin-left: auto;
				margin-right: auto;
				margin-top: 0px;
				margin-bottom: 0px;

				padding: 1em;

				background-color: #191919;
			}

		.gridcontainer > div {
				display: block;
				padding: 1em;

				color: #aaaaaa;
				background-color: #222222;
		}

		.track {
				display: block;
				margin-left: 0px;
				margin-top: 15px;
				padding: 10px;
				border-radius: 5px;

				color: #aaaaaa;
				background-color: #333333;

				cursor: pointer;
		}

		.trackCurrent {
				font-style: italic;
				font-weight: bold;
				color: #dddddd;
		}

		.trackQuery {
				font-style: italic;
				color: #aaaaaa;
		}

		.trackOption {
				display: block;
				margin-left: 10px;
				margin-top: 15px;
				padding: 10px;
				border-radius: 5px;
				background-color: #333333;

				font-style: italic;
				color: #aaaaaa;

				cursor: pointer;
		}

		.trackOption a {
				color: #aaaaaa;
				text-decoration: none;
		}


		a {
				color:#c00;
				text-decoration: underline;
				background-color:transparent;
		}

		a:hover {
				color:#c77;
		}



	-->
	</style>






	<body>





	<div class="gridcontainer">


		<!-- loader -->
		<div id="loaderContainer" style="text-align: center;">
			loading...
		</div>

        <?php if (!$isAdmin) { ?>
            <div style="display: none">
        <?php } ?>
		<!-- volume control -->
		<div id="volumeContainer" style="display:none;">

			<div style="text-align: center;">
			<input id="volumeControl" type="range" min="0" max="100" value="0" step="1" oninput="OnSetVolume(value)" style="width:80%;" />
			</div>

			<div style="text-align: center;">
			<output for="volumeControl" id="volumeLabel">0</output>
			</div>

		</div>
        <?php if (!$isAdmin) { ?>
            </div>
        <?php } ?>

		<!-- search box -->
		<div id="searchContainer" style="text-align: center; display:none;">
			<form action="#" onsubmit="OnSearchQuery(); return false;">
				<input id="queryInput" type="text" value="" class="searchInput" style="width:65%;"/>
				<input type="submit" value="Search Youtube" class="searchInput" />
			</form>
		</div>

        <div id="queryResult">

        </div>


		<!-- playlist -->
		<div id="playlistContainer" style="display:none;">
			<div>
				Playlist
			</div>
			<div id="playlist">
			</div>
		</div>


		<!-- recent tracks -->
		<div id="recentContainer" style="display:none;">
			<div>
				Recent
			</div>
			<div id="recent">
			</div>
		</div>


		<!-- footer -->
		<div id="footerContainer" style="text-align: center; font-size: 12px;">
			Weltistrasse 34 | <a href="login.php">Admin Login</a>
		</div>

	</div>


	</body>
    <?php
    if (isset($_GET['dashboard']) && $_GET['dashboard']) {
    ?>
    <script>
        setTimeout(() => {
            document.getElementById("playlistContainer")
                .scrollIntoView({
                    behavior: "smooth",
                    block: "start",
                    inline: "nearest"
                });
            }, 1000);
    </script>
    <?php
    }
    ?>


</html>
