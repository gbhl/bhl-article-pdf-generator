<?php

namespace BHL\PDFGenerator;

use PDODb;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

require_once(dirname(__FILE__) . '/../lib/djvu.php');

/* ********************************************
	Libraries for generating article PDFs for BHL 

	Created: 11 Nob 2020
	By: Joel Richard
	******************************************** 
*/

class MakePDF {

	private $bhl_dbh = null;
	private $config;
	private $log;
	private $verbose = false;

	public function __construct($config, $verbose = false) {
		$this->config = $config;
		$this->validate_config();
		$this->verbose = $verbose;

		// create a log channel
		$dateFormat = "Y-m-d H:i:s T";
		$output = "[%datetime%] %level_name% %message% %context%\n";
		$formatter = new LineFormatter($output, $dateFormat);
		$stream = new StreamHandler($this->config->get('logging.filename'));
		$stream->setFormatter($formatter);
		$this->log = new Logger('makepdf');
		$this->log->pushHandler($stream);
	}
	
	function generate_article_pdf($id) {
		try {
		$this->log->notice("Processing segment $id", ['pid' => \posix_getpid()]);
                if ($this->verbose) { print "Processing segment $id\n"; }
		// Set our filename
		$L1 = substr((string)$id, 0, 1);
		$L2 = substr((string)$id, 1, 1);
		$output_filename = $this->config->get('paths.output').'/'.$L1.'/'.$L2.'/bhl-segment-'.$id.($this->config->get('image.desaturate') ? '-grey' : '').'.pdf';
		if (!file_exists($this->config->get('paths.output').'/'.$L1.'/'.$L2)) {
			mkdir($this->config->get('paths.output').'/'.$L1.'/'.$L2, 0755, true);
		}

		$this->clean_cache();

		// Get the basic segment info
		if ($this->verbose) { print "Getting BHL Segment Metadata\n"; }
		$part = $this->get_bhl_segment($id);
		if (!$part) { return; }
		$part = $part['Result'][0]; // deference this fo ease of use

		if (!isset($part['ItemID'])) {
			return false;                    
		}

		// Turn that into a list of pages, because we need the prefix (maybe)
		$pages = [];
		if ($this->verbose) { print "Segment $id is in Item {$part['ItemID']}\n"; }
		foreach ($part['Pages'] as $p) {
			$pages[] = $p['PageID'];
		}
		if ($this->verbose) { print "Segment $id has ".count($pages)." pages\n"; }
		// Get the info for the part from BHL
		if ($this->verbose) { print "Segment $id: Getting Item Metadata\n"; }
		$item = $this->get_bhl_item($part['ItemID']);
		if (!$item) { return; }
		$item = $item['Result'][0]; // deference this fo ease of use

		// Get the pages from BHL because maybe I need the file name prefix
		if ($this->verbose) { print "Getting pages from {$item['SourceIdentifier']}\n";}
		$page_details = $this->get_bhl_pages($pages, $item['SourceIdentifier']);

		// Get our PDF
		if ($this->verbose) { print "Getting DJVU file\n"; }
		$djvu_path = $this->get_djvu($item['SourceIdentifier']);

		// Get our Images
		if ($this->verbose) { print "Getting Page Images\n"; }
		$ret = $this->get_page_images($page_details, $item['SourceIdentifier']);
		if (!$ret) {
			exit(1);
		}

		// Get the DJVU data
		$djvu = new \PhpDjvu($djvu_path);

		// ------------------------------
		// Calculate the size of our page
		// ------------------------------
		$page_width_mm = 0;
		$page_height_mm = 0;

		// Size of an A4 page
		$max_page_width_mm = 210; // millimeters
		$max_page_height_mm = 297; // millimeters

		// Calculate the upper limits of the size of all images 
		$max_img_width_px = 0;
		$max_img_height_px = 0;
		foreach ($page_details as $p) {
			$filename = $this->config->get('cache.paths.image').'/'.$p['FileNamePrefix'].'.jpg';
			$imagesize = getimagesize($filename);
			if ($imagesize[0] > $max_img_width_px) { $max_img_width_px = (int)($imagesize[0] * $this->config->get('image.resize')); }
			if ($imagesize[1] > $max_img_height_px) { $max_img_height_px = (int)($imagesize[1] * $this->config->get('image.resize')); }
		}

		// Decide if we need to fix the height or the width
		$image_aspect_ratio = $max_img_height_px / $max_img_width_px;
		$page_aspect_ratio = $max_page_height_mm / $max_page_width_mm;

		// Do we fit to the height or the width?
		if ($image_aspect_ratio > $page_aspect_ratio) {
			// Image is narrower than an A4 page, fit to the height
			$dpm = $max_img_height_px / $max_page_height_mm;
		} else {
			// Image is wider than an A4 page, fit to the width
			$dpm = $max_img_width_px / $max_page_width_mm;
		}
		// Convert to millimeters
		$page_width_mm = $max_img_width_px / $dpm; 
		$page_height_mm = $max_img_height_px / $dpm;

		// ------------------------------
		// Generate the PDF
		// ------------------------------
		$pdf = new \CustomPdf('P', 'mm', array($page_width_mm, $page_height_mm));
		$pdf->SetAutoPageBreak(false);
		$pdf->SetMargins(0, 0);

		$params = [];
		$c = 0;
		foreach ($pages as $pg) {
			$p = $page_details['pageid-'.$pg];
			if ($this->verbose) { print chr(13)."Processing Page {$c} of ".count($pages); }

			$filename = $this->config->get('cache.paths.image').'/'.$p['FileNamePrefix'].'.jpg';
			
			// Resize the image
			
			if ($this->config->get('image.resize') != 1) {
				$factor = (int)($this->config->get('image.resize') * 100);
				if (!file_exists($this->config->get('cache.paths.resize').'/'.$p['FileNamePrefix'].'.jpg')) {
					$cmd = "convert -resize ".$factor."% "
						."'".$this->config->get('cache.paths.image').'/'.$p['FileNamePrefix'].'.jpg'."' "
						."'".$this->config->get('cache.paths.resize').'/'.$p['FileNamePrefix'].'.jpg'."'";
					`$cmd`;
				}
				$filename = $this->config->get('cache.paths.resize').'/'.$p['FileNamePrefix'].'.jpg';
			}

			$imagesize = getimagesize($filename);
			$img_width = $imagesize[0];
			$img_height = $imagesize[1];
			$img_aspect_ratio = ($img_width / $img_height);

			$pdf->AddPage();
			$pdf->SetFont('Helvetica', '', 14);
			$pdf->SetTextColor(0, 0, 0);

			// Calculate the white space needed on the left and right
			$h_space = ($max_page_width_mm - ($img_width / $dpm)) / 2; 
			$v_space = ($max_page_height_mm - ($img_height / $dpm)) / 2; 
			// Get the lines, Add the text to the page
			$lines = $djvu->GetPageLines($p['FileNamePrefix'], $this->config->get('image.resize'), $dpm);
			foreach ($lines as $l) {
				$pdf->setXY($l['x'], $l['y']);
				$pdf->Cell($l['w'], $l['h'], $l['text'], 0, 0, 'FJ'); // FJ = force full justifcation
			}

			$pdf->Image($filename, 0, 0, ($dpm * -25.4));
			$c++;
		} // foreach pages
		print "\n";
		$pdf->SetCompression(false);
		$pdf->SetDisplayMode('fullpage','two');

		// Set the title metadata
		$title = $part['Genre'].': "'.$part['Title'].'"'
						.' From '.$part['ContainerTitle']
						.(isset($part['Volume']) ? ' Volume '.$part['Volume'] : '')
						.(isset($part['Issue']) ? ', Issue '.$part['Issue'] : '')
						.(isset($part['Date']) ? ' ('.$part['Date'].')' : '')
						.(isset($part['PageRange']) ? ', '.$part['PageRange'].'' : '')
						.'.';
		$pdf->SetTitle($title);

		// Set the Author Metadata
		$temp = [];
		foreach ($part['Authors'] as $a) {
			$temp[] = $a['Name'].(isset($a['Dates']) ? ' ('.$a['Dates'].')' : '');
		}
		$pdf->SetAuthor(implode('; ', $temp));

		// Set the Subject metadata, which we are hijacking to link back to BHL
		$pdf->SetSubject('From the Biodiversity Heritage Library');	

		// Set the Keyword metadata (scientific names)
		$temp = [];
		foreach ($part['Names'] as $a) {
			if ($a['NameConfirmed']) {
					$temp[] = preg_replace('/,/',';',iconv("UTF-8", "ASCII//TRANSLIT", $a['NameConfirmed']));
			}
		}

		$pdf->SetCreator($part['PartUrl']);

		// All done!
		$pdf->Output('F',$output_filename);
		$this->pdf_add_xmp($part, $item, $output_filename);
		chmod($output_filename, 0644);
		$this->log->notice("PDF for segment $id finished.", ['pid' => \posix_getpid()]);
		} catch (\Exception $e) {
			$this->log->error("Exception while processing segment $id: ".$e->getMessage(), ['pid' => \posix_getpid()]);
			return;
		}

	}

	/*
		GET PDF
		Download a PDF from the Internet Archive
	*/
	private function get_archive_pdf($identifier, $override = false) {

		$filename = $identifier.'.pdf';
		$path = $this->config->get('cache.paths.pdf').'/'.$filename;
		if (!file_exists($path) || $override) {
			$url = 'https://archive.org/download/'.$identifier.'/'.$identifier.'.pdf';
			file_put_contents($path, file_get_contents($url));
		}
		return $path;
	}

	/*
		GET DJVU XML
		Download a PDF from the Internet Archive
	*/
	private function get_djvu($identifier, $override = false) {		
		$letter = substr($identifier,0,1);
		$filename = $identifier.'_djvu.xml';
		$cache_path = $this->config->get('cache.paths.djvu').'/'.$filename;
		if (!file_exists($cache_path) || $override) {
			$djvu = $this->config->get('paths.local_source')."/{$letter}/{$identifier}/{$identifier}_djvu.xml";
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
	private function get_page_images($pages, $identifier, $override = false) {

		$letter = substr($identifier,0,1);
		$jp2_zip = $this->config->get('paths.local_source')."/{$letter}/{$identifier}/{$identifier}_jp2.zip";
		$jp2_tar = $this->config->get('paths.local_source')."/{$letter}/{$identifier}/{$identifier}_jp2.tar";
		// Do we have a path and JP2s in the Isilon?
		if (file_exists($jp2_zip)) {
			// Get the list of filenames
			$zip = new \ZipArchive;
			if (!$zip->open($jp2_zip)) {
				echo 'Failed to open Zipfile: '.$jp2_zip."\n";
				return false;
			} else {
				$c = 1;
				$total = count($pages);
				if ($this->verbose) { print "Getting Page Images from ZIP\n"; }
				foreach ($pages as $p) {
					$f_jp2 = $p['FileNamePrefix'].'.jp2';
					$f_jpg = $p['FileNamePrefix'].'.jpg';
					$fp = $identifier.'_jp2/'.$f_jp2;
					if ($this->verbose) { print "Extracting $fp\n"; }
					if (!file_exists($this->config->get('cache.paths.image').'/'.$f_jpg)) {
						// Extract them from the ZIP file
						if (!$zip->extractTo($this->config->get('cache.paths.image'), $fp)) {
							echo 'failed to extract file '.$fp."\n";
						} else {
							// Convert to JPEG and move to the cache folder
							$im = new \Imagick ();
							if ($this->verbose) { print "Converting to $f_jpg\n"; }
							$im->readImage($this->config->get('cache.paths.image').'/'.$fp);
							$im->writeImage($this->config->get('cache.paths.image').'/'.$f_jpg);
						}
					}
				}
				$zip->close();
			}
		} elseif (file_exists($jp2_tar)) {
			$tar = new \Archive_Tar($jp2_tar);
			if (!$tar) {
				echo 'Failed to open Tarfile: '.$jp2_tar."\n";
				return false;
			} else {
				$c = 1;
				$total = count($pages);
				if ($this->verbose) { print "Getting page image from TAR file\n"; }
				foreach ($pages as $p) {
					$f_jp2 = $p['FileNamePrefix'].'.jp2';
					$f_jpg = $p['FileNamePrefix'].'.jpg';
					$fp = $identifier.'_jp2/'.$f_jp2;
					if (!file_exists($this->config->get('cache.paths.image').'/'.$f_jpg)) {
						// Extract them from the ZIP file
						if (!$tar->extractList(array($fp), $this->config->get('cache.paths.image'), $identifier.'_jp2/')) {
							echo 'failed to extract file '.$fp."\n";
						} else {
							// Convert to JPEG and move to the cache folder
							$im = new \Imagick ();
							$im->readImage($this->config->get('cache.paths.image').'/'.$f_jp2);
							$im->writeImage($this->config->get('cache.paths.image').'/'.$f_jpg);
						}
					}
				}
				unset($tar);
			}
		} else {
			// No, fall back to getting it from online
			if ($this->verbose) { print "Getting page image from Internet Archive\n"; }
			foreach ($pages as $p) {
				$path = $this->config->get('cache.paths.image').'/'.$p['FileNamePrefix'].'.jpg';
				if (!file_exists($path) || $override) {
					$url = 'https://archive.org'.$p['ExternalURL'];
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
	private function get_bhl_segment($id, $override = false) {

		# build a filename
		$filename = 'segment-'.$id.'.json';
		$path = $this->config->get('cache.paths.json').'/'.$filename;

		# if it's not in the cache, get it and put it there
		# note: Error results can get saved to the cache. 
		if (!file_exists($path) || $override) {
			$url = 'https://www.biodiversitylibrary.org/api3?op=GetPartMetadata&id='.$id.'&format=json&pages=t&names=t&apikey='.$this->config->get('bhl.api_key');
			file_put_contents($path, file_get_contents($url));
		}
		# read from the cache
		$object = json_decode(file_get_contents($path), true, 512, JSON_OBJECT_AS_ARRAY);

		# check our results
		if (strtolower($object['Status']) == 'ok') {
			# did we actually get results?
			if (count($object['Result']) == 0) {
				unlink($path); # since we had an error, delete this from the cache.
				$this->log->error('Segment '.$id.' not found.', ['pid' => \posix_getpid()]);
				return null;
			} else {
				# looks good, return the object
				return $object;
			}
		} else {
			unlink($path); # since we had an error, delete this from the cache.
			$this->log->error('Error getting segment metadata: '.$object['ErrorMessage'], ['pid' => \posix_getpid()]);
		}
	}

	/* 
		GET BHL ITEM
		Download the segmnent metadata from BHL
	*/
	private function get_bhl_item($id, $override = false) {
		
		# build a filename
		$filename = 'item-'.$id.'.json';
		$path = $this->config->get('cache.paths.json').'/'.$filename;

		# if it's not in the cache, get it and put it there
		# note: Error results can get saved to the cache. 
		if (!file_exists($path) || $override) {
			$url = 'https://www.biodiversitylibrary.org/api3?op=GetItemMetadata&id='.$id.'&format=json&pages=f&names=f&parts=f&apikey='.$this->config->get('bhl.api_key');
			file_put_contents($path, file_get_contents($url));
		}
		# read from the cache
		$object = json_decode(file_get_contents($path), true, 512, JSON_OBJECT_AS_ARRAY);

		# check our results
		if (strtolower($object['Status']) == 'ok') {
			# did we actually get results?
			if (count($object['Result']) == 0) {
				unlink($path); # since we had an error, delete this from the cache.
                                $this->log->error('Item '.$id.' not found.', ['pid' => \posix_getpid()]);
				return null;
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
	private function get_bhl_pages($pages = array()) {
		
		if (!$this->bhl_dbh) {
			try {
				$this->bhl_dbh = new \PDO($this->config->get('bhl.db.dsn'), $this->config->get('bhl.db.username'), $this->config->get('bhl.db.password'));
			} catch (Exception $e) {
				echo "Failed to get DB handle: ".$e->getMessage()."\n";
				exit;
			}
		}

		if (count($pages) > 0) {
			$placeholders = str_repeat('?, ', count($pages)-1).'?';

			$stmt = $this->bhl_dbh->prepare('SELECT * FROM Page WHERE PageID IN ('.$placeholders.')');
			$stmt->execute($pages);
			$rows = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$rows['pageid-'.$row['PageID']] = $row;
			}
			return $rows;
		}
	}

	private function get_bhl_rights_holder($item_id) {
		
		$stmt = $this->bhl_dbh->prepare(
			'SELECT i.InstitutionName
			FROM ItemInstitution ii 
			INNER JOIN Book b ON b.ItemID = ii.ItemID
			INNER JOIN Institution i ON ii.InstitutionCode = i.InstitutionCode
			WHERE b.BookID = ?
			AND ii.InstitutionRoleID = 2' // 2 = Rights Holder
		);
		$stmt->execute(array($item_id));
		$row = null;
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			return $row['InstitutionName'];
		}
		return null;
	}

	/* 
		VALIDATE INPUT
		Make sure we have everything we need to continue
	*/
	private function validate_config() {

		# do we have a BHL API Key
		if (!$this->config->get('bhl.api_key')) {
			die('BHL API Key not set.'."\n");
		}

		foreach ($this->config->get('cache.paths') as $p) {
			# do the paths exist?
			if (!file_exists($p)) {
				mkdir($p, 0700, true);
			}
			# can we write to the paths?
				if (!is_writable($p)) {
				die("Permission denied to write to $p\n");
			}
		}
	}

	/**
	* An ugly, non-ASCII-character safe replacement of escapeshellarg().
	*/
	function escapeshellarg_special($file) {
		return "'" . str_replace("'", "'\"'\"'", $file) . "'";
	}

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
	private function pdf_add_xmp($part, $item, $pdf) {	
		$metadata = [];
		// $metadata[] = "-XMP:URL=".escapeshellarg($part['PartUrl']);	
		if (isset($part['Doi'])) {
			$metadata[] = "-XMP:DOI=".escapeshellarg($part['Doi']);
		}

		// Title
		$metadata[] = "-XMP:Title=".escapeshellarg($part['Title']);
		
		// Authors
		foreach ($part['Authors'] as $a) {
			$name = trim($a['Name'].' '.(isset($a['Dates']) ? ' ('.$a['Dates'].')' : ''));
			$name = preg_replace('/\\0/', "", $name);
			$metadata[] = "-XMP:Creator+=".$this->escapeshellarg_special($name);
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
			$rights_holder = $this->get_bhl_rights_holder($part['ItemID']);
			if ($rights_holder) {
				$metadata[] = "-XMP:RightsOwner=".escapeshellarg($rights_holder);
				$metadata[] = "-XMP:License=".escapeshellarg($item['LicenseUrl']);
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

	private function clean_cache() {
		`find {$this->config->get('cache.paths.image')} -type f -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \;`;
		`find {$this->config->get('cache.paths.resize')} -type f -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \;`;
		`find {$this->config->get('cache.paths.pdf')} -type f -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \;`;
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



















}
