<?php

namespace BHL\PDFGenerator;

use Exception;
use PDODb;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use setasign\Fpdi\Tfpdf\Fpdi;
use setasign\Fpdi\PdfReader;

require_once(dirname(__FILE__) . '/../lib/djvu.php');

define("_SYSTEM_TTFONTS", dirname(__FILE__).'/../assets/noto-sans/');

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

	// Size of an A4 page
	private $a4_width_mm = 210; // millimeters
	private $a4_height_mm = 297; // millimeters

	/*
		CONSTRUCTOR
		Set up logging, check config.
	 */
	public function __construct($config, $verbose = false) {
		$this->config = $config;
		$this->validate_config();
		if ($verbose) {
			print "Setting verbosity from constructor\n";
			$this->verbose = $verbose;
		}
		
		// Allow config to set verbosity
		if ($this->config->get('verbose')) {
			print "Setting verbosity from config\n";
			$this->verbose = true;
		}

		// create a log channel
		$dateFormat = "Y-m-d H:i:s T";
		$output = "[%datetime%] %level_name% %message% %context%\n";
		$formatter = new LineFormatter($output, $dateFormat);
		$stream = new StreamHandler($this->config->get('logging.filename'));
		$stream->setFormatter($formatter);
		$this->log = new Logger('makepdf');
		$this->log->pushHandler($stream);
	}
	
	/*
		GENERATE ARTICLE PDF
		Main action, do all the things!
	 */
	function generate_article_pdf($id, $pages_changed = true, $metadata_changed = true, $ocr_changed = true) {
		try {
			// Set our filename
			$L1 = substr((string)$id, 0, 1);
			$L2 = substr((string)$id, 1, 1);
			$output_filename = $this->config->get('paths.output').'/'.$L1.'/'.$L2.'/bhl-segment-'.$id.($this->config->get('image.desaturate') ? '-grey' : '').'.pdf';
			if (!file_exists($this->config->get('paths.output').'/'.$L1.'/'.$L2)) {
				mkdir($this->config->get('paths.output').'/'.$L1.'/'.$L2, 0755, true);
			}

			// If it doesn't exist, make sure we recreate from scratch.
			if (!file_exists($output_filename)) {
				$pages_changed = true;
			}

			$this->clean_cache();

			// Get the basic segment info
			if ($this->verbose) { print "Getting BHL Segment Metadata\n"; }
			$part = $this->get_bhl_segment($id);
			if (!$part) { return; }
			$part = $part['Result'][0]; // deference this for ease of use

			if (isset($part['ExternalUrl'])) {
				if ($part['ExternalUrl'] != '') {
					if ($this->verbose) { print "Part points to an external URL. Skipping.\n"; }
					$this->log->notice("Segment $id points to an external URL. Skipping.", ['pid' => \posix_getpid()]);
					return;
				}
			}
			if (!isset($part['ItemID'])) {
				if ($this->verbose) { print "Part has no item id!\n"; }
				$this->log->notice("Segment $id has no item id.", ['pid' => \posix_getpid()]);
				return false;                    
			}

			// Turn that into a list of pages, because we need the prefix (maybe)
			$pages = [];
			foreach ($part['Pages'] as $p) {
				$pages[] = $p['PageID'];
			}
			$mode = ($pages_changed ? "pages " : "").($metadata_changed ? "metadata ": "").($ocr_changed ? "ocr " : "");
			$this->log->notice("Processing segment $id (".count($pages)." pages) [".trim($mode)."]...", ['pid' => \posix_getpid()]);
			// Get the info for the part from BHL
			if ($this->verbose) { print "Getting BookID {$part['ItemID']}\n"; }
			$item = $this->get_bhl_item($part['ItemID']);
			if (!$item) { return; }
			$item = $item['Result'][0]; // deference this for ease of use

			// Get the pages from BHL because maybe I need the file name prefix
			$page_details = $this->get_bhl_pages($pages);

			$pdf = null;
			// If the pages changed, then we generate a whole new PDF
			// Alternatively, only the metadata might change, in which case we 
			// skip all this.
			if ($pages_changed) {

				// Get our PDF
				if ($this->verbose) { print "Getting DJVU file\n"; }
				$djvus = $this->get_djvus($page_details);

				// Error handling
				if (!$djvus) {
					$this->log->error("  Could not get DJVU file or it's empty.", ['pid' => \posix_getpid()]);
					if ($this->verbose) { print "  ERROR: Could not get DJVU file or it's empty.\n"; }
					exit(1);
				}
				if ($this->verbose) { print "Reading DJVU file(s)\n"; }
				foreach ($djvus as $d => $rec) {
					$djvu = new \PhpDjvu($djvus[$d]['path']);
					$djvus[$d]['djvu'] = $djvu;
				}
				unset($djvu);
				if (count($djvus) > 1) {
					$this->log->notice("  Segment $id spans multiple Books.", ['pid' => \posix_getpid()]);
					if ($this->verbose) { print "  WARNING: Segment $id spans multiple Books.\n"; }
				}
				

				// Get our Images
				if ($this->verbose) { print "Getting Page Images\n"; }
				$this->get_page_images($page_details, $item['SourceIdentifier']);

								// Calculate the height and width and aspect ratio of each page.
				foreach ($page_details as $p => $page) {
					if (!$page_details[$p]['JPGFile']) {
						$this->log->notice("  Segment $id has problems with images. Clearing cache and exiting early. Please try again.", ['pid' => \posix_getpid()]);	
						if ($this->verbose) { print "  ERROR: Segment $id has problems with images. Try clearing cache and try again. \n"; }
						exit(1);
					}
					$imagesize = getimagesize($page_details[$p]['JPGFile']);

					$img_width_px = (int)($imagesize[0] * $this->config->get('image.resize'));
					$img_height_px = (int)($imagesize[1] * $this->config->get('image.resize'));

					$page_details[$p]['WidthPX'] = $img_width_px; 
					$page_details[$p]['HeightPX'] = $img_height_px;

					// Decide if we need to fix the height or the width
					$image_aspect_ratio = $img_height_px / $img_width_px;
					$a4_aspect_ratio = $this->a4_height_mm / $this->a4_width_mm;

					$page_details[$p]['AspectRatio'] = $image_aspect_ratio;
					$page_details[$p]['A4AspectRatio'] = $a4_aspect_ratio;
					// Do we fit to the height or the width?
					if ($image_aspect_ratio > 1) {
						// Image is narrower than an A4 page, fit to the height
						$page_details[$p]['DPMM'] = $img_height_px / $this->a4_height_mm;
						$page_details[$p]['Orientation'] = 'P';
					} else {
						// Image is wider than an A4 page, fit to the width
						$page_details[$p]['DPMM'] = $img_width_px / $this->a4_width_mm;
						$page_details[$p]['Orientation'] = 'L';
					}
					// Convert to millimeters
					$page_details[$p]['WidthMM'] = (int)($img_width_px / $page_details[$p]['DPMM']); 
					$page_details[$p]['HeightMM'] = (int)($img_height_px / $page_details[$p]['DPMM']);
				}

				// ------------------------------
				// Generate the PDF
				// ------------------------------
				$pdf = new \CustomPDF('P', 'mm');
				$pdf->SetAutoPageBreak(false);
				$pdf->SetMargins(0, 0);
				$pdf->AddFont('NotoSans','',   'NotoSans-Regular.ttf', true);
				$pdf->AddFont('NotoSans','I',  'NotoSans-Italic.ttf', true);
				$pdf->AddFont('NotoSans','B',  'NotoSans-Bold.ttf', true);
				$pdf->AddFont('NotoSans','IB', 'NotoSans-BoldItalic.ttf', true);

				$params = [];
				$c = 0;
				foreach ($pages as $pg) {
					$p = $page_details['pageid-'.$pg];
					if ($this->verbose) { print chr(13)."Adding Page {$c} of ".count($pages)." to PDF"; }
					// Resize the image
					$xy_factor = 1;
					if ($this->config->get('image.resize') != 1) {
						$factor = (int)($this->config->get('image.resize') * 100);
						$xy_factor = $this->config->get('image.resize');
						if (!file_exists($this->config->get('cache.paths.resize').'/'.$p['FileNamePrefix'].'.jpg')) {
							$cmd = "convert -resize ".$factor."% "
								."'".$this->config->get('cache.paths.image').'/'.$p['FileNamePrefix'].'.jpg'."' "
								."'".$this->config->get('cache.paths.resize').'/'.$p['FileNamePrefix'].'.jpg'."'";
							`$cmd`;
						}
						$p['JPGFile'] = $this->config->get('cache.paths.resize').'/'.$p['FileNamePrefix'].'.jpg';
					}
					$pdf->AddPage($p['Orientation'], array($p['WidthMM'], $p['HeightMM']));
					$pdf->SetFont('NotoSans', '', 10);
					$pdf->SetTextColor(0, 0, 0);
					
					// Get the lines, Add the text to the page
					$djvu = $djvus[$p['BarCode']]['djvu'];
					$prefix = $djvu->GetPagebySequence($p['SequenceOrder']-1);
					$lines = $djvu->GetPageLines($prefix, $this->config->get('image.resize'), $p['DPMM']);
					foreach ($lines as $l) {
						$pdf->setXY($l['x'], $l['y']);
						$pdf->Cell($l['w'], $l['h'], $l['text'], 1, 0, 'FJ'); // FJ = force full justifcation
					}
					$pdf->Image($p['JPGFile'], 0, 0, ($p['DPMM'] * -25.4)); 
					$c++;
				} // foreach pages
				if ($this->verbose) { print chr(13)."Adding Page {$c} of ".count($pages)." to PDF"; }
				print "\n";
				$pdf->SetCompression(false);
				$pdf->SetDisplayMode('fullpage','two');

				// Add the "cover" page...at the end
				$this->add_cover_page($pdf, $part, $item);

			}
			if ($metadata_changed) {
				$pdf = new Fpdi();

				// get the page count
				$pageCount = $pdf->setSourceFile($output_filename);
				// iterate through all pages
				for ($pageNo = 1; $pageNo <= ($pageCount-1); $pageNo++) {
						// import a page
						$templateId = $pdf->importPage($pageNo);

						$pdf->AddPage();
						// use the imported page and adjust the page size
						$pdf->useTemplate($templateId, ['adjustPageSize' => true]);
				}
				// Re-add the fonts because we made a new PDF
				$pdf->AddFont('NotoSans','',   'NotoSans-Regular.ttf', true);
				$pdf->AddFont('NotoSans','I',  'NotoSans-Italic.ttf', true);
				$pdf->AddFont('NotoSans','B',  'NotoSans-Bold.ttf', true);
				$pdf->AddFont('NotoSans','IB', 'NotoSans-BoldItalic.ttf', true);

				// Add the "cover" page...at the end
				$this->add_cover_page($pdf, $part, $item);

			}

			// Set the title metadata
			// For whatever reason, the PDF expects ISO-8859-1 even though we are using UTF-8
			$pdf->SetTitle(utf8_decode($this->get_citation($part, $item)));

			// Set the Author Metadata
			$temp = [];
			foreach ($part['Authors'] as $a) {
				$temp[] = $a['Name'].(isset($a['Dates']) ? ' ('.$a['Dates'].')' : '');
			}
			// For whatever reason, the PDF expects ISO-8859-1 even though we are using UTF-8
			$pdf->SetAuthor(utf8_decode(implode('; ', $temp)));

			// Set the Subject metadata, which we are hijacking to link back to BHL
			$pdf->SetSubject('From the Biodiversity Heritage Library (BHL)');	

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
			if ($this->verbose) { print "PDF for segment $id finished\n"; }
			$this->log->notice("PDF for segment $id finished.", ['pid' => \posix_getpid()]);
		} catch (\Exception $e) {
			if ($this->verbose) { print "Exception while processing segment $id: ".$e->getMessage()."\n"; }
			$this->log->error("Exception while processing segment $id: ".$e->getMessage(), ['pid' => \posix_getpid()]);
			throw new \Exception("Exception while processing segment $id: ".$e->getMessage());
			return;
		}

	}

	/*
	 *
	 */
	private function add_cover_page($pdf, $part, $item) {
		$image = dirname(__FILE__).'/../assets/BHL-logo.png';
		$pdf->AddPage('P', array($this->a4_width_mm, $this->a4_height_mm));
		$pdf->SetMargins('20','20');
		$pdf->Image($image, 30, 30, 150, 0, 'PNG');

		// PREPROCESS THE AUTHORS
		$authors = [];
		$authorstring = '';
		foreach ($part['Authors'] as $a) {
			$authors[] = preg_replace('/,\s?$/', '', $a['Name']);
		}
		if (count($authors) == 1) {
			$authorstring = $authors[0];
		} elseif (count($authors) == 2) {
			$authorstring = "{$authors[0]} and {$authors[1]}";
		} elseif (count($authors) == 3) {
			$authorstring = "{$authors[0]}, {$authors[1]}, and {$authors[2]}";
		} elseif (count($authors) != 0) {
			$authorstring = "{$authors[0]} et al.";
		}

		$pdf->setY('100');
		$line_height = 7;
		$font_size = 13;

		// CITATION
		//    ... approximately
		$pdf->SetFont('NotoSans', '', $font_size); 
		if ($authorstring) { 
			if (preg_match('/\.$/', $authorstring)) {
				$pdf->Write($line_height, $authorstring.' '); 
			} else {
				$pdf->Write($line_height, $authorstring.'. '); 
			}
		}
		if (isset($part['Date']) && $part['Date']) { 
			$matches = [];
			if (preg_match('/(\d\d\d\d)/', $part['Date'], $matches)) {
				$part['Date'] = $matches[1];
			}
			$pdf->Write($line_height, $part['Date'].'. '); 
		}
		if (isset($part['Title']) && $part['Title']) { 
			if (preg_match('/[\'?,.:;]$/', $part['Title'])) {
				$pdf->Write($line_height, "\"{$part['Title']}\" ");
			} else {
				$pdf->Write($line_height, "\"{$part['Title']}.\" ");
			}
		}
		if (isset($part['ContainerTitle']) && $part['ContainerTitle']) { 
			$pdf->SetFont('NotoSans', 'I', $font_size); 
			$pdf->Write($line_height, $part['ContainerTitle']." "); 
			$pdf->SetFont('NotoSans', '', $font_size); 
		}
		// build the volume/series/issue info
		$vol_series = '';
		if (isset($part['Volume']) && $part['Volume']) { 
			$vol_series .= $part['Volume'];
		}
		if (isset($part['Issue']) && $part['Issue']) {
			$vol_series .= "(".$part['Issue'].")";
		}
		// Series is not required.
		// if (isset($part['Series']) && $part['Series']) {
		// 	if ($vol_series) { $vol_series .= " "; }
		// 	$vol_series .= "(".$part['Series'].")";
		// }
		// did we build something?
		if ($vol_series) { 
			$pdf->Write($line_height, $vol_series.", "); 
		}
		
		if (isset($part['PageRange']) && $part['PageRange']) { 
			$part['PageRange'] = preg_replace("/[–-]+/", "–", $part['PageRange']);
			$pdf->Write($line_height, $part['PageRange'].". "); 
		}
		// if (isset($part['PublicationDetails']) && $part['PublicationDetails']) { $pdf->Write($line_height, $part['PublicationDetails']." "); }
		if (isset($part['Doi']) && $part['Doi']) { 
			$pdf->SetFont('NotoSans', 'U', $font_size); 
			$pdf->SetTextColor(76, 103, 155);
			$pdf->Write($line_height, 'https://doi.org/'.$part['Doi'], 'https://doi.org/'.$part['Doi']); 
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('NotoSans', '', $font_size); 
			$pdf->Write($line_height, '.'); 
		}

		$pdf->Ln($line_height, '');
		$pdf->Ln($line_height, '');

		$line_height = 6;
		$font_size = 11;
		
		// URLS 
		$pdf->SetFont('NotoSans', 'B', $font_size);
		$pdf->SetTextColor(0,0,0);
		$pdf->Write($line_height, 'View This Item Online: ');

		$pdf->SetFont('NotoSans', 'U', $font_size);
		$pdf->SetTextColor(76, 103, 155);
		$pdf->Write($line_height, 'https://www.biodiversitylibrary.org/item/'.$part['ItemID'], 'https://www.biodiversitylibrary.org/item/'.$part['ItemID']);
		$pdf->Ln($line_height, '');
		if (isset($part['Doi']) && $part['Doi']) {
			$pdf->SetFont('NotoSans', 'B', $font_size);
			$pdf->SetTextColor(0,0,0);
			$pdf->Write($line_height, 'DOI: ');
			$pdf->SetTextColor(76, 103, 155);
			$pdf->SetFont('NotoSans', 'U', $font_size);
			$pdf->Write($line_height, 'https://doi.org/'.$part['Doi'], 'https://doi.org/'.$part['Doi']); 
			$pdf->Ln($line_height, '');
		}
		$pdf->SetFont('NotoSans', 'B', $font_size);
		$pdf->SetTextColor(0,0,0);
		$pdf->Write($line_height, 'Permalink: ');
		$pdf->SetFont('NotoSans', 'U', $font_size);
		$pdf->SetTextColor(76, 103, 155);
		$pdf->Write($line_height, 'https://www.biodiversitylibrary.org/partpdf/'.$part['PartID'], 'https://www.biodiversitylibrary.org/partpdf/'.$part['PartID']);
		$pdf->Ln($line_height, '');
		$pdf->Ln($line_height, '');

		$pdf->SetTextColor(0, 0, 0);

		$line_height = 6; 
		$font_size = 11;
		
		// RIGHTS STATUS
		if (isset($item['HoldingInstitution']) && $item['HoldingInstitution']) {
			$pdf->SetFont('NotoSans', 'B', $font_size);
			$pdf->Write($line_height, 'Holding Institution ');
			$pdf->Ln($line_height, '');
			$pdf->SetFont('NotoSans', '', $font_size);
			$pdf->Write($line_height, $item['HoldingInstitution']);
			$pdf->Ln($line_height, '');
			$pdf->Ln($line_height, '');
	  }
		if (isset($item['Sponsor']) && $item['Sponsor']) {
			$pdf->SetFont('NotoSans', 'B', $font_size);
			$pdf->Write($line_height, 'Sponsored by ');
			$pdf->Ln($line_height, '');
			$pdf->SetFont('NotoSans', '', $font_size);
			$pdf->Write($line_height, $item['Sponsor']);
			$pdf->Ln($line_height, '');
			$pdf->Ln($line_height, '');
		}

		// RIGHTS STATUS
		$pdf->SetTextColor(0,0,0);
		$pdf->SetFont('NotoSans', 'B', $font_size);
		$pdf->Write($line_height, 'Copyright & Reuse ');
		$pdf->Ln($line_height, '');
		$pdf->SetFont('NotoSans', '', $font_size);
		$pdf->Write($line_height, 'Copyright Status: '.$part['RightsStatus']);
		$pdf->Ln($line_height, '');
		if (isset($item['RightsHolder']) && $item['RightsHolder']) {
			$pdf->SetTextColor(0, 0, 0); // Black
			$pdf->SetFont('NotoSans', '', $font_size); // Regular Font
			$pdf->Write($line_height, 'Rights Holder: '.$item['RightsHolder']);
			$pdf->Ln($line_height, '');
		}
		if (isset($part['LicenseUrl']) && $part['LicenseUrl']) {
			$pdf->Write($line_height, 'License: ');
			$pdf->SetFont('NotoSans', 'U', $font_size); // Underline Regular
			$pdf->SetTextColor(76, 103, 155); // Blue
			$pdf->Write($line_height, $part['LicenseUrl'], $part['LicenseUrl']);
			$pdf->Ln($line_height, '');
		}
		if (isset($item['Rights']) && $item['Rights']) {
			$pdf->SetTextColor(0, 0, 0); // Black
			$pdf->SetFont('NotoSans', '', $font_size); // Regular Font
			$pdf->Write($line_height, 'Rights: ');
			$pdf->SetFont('NotoSans', 'U', $font_size); // Underline Regular
			$pdf->SetTextColor(76, 103, 155); // Blue
			$pdf->Write($line_height, $item['Rights'], $item['Rights']);
			$pdf->Ln($line_height, '');
		}
		$pdf->Ln($line_height, '');
		// PDF CREATED DATE
		$font_size = 11;
		$line_height = 5;

		$pdf->Ln($line_height, '');
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('NotoSans', '', $font_size);
		$pdf->Write($line_height, 'This document was created from content at the ');
		$pdf->SetFont('NotoSans', 'B', $font_size);
		$pdf->Write($line_height, 'Biodiversity Heritage Library');
		$pdf->SetFont('NotoSans', '', $font_size);
		$pdf->Write($line_height, ', the world\'s largest open access digital library for biodiversity literature and archives. ');
		$pdf->Write($line_height, 'Visit BHL at ');
		$pdf->SetTextColor(76, 103, 155);
		$pdf->Write($line_height, 'https://www.biodiversitylibrary.org', 'https://www.biodiversitylibrary.org');
		$pdf->SetTextColor(0,0,0);
		$pdf->Write($line_height, '.');
		

		$pdf->setY('270');
		$font_size = 8;
		$line_height = 5;
		date_default_timezone_set('UTC');
		$pdf->SetFont('NotoSans', '', $font_size);
		$pdf->Write($line_height, 'This file was generated '.date('j F Y \a\t H:i T'));
		$pdf->Ln($line_height, '');

	}

	/*
		GET PDF
		Download a PDF from the Internet Archive
	 */
	private function get_archive_pdf($identifier) {

		$filename = $identifier.'.pdf';
		$path = $this->config->get('cache.paths.pdf').'/'.$filename;
		if (!file_exists($path)) {
			$url = 'https://archive.org/download/'.$identifier.'/'.$identifier.'.pdf';
			file_put_contents($path, file_get_contents($url));
		}
		return $path;
	}

	/*
		GET DJVU XML Files
		Download the DJVU file(s) from the Internet Archive
		There may be more than one, so let's be extra careful
	 */
	private function get_djvus($pages) {
		$ret = [];
		foreach ($pages as $p) {
			$identifier = $p['BarCode'];
			if (!isset($ret[$identifier])) {
				$letter = substr($identifier,0,1);
				$filename = $identifier.'_djvu.xml';
				$cache_path = $this->config->get('cache.paths.djvu').'/'.$filename;
				if (!file_exists($cache_path)) {
					$djvu = $this->config->get('paths.local_source')."/{$letter}/{$identifier}/{$identifier}_djvu.xml";
					// Do we have the file locally?
					if (file_exists($djvu)) {
						// Do we have it on the Isilon?
						copy($djvu, $cache_path);
					} else {
						// Get it from the internet archive
						$url = 'https://archive.org/download/'.$identifier.'/'.$filename;
						try {
							// Suppress the warning because we'll check later if it's empty
							$contents = @file_get_contents($url);
							if ($contents) {
								file_put_contents($cache_path, $contents);
							} else {
								if ($this->verbose) { print "  ERROR: Could not get DJVU from IA\n"; }
								return null;	
							}							
						} catch (Exception $e) {
							if ($this->verbose) { print "  ERROR: Unable to get DJVU from IA: ".$e->getMessage()."\n"; }
							return null;
						}
						
					}
				}
				$ret[$identifier] = array('path' => $cache_path);
				if (filesize($cache_path) == 0) {
					unlink($cache_path);
					if ($this->verbose) { print "  DJVU Cache file is empty\n"; }
					return null;
				}
			}
		}
		return $ret;
	}

	/*
		GET PAGE IMAGES
		Given an array of Page IDs, download the images from IA
		(future versions of this will grab the image from our TAR file)
	 */
	private function get_page_images(&$pages, $identifier) {
		$c = 1;
		$total = count($pages);
		foreach ($pages as $p => $rec) {
			$bc = $pages[$p]['BarCode'];
			$prefix = $pages[$p]['FileNamePrefix'];
			$letter = substr($pages[$p]['BarCode'], 0, 1);

			$jp2_zip = $this->config->get('paths.local_source')."/{$letter}/{$bc}/{$bc}_jp2.zip";
			$jp2_tar = $this->config->get('paths.local_source')."/{$letter}/{$bc}/{$bc}_jp2.tar";
			$tif_zip = $this->config->get('paths.local_source')."/{$letter}/{$bc}/{$bc}_tif.zip";

			if ($this->verbose) { print "  {$prefix} ($c/$total)..."; }

			$pages[$p]['JPGFile'] = null;

			// Check the Cache file
			if (file_exists($this->config->get('cache.paths.image')."/{$prefix}.jpg")) {
				if ($this->verbose) { print " from Cache.\n"; }
				$pages[$p]['JPGFile'] = $this->config->get('cache.paths.image')."/{$prefix}.jpg";
				$got_file = true;
			}
			
			// Check the local JP2 ZIP file
			if (!$pages[$p]['JPGFile']) {
				if (file_exists($jp2_zip)) {
					// Get the list of filenames
					$zip = new \ZipArchive;
					if ($zip->open($jp2_zip)) {
						// Extract them from the ZIP file
						$ret = $zip->extractTo($this->config->get('cache.paths.image'), "{$bc}_jp2/{$prefix}.jp2");
						if ($ret) {
							if ($this->verbose) { print " from JP2 ZIP\n"; }
							// Convert to JPEG and move to the cache folder
							$im = new \Imagick ();
							$im->readImage($this->config->get('cache.paths.image')."/{$bc}_jp2/{$prefix}.jp2");
							$im->writeImage($this->config->get('cache.paths.image')."/{$prefix}.jpg");
							$pages[$p]['JPGFile'] = $this->config->get('cache.paths.image')."/{$prefix}.jpg";
						} else {
							if ($this->verbose) { print "    $jp2_zip Extraction Failed for {$bc}_jp2/{$prefix}.jp2\n"; }
						}
						$zip->close();
					} else {
						if ($this->verbose) { print "    $jp2_zip Can't be Opened\n"; }
					}
					unset($zip);
				}
			}

			// Check the local JP2 TAR file
			if (!$pages[$p]['JPGFile']) {
				if (file_exists($jp2_tar)) {
					$tar = new \Archive_Tar($jp2_tar);
					if ($tar) {
						$f_jp2 = $pages[$p]['FileNamePrefix'].'.jp2';
						$f_jpg = $pages[$p]['FileNamePrefix'].'.jpg';
						$fp = "{$pages[$p]['BarCode']}_jp2/{$f_jp2}";
						// Extract them from the ZIP file
						$ret = $tar->extractList(
							array("{$pages[$p]['BarCode']}_jp2/{$f_jp2}"), 
							$this->config->get('cache.paths.image'), 
							"{$bc}_jp2/"
						);
						if ($ret) {
							if ($this->verbose) { print " from JP2 TAR\n"; }
							// Convert to JPEG and move to the cache folder
							$im = new \Imagick ();
							$im->readImage($this->config->get('cache.paths.image')."/{$prefix}.jp2");
							$im->writeImage($this->config->get('cache.paths.image')."/{$prefix}.jpg");
							$pages[$p]['JPGFile'] = $this->config->get('cache.paths.image')."/{$prefix}.jpg";
						}
						unset($tar);
					}
				}
			}

			// Check the local TIF ZIP file
			if (!$pages[$p]['JPGFile']) {
				if (file_exists($tif_zip)) {
					// Get the list of filenames
					$zip = new \ZipArchive;
					if ($zip->open($tif_zip)) {
						// Extract them from the ZIP file
						$ret = $zip->extractTo($this->config->get('cache.paths.image'), "{$bc}_tif/{$prefix}.tif");
						if ($ret) {
							if ($this->verbose) { print " from TIF ZIP\n"; }
							// Convert to JPEG and move to the cache folder
							$im = new \Imagick ();
							$im->readImage($this->config->get('cache.paths.image')."/{$bc}_tif/{$prefix}.tif");
							$im->writeImage($this->config->get('cache.paths.image')."/{$prefix}.jpg");
							$pages[$p]['JPGFile'] = $this->config->get('cache.paths.image')."/{$prefix}.jpg";
						} else {
							if ($this->verbose) { print "    $tif_zip Extraction Failed for {$bc}_tif/{$prefix}.tif\n"; }
						}
						$zip->close();
					} else {
						if ($this->verbose) { print "    $tif_zip Can't be Opened\n"; }
					}
					unset($zip);
				}
			}

			// fall back to getting it from IA's JP2 ZIP file
			if (!$pages[$p]['JPGFile']) {
				$url = "https://archive.org/download/{$bc}/{$bc}_jp2.zip/{$bc}_jp2/{$prefix}.jp2";
				$temp_fn = $this->config->get('cache.paths.image')."/{$prefix}.jp2";
				@file_put_contents($temp_fn, file_get_contents($url));
				clearstatcache(); // Stupid, but required for getting filesize() of local paths
				if (file_exists($temp_fn) && filesize($temp_fn) > 0) {
					if ($this->verbose) { print " from IA JP2 ZIP\n"; }
					$im = new \Imagick ();
					$im->readImage($temp_fn);
					$im->writeImage($this->config->get('cache.paths.image')."/{$prefix}.jpg");
					$pages[$p]['JPGFile'] = $this->config->get('cache.paths.image')."/{$prefix}.jpg";
				}
			}

			// fall back even more to getting it from IA's JP2 TAR file
			if (!$pages[$p]['JPGFile']) {
				$url = "https://archive.org/download/{$bc}/{$bc}_jp2.tar/{$bc}_jp2/{$prefix}.jp2";
				$temp_fn = $this->config->get('cache.paths.image')."/{$prefix}.jp2";
				@file_put_contents($temp_fn, file_get_contents($url));
				clearstatcache(); // Stupid, but required for getting filesize() of local paths
				if (file_exists($temp_fn) && filesize($temp_fn) > 0) {
					if ($this->verbose) { print " from IA JP2 TAR\n"; }
					$im = new \Imagick ();
					$im->readImage($temp_fn);
					$im->writeImage($this->config->get('cache.paths.image')."/{$prefix}.jpg");
					$pages[$p]['JPGFile'] = $this->config->get('cache.paths.image')."/{$prefix}.jpg";
				}
			}

			// This is pretty bad, let's log an error
			if (!$pages[$p]['JPGFile']) {
				if ($this->verbose) { print "    ERROR: Could not find file {$prefix}\n"; }
				$this->log->error("Could not find file {$prefix}", ['pid' => \posix_getpid()]);
				throw new \Exception("Item {$identifier}: Could not find file {$prefix}");
			}

			// Verify this is an image!
			if (exif_imagetype($pages[$p]['JPGFile']) != IMAGETYPE_JPEG) {
				if ($this->verbose) { print "    File is not a JPEG: {$prefix}.jpg\n"; }
				$this->log->notice("Item {$identifier}: File is not a JPEG: {$prefix}.jpg", ['pid' => \posix_getpid()]);
				throw new \Exception("Item {$identifier}: File is not a JPEG: {$prefix}.jpg");
				$pages[$p]['JPGFile'] = null;
				$got_file = false;
			}
			$c++;
		}
	}

	/* 
		GET BHL SEGMENT
		Download the segment metadata from BHL
	 */
	private function get_bhl_segment($id) {
		$url = 'https://www.biodiversitylibrary.org/api3?op=GetPartMetadata&id='.$id.'&format=json&pages=t&names=t&apikey='.$this->config->get('bhl.api_key');
		$data = file_get_contents($url);
		$object = json_decode($data, true, 512, JSON_OBJECT_AS_ARRAY);

		# check our results
		if (strtolower($object['Status']) == 'ok') {
			# did we actually get results?
			if (count($object['Result']) == 0) {
				$this->log->error('Segment '.$id.' not found.', ['pid' => \posix_getpid()]);
				return null;
			} else {
				# looks good, return the object
				return $object;
			}
		} else {
			$this->log->error('Error getting segment metadata: '.$object['ErrorMessage'], ['pid' => \posix_getpid()]);
		}
	}

	/* 
		GET BHL ITEM
		Download the segmnent metadata from BHL
	 */
	private function get_bhl_item($id) {
		# read from the cache
		$url = 'https://www.biodiversitylibrary.org/api3?op=GetItemMetadata&id='.$id.'&format=json&pages=f&names=f&parts=f&apikey='.$this->config->get('bhl.api_key');
		$data = file_get_contents($url);
		$object = json_decode($data, true, 512, JSON_OBJECT_AS_ARRAY);

		# check our results
		if (strtolower($object['Status']) == 'ok') {
			# did we actually get results?
			if (count($object['Result']) == 0) {
		        $this->log->error('Item '.$id.' not found.', ['pid' => \posix_getpid()]);
				return null;
			} else {
				# looks good, return the object
				return $object;
			}
		} else {
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
			$stmt = $this->bhl_dbh->prepare(
				"SELECT p.PageID, p.FileNamePrefix, ip.ItemID, ip.SequenceOrder, b.BookID, b.BarCode
				FROM Page p 
				INNER JOIN ItemPage ip ON p.PageID = ip.PageID
				LEFT OUTER JOIN Book b On ip.ItemID = b.ItemID
				WHERE p.PageID IN ({$placeholders}) 
				AND b.BookID IS NOT NULL
				ORDER BY ip.sequenceorder"
			);
			$stmt->execute($pages);
			$rows = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$rows['pageid-'.$row['PageID']] = $row;
			}
			return $rows;
		}
	}

	/* 
		GET ALL SEGMENTS
		For a given item id get the pages at BHL
	 */
	public function get_all_segments() {		
		if (!$this->bhl_dbh) {
			try {
				$this->bhl_dbh = new \PDO($this->config->get('bhl.db.dsn'), $this->config->get('bhl.db.username'), $this->config->get('bhl.db.password'));
			} catch (Exception $e) {
				echo "Failed to get DB handle: ".$e->getMessage()."\n";
				exit;
			}
		}
		$stmt = $this->bhl_dbh->prepare(
			"SELECT SegmentID 
			 FROM Segment s 
			 INNER JOIN item i ON s.ItemId = i.ItemID
			 WHERE i.ItemStatusID = 40
			 AND s.RedirectSegmentID IS NULL
			 AND (s.url IS NULL OR s.url = '')
			 ORDER BY s.SegmentID"
		);
		$stmt->execute();
		$ids = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$ids[] = $row['SegmentID'];
		}
		return $ids;
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

	/*
	  An ugly, non-ASCII-character safe replacement of escapeshellarg().
	 */
	function escapeshellarg_special($file) {
		return "'" . str_replace("'", "'\"'\"'", $file) . "'";
	}

	/*
	  Inject XMP metadata into PDF
	 */
	private function pdf_add_xmp($part, $item, $pdf) {	
		$metadata = [];
		// $metadata[] = "-XMP:URL=".escapeshellarg($part['PartUrl']);	
		if (isset($part['Doi'])) {
			$metadata[] = "-XMP:DOI=".escapeshellarg($part['Doi']);
		}

		// Title
		$metadata[] = "-XMP:Title=".escapeshellarg($this->get_citation($part, $item));
		
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
			if (isset($part['StartPageNumber'])) {
				$metadata[] = "-XMP:StartingPage=".escapeshellarg($part['StartPageNumber']);
			}	
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
			if (isset($item['RightsHolder'])) {
				$metadata[] = "-XMP:RightsOwner=".escapeshellarg($item['RightsHolder']);
				$metadata[] = "-XMP:License=".escapeshellarg($item['LicenseUrl']);
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

		$page_ids = [];
		foreach ($part['Pages'] as $p) {
			$page_ids[] = $p['PageID'];
			$page_text = '';
			if (isset($p['PageNumbers']) && $p['PageNumbers']) {
				foreach ($p['PageNumbers'] as $pg) {
					if (isset($pg['Number']) && $pg['Number']) {
						$page_text .= $pg['Number'];
					}
				}
			}
			if ($page_text) {
				$metadata[] = "-XMP:PageInfo+=".escapeshellarg("{PageNumber={$page_text}}");
			}
		}
		// Save the Page IDs for future use
		$metadata[] = "-XMP:Notes=".escapeshellarg("BHL PageIDs: ".implode(',', $page_ids));
		// TODO Handle different Genres

		if (isset($part['Date'])) {
			$metadata[] = "-XMP:Date=".escapeshellarg($part['Date']);
		}

		$cmd = '/usr/local/bin/exiftool -json -overwrite_original '.implode(' ', $metadata).' '.$pdf.' 2>&1';
		exec($cmd);
	}	

	/*
	  Be Nice to the disk. It is your friend.
	 */
	private function clean_cache() {
		return;
		`find {$this->config->get('cache.paths.image')} -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \; > /dev/null 2>&1`;
		`find {$this->config->get('cache.paths.resize')} -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \; > /dev/null 2>&1`;
		`find {$this->config->get('cache.paths.pdf')} -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \; > /dev/null 2>&1`;
		`find {$this->config->get('cache.paths.json')} -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \; > /dev/null 2>&1`;
		`find {$this->config->get('cache.paths.djvu')} -mtime +{$this->config->get('cache.lifetime')} -exec rm {} \; > /dev/null 2>&1`;
	}

	private function get_citation($part, $item) {

		// PREPROCESS THE AUTHORS
		$citation = '';
		$authors = [];
		$authorstring = '';
		foreach ($part['Authors'] as $a) {
			$authors[] = preg_replace('/,\s?$/', '', $a['Name']);
		}
		if (count($authors) == 1) {
			$authorstring = $authors[0];
		} elseif (count($authors) == 2) {
			$authorstring = "{$authors[0]} and {$authors[1]}";
		} elseif (count($authors) == 3) {
			$authorstring = "{$authors[0]}, {$authors[1]}, and {$authors[2]}";
		} elseif (count($authors) != 0) {
			$authorstring = "{$authors[0]} et al.";
		}

		// CITATION
		//    ... approximately
		if ($authorstring) { 
			if (preg_match('/\.$/', trim($authorstring))) {
				$citation .= $authorstring.' '; 
			} else {
				$citation .= $authorstring.'. '; 
			}
				
		}
		if (isset($part['Date']) && $part['Date']) { 
			if (preg_match('/(\d\d\d\d)/', $part['Date'], $matches)) {
				$part['Date'] = $matches[1];
			}
			$citation .= $part['Date'].'. '; }
		if (isset($part['Title']) && $part['Title']) { $citation .= "\"{$part['Title']}.\" "; }
		if (isset($part['ContainerTitle']) && $part['ContainerTitle']) { 

			$citation .= $part['ContainerTitle']." "; 

		}
		// build the volume/series/issue info
		$vol_series = '';
		if (isset($part['Volume']) && $part['Volume']) { 
			$vol_series .= $part['Volume'];
		}
		if (isset($part['Issue']) && $part['Issue']) {
			$vol_series .= "(".$part['Issue'].")";
		}
		// Series is not required.
		// if (isset($part['Series']) && $part['Series']) {
		// 	if ($vol_series) { $vol_series .= " "; }
		// 	$vol_series .= "(".$part['Series'].")";
		// }
		// did we build something?
		if ($vol_series) { 
			$citation .= $vol_series.", "; 
		}
		
		if (isset($part['PageRange']) && $part['PageRange']) { 
			$part['PageRange'] = preg_replace("/[–-]+/", "–", $part['PageRange']);
			$citation .= $part['PageRange'].". "; 
		}
		// if (isset($part['PublicationDetails']) && $part['PublicationDetails']) { $citation .= $part['PublicationDetails']." "; }
		if (isset($part['Doi']) && $part['Doi']) { 
			$citation .= 'https://doi.org/'.$part['Doi']; 
			$citation .= '.'; 
		}
		$citation = str_replace("\0", "", $citation);
		return $citation;
	}
}
