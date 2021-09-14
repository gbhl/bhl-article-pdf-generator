<?php
$bhl_dbh = null;
include ('Archive/Tar.php');

/* ********************************************
	Libraries for generating article PDFs for BHL 

	Created: 11 Nob 2020
	By: Joel Richard
	******************************************** 
*/

/* function download_progress($curl_handle, $dl_max, $dl, $ul_max, $ul){
	$filename = basename(curl_getinfo($curl_handle, CURLINFO_EFFECTIVE_URL));
	
	if ($dl_max > 0) {
		print chr(13)."Downloading $filename (".round($dl / $dl_max * 100)."%)...";
	} else {
		print chr(13)."Initializing...";
	}
	ob_flush();
	flush();
} */

/*
  GET PDF
 	Download a PDF from the Internet Archive
*/
function get_pdf($identifier, $override = false) {
	global $config;

	$filename = $identifier.'.pdf';
	$path = $config['paths']['cache_pdf'].'/'.$filename;
	if (!file_exists($path) || $override) {
		$url = 'https://archive.org/download/'.$identifier.'/'.$identifier.'.pdf';
		// ob_start();

		// ob_flush();
		// flush();

		// $ch = curl_init();
		// $fp = fopen($path, 'w+'); 
		// curl_setopt($ch, CURLOPT_FILE, $fp); 
		// curl_setopt($ch, CURLOPT_URL, $url);
		// curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
		// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// curl_setopt($ch, CURLOPT_FAILONERROR, true); 
		// curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'download_progress');
		// curl_setopt($ch, CURLOPT_NOPROGRESS, false); 
		// curl_setopt($ch, CURLOPT_HEADER, 0);
		// curl_setopt($ch, CURLOPT_USERAGENT, 'BHL PDF Generator (https://biodiversitylibrary.org)');
		// $html = curl_exec($ch);
		// curl_close($ch);

		// print "Done\n";
		// ob_flush();
		// flush();
		// fclose($fp); 

		file_put_contents($path, file_get_contents($url));
	}
	return $path;
}

/*
  GET DJVU XML
 	Download a PDF from the Internet Archive
*/
function get_djvu($identifier, $override = false) {
	global $config;
  
	$letter = substr($identifier,0,1);
	$filename = $identifier.'_djvu.xml';
	$cache_path = $config['paths']['cache_djvu'].'/'.$filename;
	if (!file_exists($cache_path) || $override) {
		$djvu = $config['local_source_path']."/{$letter}/{$identifier}/{$identifier}_djvu.xml";
		// Do we have the file locally?
		if (file_exists($djvu)) {
			// Do we have it on the Isilon?
			copy($djvu, $cache_path);
		} else {
			// Get it from the internet archive
			$url = 'https://archive.org/download/'.$identifier.'/'.$filename;
			file_put_contents($cache_path, file_get_contents($url));			
		}
	}
	return $cache_path;
}

/*
  GET PAGE IMAGES
 	Given an array of Page IDs, download the images from IA
	(future versions of this will grab the image from our TAR file)
*/
function get_page_images($pages, $identifier, $override = false) {
	global $config;

	$letter = substr($identifier,0,1);
	$jp2_zip = $config['local_source_path']."/{$letter}/{$identifier}/{$identifier}_jp2.zip";
	$jp2_tar = $config['local_source_path']."/{$letter}/{$identifier}/{$identifier}_jp2.tar";
	// Do we have a path and JP2s in the Isilon?
	if (file_exists($jp2_zip)) {
		// Get the list of filenames
		$zip = new ZipArchive;
		if (!$zip->open($jp2_zip)) {
			echo 'Failed to open Zipfile: '.$jp2_zip."\n";
			return false;
		} else {
			$c = 1;
			$total = count($pages);
			foreach ($pages as $p) {
				print chr(13)."Getting/converting images from the Isilon (".$c++." of $total)...";
				$f_jp2 = $p['FileNamePrefix'].'.jp2';
				$f_jpg = $p['FileNamePrefix'].'.jpg';
				$fp = $identifier.'_jp2/'.$f_jp2;
				if (!file_exists($config['paths']['cache_image'].'/'.$f_jpg)) {
					// Extract them from the ZIP file
					if (!$zip->extractTo($config['paths']['cache_image'], $fp)) {
						echo 'failed to extract file '.$fp."\n";
					} else {
						// Convert to JPEG and move to the cache folder
						$im = new Imagick ();
						$im->readImage($config['paths']['cache_image'].'/'.$fp);
						$im->writeImage($config['paths']['cache_image'].'/'.$f_jpg);
					}
				}
			}
			print "\n";
			$zip->close();
		}
	} elseif (file_exists($jp2_tar)) {
		
		$tar = new Archive_Tar($jp2_tar);
		if (!$tar) {
			echo 'Failed to open Tarfile: '.$jp2_tar."\n";
			return false;
		} else {
			$c = 1;
			$total = count($pages);
			foreach ($pages as $p) {
				print chr(13)."Getting/converting images from the Isilon (".$c++." of $total)...";
				$f_jp2 = $p['FileNamePrefix'].'.jp2';
				$f_jpg = $p['FileNamePrefix'].'.jpg';
				$fp = $identifier.'_jp2/'.$f_jp2;
				if (!file_exists($config['paths']['cache_image'].'/'.$f_jpg)) {
					// Extract them from the ZIP file
					if (!$tar->extractList(array($fp), $config['paths']['cache_image'], $identifier.'_jp2/')) {
						echo 'failed to extract file '.$fp."\n";
					} else {
						// Convert to JPEG and move to the cache folder
						$im = new Imagick ();
						$im->readImage($config['paths']['cache_image'].'/'.$f_jp2);
						$im->writeImage($config['paths']['cache_image'].'/'.$f_jpg);
					}
				}
			}
			print "\n";
			unset($tar);
		}

	} else {
		print "Getting images from the Internet Archive...\n";
		// No, fall back to getting it from online
		foreach ($pages as $p) {
			$path = $config['paths']['cache_image'].'/'.$p['FileNamePrefix'].'.jpg';
			if (!file_exists($path) || $override) {
				$url = 'https://archive.org'.$p['ExternalURL'];
				print "Downloading page $url...\n";
				file_put_contents($path, file_get_contents($url));
			}
		}
	}
	return true;
}

/* 
	GET BHL SEGMENT
	Download the segment metadata from BHL
*/
function get_bhl_segment($id, $override = false) {
	global $config;

	# build a filename
	$filename = 'segment-'.$id.'.json';
	$path = $config['paths']['cache_json'].'/'.$filename;

	# if it's not in the cache, get it and put it there
	# note: Error results can get saved to the cache. 
	if (!file_exists($path) || $override) {
		$url = 'https://www.biodiversitylibrary.org/api3?op=GetPartMetadata&id='.$id.'&format=json&pages=t&names=t&apikey='.$config['bhl_api_key'];
		file_put_contents($path, file_get_contents($url));
	}
	# read from the cache
	$object = json_decode(file_get_contents($path), true, 512, JSON_OBJECT_AS_ARRAY);

	# check our results
	if (strtolower($object['Status']) == 'ok') {
		# did we actually get results?
		if (count($object['Result']) == 0) {
			unlink($path); # since we had an error, delete this from the cache.
			die('Segment '.$id.' not found.'."\n");		
		} else {
			# looks good, return the object
			return $object;
		}
	} else {
		unlink($path); # since we had an error, delete this from the cache.
		die('Error getting segment metadata: '.$object['ErrorMessage']."\n");
	}
}

/* 
	GET BHL ITEM
	Download the segmnent metadata from BHL
*/
function get_bhl_item($id, $override = false) {
	global $config;

	# build a filename
	$filename = 'item-'.$id.'.json';
	$path = $config['paths']['cache_json'].'/'.$filename;

	# if it's not in the cache, get it and put it there
	# note: Error results can get saved to the cache. 
	if (!file_exists($path) || $override) {
		$url = 'https://www.biodiversitylibrary.org/api3?op=GetItemMetadata&id='.$id.'&format=json&pages=f&names=f&parts=f&apikey='.$config['bhl_api_key'];
		file_put_contents($path, file_get_contents($url));
	}
	# read from the cache
	$object = json_decode(file_get_contents($path), true, 512, JSON_OBJECT_AS_ARRAY);

	# check our results
	if (strtolower($object['Status']) == 'ok') {
		# did we actually get results?
		if (count($object['Result']) == 0) {
			unlink($path); # since we had an error, delete this from the cache.
			die('Item '.$id.' not found.'."\n");		
		} else {
			# looks good, return the object
			return $object;
		}
	} else {
		unlink($path); # since we had an error, delete this from the cache.
		die('Error getting segment metadata: '.$object['ErrorMessage']."\n");
	}
}

/* 
	GET BHL PAGES
	For a given item id get the pages at BHL
*/
function get_bhl_pages($pages = array()) {
	global $bhl_dbh;
	
	if (!$bhl_dbh) {
		try {
			$bhl_dbh = new PDO(
				'sqlsrv:server=tcp:sil-cl01-bhl.us.sinet.si.edu,1433;Database=BHL',
				'BHLReadOnly',
				'BHLR3ad0n!y', 
				array('PDO::ATTR_ERRMODE' => PDO::ERRMODE_EXCEPTION)
			);
		} catch (Exception $e) {
			echo "Failed to get DB handle: ".$e->getMessage()."\n";
			exit;
		}
	}

	if (count($pages) > 0) {
		$placeholders = str_repeat('?, ', count($pages)-1).'?';

		$stmt = $bhl_dbh->prepare('SELECT * FROM Page WHERE PageID IN ('.$placeholders.')');
		$stmt->execute($pages);
		$rows = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$rows['pageid-'.$row['PageID']] = $row;
		}
		return $rows;
	}
}

/* 
	VALIDATE INPUT
	Make sure we have everything we need to continue
*/
function validate_input($argv) {
	global $config;

	# do we have a BHL API Key
	if (!$config['bhl_api_key']) {
		die('BHL API Key not set.'."\n");
	}

	foreach ($config['paths'] as $p) {
		# do the paths exist?
		if (!file_exists($p)) {
			mkdir($p, 0700, true);
		}
		# can we write to the paths?
			if (!is_writable($p)) {
			die("Permission denied to write to $p\n");
		}
	}

	# did we get an ID number on the URL?
	$id = null;
	if (!isset($argv[1])) {
		die('ID is required.'."\n");
	}

	# is the ID numeric?
	if (preg_match('/\d+/', $argv[1])) {
		$id = $argv[1];
	} else {
		die('ID must be numeric.'."\n");
	}

	return $id;
}

//----------------------------------------------------------------------------------------
/**
 * @brief Inject XMP metadata into PDF
 *
 * We inject XMP metadata using Exiftools
 *
 * @param reference Reference 
 * @param pdf_filename Full path of PDF file to process
 * @param tags Tags to add to PDF
 *
 */
function pdf_add_xmp($part, $pdf) {	
	$metadata = [];

	// $metadata[] = "-XMP:URL=".escapeshellarg($part['PartUrl']);	
	if (isset($part['Doi'])) {
		$metadata[] = "-XMP:DOI=".escapeshellarg($part['Doi']);
	}

	// Title
	$metadata[] = "-XMP:Title=".escapeshellarg($part['Title']);
	
	// Authors
	foreach ($part['Authors'] as $a) {
		$name = $a['Name'].' '.(isset($a['Dates']) ? ' ('.$a['Dates'].')' : '');
		$metadata[] = "-XMP:Creator+=".escapeshellarg($name);
	}
	
	// Article
	if ($part['Genre'] == 'Article') {
		$metadata[] = "-XMP:AggregationType=".escapeshellarg($part['Genre']); // TODO: Should be Genre
		$metadata[] = "-XMP:PublicationName=".escapeshellarg($part['ContainerTitle']);
		$metadata[] = "-XMP:Source=".escapeshellarg('https://www.biodiversitylibrary.org/item/'.$part['ItemID']);
		$metadata[] = "-XMP:Volume=".escapeshellarg($part['Volume']);
		if (isset($part['Issue'])) {
			$metadata[] = "-XMP:Number=".escapeshellarg($part['Issue']);
		}
		$metadata[] = "-XMP:StartingPage=".escapeshellarg($part['StartPageNumber']);
		if (isset($part['EndPageNumber'])) {
			$metadata[] = "-XMP:EndingPage=".escapeshellarg($part['EndPageNumber']);
		}
		if (isset($part['PageRange'])) {
			$metadata[] = "-XMP:PageRange=".escapeshellarg($part['PageRange']);
		}
		if (isset($part['Language'])) {
			$metadata[] = "-XMP:Publisher=".escapeshellarg($part['PublicationDetails']);
		}
		if (isset($part['Language'])) {
			$metadata[] = "-XMP:Language=".escapeshellarg($part['Language']);
		}
		if (isset($part['RightsStatus'])) {
			$metadata[] = "-XMP:Rights=".escapeshellarg($part['RightsStatus']);
		}
		if (isset($part['RelatedParts'])) {
			if (is_array($part['RelatedParts'])) {
				foreach ($part['RelatedParts'] as $r) {
					$metadata[] = "-XMP:Relation=".escapeshellarg('https://www.biodiversitylibrary.org/part/'.$r['PartID']);
				}				
			}
		}
		if (isset($part['Names'])) {
			if (is_array($part['Names'])) {
				foreach ($part['Names'] as $n) {
					$n = trim($n['NameConfirmed']);
					if ($n) {
						$metadata[] = "-keywords+=".escapeshellarg($n);
					}
				}				
			}
		}

		if (isset($part['Identifiers'])) {
			if (is_array($part['Identifiers'])) {
				foreach ($part['Identifiers'] as $i) {
					if ($i['IdentifierName'] == 'ISSN') {
						$metadata[] = "-XMP:ISSN=".escapeshellarg($i['IdentifierValue']);
					}
					if ($i['IdentifierName'] == 'BioStor') {
						$metadata[] = "-XMP-dc:Identifier=".escapeshellarg('BioStor:'.$i['IdentifierValue']);
					}
				}				
			}
		}
	}
	
	// TODO Handle different Genres

	if (isset($part['Date'])) {
		$metadata[] = "-XMP:Date=".escapeshellarg($part['Date']);
	}

	$cmd = '/usr/local/bin/exiftool -overwrite_original '.implode(' ', $metadata).' '.$pdf;
	`$cmd`;
}	


/*
<taginfo>
	<table name='XMP::cc' g0='XMP' g1='XMP-cc' g2='Author'>
		<desc lang='en'>XMP cc</desc>
		<tag id='attributionName' name='AttributionName' type='string' writable='true'/>
		<tag id='attributionURL' name='AttributionURL' type='string' writable='true'/>
		<tag id='deprecatedOn' name='DeprecatedOn' type='date' writable='true' g2='Time'/>
		<tag id='jurisdiction' name='Jurisdiction' type='string' writable='true'/>
		<tag id='legalcode' name='LegalCode' type='string' writable='true'/>
		<tag id='license' name='License' type='string' writable='true'/>
		<tag id='morePermissions' name='MorePermissions' type='string' writable='true'/>
		<tag id='permits' name='Permits' type='string' writable='true'>
			<values>
				<key id='cc:DerivativeWorks'/>
				<key id='cc:Distribution'/>
				<key id='cc:Reproduction'/>
				<key id='cc:Sharing'/>
			</values>
		</tag>
		<tag id='prohibits' name='Prohibits' type='string' writable='true'>
			<values>
				<key id='cc:CommercialUse'/>
				<key id='cc:HighIncomeNationUse'/>
			</values>
		</tag>
		<tag id='requires' name='Requires' type='string' writable='true'>
			<values>
				<key id='cc:Attribution'/>
				<key id='cc:Copyleft'/>
				<key id='cc:LesserCopyleft'/>
				<key id='cc:Notice'/>
				<key id='cc:ShareAlike'/>
				<key id='cc:SourceCode'/>
			</values>
		</tag>
		<tag id='useGuidelines' name='UseGuidelines' type='string' writable='true'/>
	</table>

	<table name='XMP::dc' g0='XMP' g1='XMP-dc' g2='Other'>
		<desc lang='en'>XMP Dublin Core</desc>
		<tag id='contributor' name='Contributor' type='string' writable='true' g2='Author'/>
		<tag id='coverage' name='Coverage' type='string' writable='true'/>
		<tag id='creator' name='Creator' type='string' writable='true' g2='Author'/>
		<tag id='date' name='Date' type='date' writable='true' g2='Time'/>
		<tag id='description' name='Description' type='lang-alt' writable='true' g2='Image'/>
		<tag id='format' name='Format' type='string' writable='true' g2='Image'/>
		<tag id='identifier' name='Identifier' type='string' writable='true' g2='Image'/>
		<tag id='language' name='Language' type='string' writable='true'/>
		<tag id='publisher' name='Publisher' type='string' writable='true' g2='Author'/>
		<tag id='relation' name='Relation' type='string' writable='true'/>
		<tag id='rights' name='Rights' type='lang-alt' writable='true' g2='Author'/>
		<tag id='source' name='Source' type='string' writable='true' g2='Author'/>
		<tag id='subject' name='Subject' type='string' writable='true' g2='Image'/>
		<tag id='title' name='Title' type='lang-alt' writable='true' g2='Image'/>
		<tag id='type' name='Type' type='string' writable='true' g2='Image'/>
	</table>

	<table name='XMP::pdf' g0='XMP' g1='XMP-pdf' g2='Image'>
		<desc lang='en'>XMP PDF</desc>
		<tag id='Author' name='Author' type='string' writable='true' g2='Author'/>
		<tag id='Copyright' name='Copyright' type='string' writable='true' g2='Author'/>
		<tag id='CreationDate' name='CreationDate' type='date' writable='true' g2='Time'/>
		<tag id='Creator' name='Creator' type='string' writable='true' g2='Author'/>
		<tag id='Keywords' name='Keywords' type='string' writable='true'/>
		<tag id='Marked' name='Marked' type='boolean' writable='true'/>
		<tag id='ModDate' name='ModDate' type='date' writable='true' g2='Time'/>
		<tag id='PDFVersion' name='PDFVersion' type='string' writable='true'/>
		<tag id='Producer' name='Producer' type='string' writable='true' g2='Author'/>
		<tag id='Subject' name='Subject' type='string' writable='true'/>
		<tag id='Title' name='Title' type='string' writable='true'/>
		<tag id='Trapped' name='Trapped' type='string' writable='true'>
			<values>
				<key id='False'/>
				<key id='True'/>
				<key id='Unknown'>
				</key>
			</values>
		</tag>
	</table>

	<table name='XMP::xmp' g0='XMP' g1='XMP-xmp' g2='Image'>
		<desc lang='en'>XMP xmp</desc>
		<tag id='Advisory' name='Advisory' type='string' writable='true'/>
		<tag id='Author' name='Author' type='string' writable='true' g2='Author'/>
		<tag id='BaseURL' name='BaseURL' type='string' writable='true'/>
		<tag id='CreateDate' name='CreateDate' type='date' writable='true' g2='Time'/>
		<tag id='CreatorTool' name='CreatorTool' type='string' writable='true'/>
		<tag id='Description' name='Description' type='lang-alt' writable='true'/>
		<tag id='Format' name='Format' type='string' writable='true'/>
		<tag id='Identifier' name='Identifier' type='string' writable='true'/>
		<tag id='Keywords' name='Keywords' type='string' writable='true'/>
		<tag id='Label' name='Label' type='string' writable='true'/>
		<tag id='MetadataDate' name='MetadataDate' type='date' writable='true' g2='Time'/>
		<tag id='ModifyDate' name='ModifyDate' type='date' writable='true' g2='Time'/>
		<tag id='Nickname' name='Nickname' type='string' writable='true'/>
		<tag id='PageInfo' name='PageInfo' type='struct' writable='true'/>
		<tag id='PageInfoFormat' name='PageImageFormat' type='string' writable='true'/>
		<tag id='PageInfoHeight' name='PageImageHeight' type='integer' writable='true'/>
		<tag id='PageInfoImage' name='PageImage' type='string' writable='true' g2='Preview'/>
		<tag id='PageInfoPageNumber' name='PageImagePageNumber' type='integer' writable='true'/>
		<tag id='PageInfoWidth' name='PageImageWidth' type='integer' writable='true'/>
		<tag id='Rating' name='Rating' type='real' writable='true'/>
		<tag id='Thumbnails' name='Thumbnails' type='struct' writable='true'/>
		<tag id='ThumbnailsFormat' name='ThumbnailFormat' type='string' writable='true'/>
		<tag id='ThumbnailsHeight' name='ThumbnailHeight' type='integer' writable='true'/>
		<tag id='ThumbnailsImage' name='ThumbnailImage' type='string' writable='true' g2='Preview'/>
		<tag id='ThumbnailsWidth' name='ThumbnailWidth' type='integer' writable='true'/>
		<tag id='Title' name='Title' type='lang-alt' writable='true'/>
	</table>

</taginfo>
*/
