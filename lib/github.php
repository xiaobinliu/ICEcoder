<?php
include("headers.php");
include("settings.php");
$t = $text['github'];

// SSL check, as everything is over https
$wrappers = stream_get_wrappers();
$sslAvail = true;
if (!extension_loaded('openssl') || !in_array('https', $wrappers)) {
	$sslAvail = false;
	echo "<script>top.ICEcoder.message('".$t['Sorry, you do...']."');top.ICEcoder.showHide('hide',top.get('loadingMask'));</script>";
	die();
}

// If we have an action to perform
if (!$demoMode && isset($_SESSION['loggedIn']) && $_SESSION['loggedIn'] && isset($_GET['action']) && $sslAvail) {

	// ====
	// AUTH
	// ====
	if ($_GET['action']=="auth") {
		$_SESSION['githubAuthToken'] = xssClean($_GET['token'],"html");
		echo '<!DOCTYPE html>
			<html>
			<head>
			<script src="base64.js"></script>
			<script src="github.js"></script>
			</body>
			<script>
			top.ICEcoder.githubAuthTokenSet = true;
			goNext = "'.xssClean($_GET['goNext'],"html").'";
			if (goNext=="showManager") {
				top.ICEcoder.githubManager();
			}
			if (goNext=="loadFiles") {
				top.ICEcoder.githubDiffToggle();
			}
			</script>
			</body>
			</html>';
	}

	// =====
	// CLONE
	// =====
	if ($_GET['action']=="clone") {

		$iceGithubLocalPaths = $ICEcoder["githubLocalPaths"];
		$iceGithubRemotePaths = $ICEcoder["githubRemotePaths"];
		$pathPos = array_search($iceRoot,$iceGithubLocalPaths);
		if ($pathPos !== false) {

			// USE: https://github.com/mattpass/ICEcoder/zipball/master
			// Store the plugin zip to the tmp dir
			$target = $docRoot.$iceGithubLocalPaths[$pathPos]."/";
			$zipURL = $iceGithubRemotePaths[$pathPos].'/zipball/master';
	    		$zipFile = "../tmp/".basename($zipURL);

			if (ini_get('allow_url_fopen')) {
				$fileData = file_get_contents($zipURL, false, $context);
			} elseif (function_exists('curl_init')) {
    				$client = curl_init($zipURL);
				curl_setopt($client, CURLOPT_SSL_VERIFYPEER, false);
		    		curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);  //fixed this line
				$fileData = curl_exec($client);
			}
			file_put_contents($zipFile, $fileData);

			// Now unpack the zip
			$zip = new ZipArchive;
			$zip->open($zipFile);

			// Create all files & dirs, in 1kb chunks
			for($i=0; $i<$zip->numFiles; $i++) {

				$name = $zip->getNameIndex($i);
				if ($i==0) {
					$dirName = $name;
				} else {
					$tgtName = str_replace($dirName,"",$name);
					// Determine output filename
					$file = $target.$tgtName;

					// Create the directories if necessary
					$dir = dirname($file);
					if (!is_dir($dir)) mkdir($dir, 0777, true);

					// Read from zip and write to disk
					$fpr = $zip->getStream($name);
					if (!is_dir($file)) {
						$fpw = fopen($file, 'w');
						while ($data = fread($fpr, 1024)) {
							fwrite($fpw, $data);
						}
						fclose($fpw);
					}
					fclose($fpr);
				}
			}
			$zip->close();

			// Remove the tmp zip file
			unlink($zipFile);

			// Refresh the file manager
			echo "<script>top.ICEcoder.refreshFileManager();</script>";

		}

	}

	// ======
	// COMMIT
	// ======
	if ($_GET['action']=="commit") {
	?>
		<!DOCTYPE html>

		<html onContextMenu="return false">
		<head>
		<title>ICEcoder <?php echo $ICEcoder["versionNo"];?> GitHub commit files</title>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
		<meta name="robots" content="noindex, nofollow">
		<script src="base64.js"></script>
		<script src="github.js"></script>
		<link rel="stylesheet" type="text/css" href="github.css">
		</head>

		<body class="githubAction">

		<h1><?php
		$action = xssClean($_GET['action'],"html");
		echo $action == "commit" ? "Commit files" : "Pull files"; ?></h1>

		<form name="commitDetails">
			Title:<br><input type="text" name="commitTitle" id="commitTitle" value="" style="width: 300px; margin: 5px 0 15px 0"><br>
			Message:<br><textarea name="commitMessage" id="commitMessage" style="width: 300px; height: 118px; margin: 5px 0 15px 0"></textarea>
		</form>

		<div style="display: inline-block; padding: 5px; background: #2187e7; color: #fff; font-size: 12px; cursor: pointer" onclick="top.ICEcoder.showHide('show',top.get('loadingMask'));commitFiles()">Commit</div>

		<br><br>

		<?php
		// Get file contents for selected files
		$selectedFiles = xssClean($_GET['selectedFiles'],"html");
		$selectedFiles = explode(";",$selectedFiles);

		for ($i=0; $i<count($selectedFiles); $i++) {
			// Replace pipes with slashes
			$file = str_replace("|","/",$selectedFiles[$i]);

			// Trim any +'s or spaces from the end of file and clear any ../'s
			$file = str_replace("../","",rtrim(rtrim($file,'+'),' '));

			// Make $file a full path
			if (strpos($file,$docRoot)===false) {$file=str_replace("|","/",$docRoot.$iceRoot.$file);};

			if (file_exists($file)) {
				$loadedFile = toUTF8noBOM(file_get_contents($file,false,$context),true);
				echo '<textarea name="loadedFile'.$i.'" id="loadedFile'.$i.'" style="display: none">'.str_replace("</textarea>","<ICEcoder:/:textarea>",str_replace("&","&amp;",$loadedFile)).'</textarea><br><br>'.PHP_EOL.PHP_EOL;
			}
		}
		?>

		<script>
		// Start our github object
		var github = new Github({token: "<?php echo $_SESSION['githubAuthToken'];?>", auth: "oauth"});

		committingFiles = ['<?php 
			$cF = implode("','", $selectedFiles);
			echo $cF;
			?>'];
		seqFile = 0;
		commitFiles = function() {
			// Commit our files one after another
			var repo = github.getRepo(top.repo.split("/")[0], top.repo.split("/")[1]);
			repo.write(
				'master', 
				committingFiles[seqFile].substr(1).replace(/\|/g,"/"),
				document.getElementById('loadedFile'+seqFile).value,
				document.getElementById('commitTitle').value+'\n\n'+document.getElementById('commitMessage').value,
				function(err) {
					if (!err) {
						var locSplit = committingFiles[seqFile].lastIndexOf("|");
						var location = committingFiles[seqFile].substr(0,locSplit+1);
						var file = committingFiles[seqFile].substr(locSplit+1);

						// Splice from diff or deleted paths
						if (top.diffPaths.indexOf(committingFiles[seqFile].substr(1).replace(/\|/g,"/")) > -1) {
							top.diffPaths.splice(top.diffPaths.indexOf(committingFiles[seqFile].substr(1).replace(/\|/g,"/")),1);
						}
						if (top.deletedPaths.indexOf(committingFiles[seqFile].substr(1).replace(/\|/g,"/")) > -1) {
							top.deletedPaths.splice(top.deletedPaths.indexOf(committingFiles[seqFile].substr(1).replace(/\|/g,"/")),1);
						}
						
						// Then deselect and remove from file manager
						top.ICEcoder.thisFileFolderLink = committingFiles[seqFile];
						top.ICEcoder.selectFileFolder(false,'ctrlSim');
						top.ICEcoder.updateFileManagerList("delete",location,file);
						seqFile++;
						// If there's another file to do
						if (top.ICEcoder.selectedFiles.length > 0) {
							commitFiles();
						} else {
							top.ICEcoder.showHide('hide',top.get('loadingMask'));
							top.ICEcoder.showHide('hide',top.get('blackMask'));
							if (top.diffPaths.length == 0 && top.deletedPaths.length == 0) {
								top.ICEcoder.message('All done, switching modes');
								top.ICEcoder.githubDiffToggle();
							}
						}
					} else {
						top.ICEcoder.message('There was an error with committing.\n\nSee dev tools console for details.');
						console.log(err);
					}
				}
			);

		}
		</script>

		</body>

		</html>
	<?php
	}

	// ====
	// PULL
	// ====
	if ($_GET['action']=="pull") {
	?>
	<script>
		top.ICEcoder.showHide('hide',top.get('blackMask'));
		top.ICEcoder.message("Pull actions not yet available. Will be in ICEcoder v4.3");
	</script>
	<?php
	}

}
?>
