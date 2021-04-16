<?php
/* ********************************************
	Generate PDF for an Article/Segment in BHL 

	Created: 11 Nob 2020
	By: Joel Richard
	******************************************** 
*/

# does the config file exist?
if (!file_exists('config.php')) {
	die('config.php file not found.'."\n");
}
require_once('config.php');
require_once('lib/lib.php');
require_once('lib/djvu.php');
require_once('lib/fpdf182/fpdf.php');
require_once('lib/force_justify.php');

// Sanity checks
$id = validate_input($argv);

print "Getting metadata for Segment ID $id...\n";
// Get the basic segment info
$part = get_bhl_segment($id);
$part = $part['Result'][0]; // deference this fo ease of use

// Turn that into a list of pages, because we need the prefix (maybe)
$pages = [];
foreach ($part['Pages'] as $p) {
	$pages[] = $p['PageID'];
}
print "Getting metadata for Item ID {$part['ItemID']}...\n";
// Get the info for the part from BHL
$item = get_bhl_item($part['ItemID']);
$item = $item['Result'][0]; // deference this fo ease of use

// Get the pages from BHL because maybe I need the file name prefix
$page_details = get_bhl_pages($pages);

// Get our PDF
print "Getting DJVU...\n";
$djvu_path = get_djvu($item['SourceIdentifier']);

// Get our Images
print "Getting Images...\n";
get_page_images($page_details);

// Get the DJVU fata
$djvu = new PhpDjvu($djvu_path);

$output_filename = 	$config['paths']['output'].'/bhl-segment-'.$id.'.pdf';

$page_width = 8.5; // Inches
$page_height = 11; // Inches 
$page_aspect_ratio = $page_width / $page_height;

$pdf = new PDF('P', 'in', array($page_width, $page_height));
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0);

$params = [];
print "Adding pages...\n";
foreach ($page_details as $p) {
	$filename = $config['paths']['cache_image'].'/'.$p['FileNamePrefix'].'.jpg';
  
	// Resize the image
	if ($config['resize_factor'] != 1) {
		$factor = (int)($config['resize_factor'] * 100);

		$cmd = "convert -resize ".$factor."% "
		       ."'".$config['paths']['cache_image'].'/'.$p['FileNamePrefix'].'.jpg'."' "
					 ."'".$config['paths']['cache_resize'].'/'.$p['FileNamePrefix'].'.jpg'."'";
		`$cmd`;
		$filename = $config['paths']['cache_resize'].'/'.$p['FileNamePrefix'].'.jpg';
	}

	$imagesize = getimagesize($filename);
	$img_width = $imagesize[0];
	$img_height = $imagesize[1];
	$img_aspect_ratio = ($img_width / $img_height);

	$pdf->AddPage();
	$pdf->SetFont('Helvetica', '', 14);
	$pdf->SetTextColor(0, 0, 0);

	// Determine if we max out height or width to fit it in the page
	if ($img_aspect_ratio < $page_aspect_ratio) {
		// Image is narrower than the page, max out the height and center it horizontally
		$dpi = $img_height / $page_height;

		// Calculate the white space needed on the left and right
		$h_space = ($page_width - ($img_width / $dpi)) / 2; 
		
		// Get the lines, Add the text to the page
		$lines = $djvu->GetPageLines($p['FileNamePrefix'], $config['resize_factor'], $dpi);
		foreach ($lines as $l) {
 			$pdf->setXY($l['x'] + $h_space, $l['y']);
			$pdf->Cell($l['w'], $l['h'], $l['text'], 0, 0, 'FJ'); // FJ = force full justifcation
		}

		$pdf->Image($filename, $h_space, 0, null, $page_height);

	} else {
    // Image is taller than the page, max out the width and center it vertically
		$dpi = $img_width / $page_width;

		// Calculate the white space needed on the top and bottom
		$v_space = ($page_height - ($img_height / $dpi)) / 2; 

		// Get the lines, Add the text to the page
		$lines = $djvu->GetPageLines($p['FileNamePrefix'], $config['resize_factor'], $dpi);
		foreach ($lines as $l) {
			$pdf->setXY($l['x'], $l['y'] + $v_space);
			$pdf->Cell($l['w'], $l['h'], $l['text'], 0, 0, 'FJ'); // FJ = force full justifcation
		}

		$pdf->Image($filename, 0, $v_space, $page_width, null);
	} // if aspect ratio
	
} // foreach pages

$pdf->SetCompression(true);
$pdf->SetDisplayMode('fullpage','two');

// Set the title metadata
$title = $part['Genre'].': "'.$part['Title'].'"'
         .' From '.$part['ContainerTitle']
				 .($part['Volume'] ? ' Volume '.$part['Volume'] : '')
				 .($part['Issue'] ? ', Issue '.$part['Issue'] : '')
				 .($part['Date'] ? ' ('.$part['Date'].')' : '')
				 .($part['PageRange'] ? ', '.$part['PageRange'].'' : '')
				 .'.';
$pdf->SetTitle($title);

// Set the Author Metadata
$temp = [];
foreach ($part['Authors'] as $a) {
	$temp[] = $a['Name'].($a['Dates'] ? ' '.$a['Dates'] : '');
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
//$pdf->SetKeywords(implode(', ', $temp));

$pdf->SetCreator($part['PartUrl']);

// All done!
$pdf->Output('F',$output_filename);
pdf_add_xmp($part, $output_filename);
