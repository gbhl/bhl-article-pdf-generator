<?php
/* ********************************************
	Generate PDF for an Article/Segment in BHL 

	Created: 11 Nob 2020
	By: Joel Richard
	******************************************** 
*/

ini_set('memory_limit','1024M');
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
$letter = substr(''.$id, 0,1);

// Set our filename
$output_filename = $config['paths']['output'].'/'.$letter.'/bhl-segment-'.$id.($config['desaturate'] ? '-grey' : '').'.pdf';
if (file_exists($output_filename)) {
	print "File exists: $output_filename\n";
	exit;
}


print "Getting metadata for Segment ID $id...\n";
// Get the basic segment info
$part = get_bhl_segment($id);
$part = $part['Result'][0]; // deference this fo ease of use

if (!isset($part['ItemID'])) {
	print "Segment ID $id not found\n";
	exit;                    
}


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
$page_details = get_bhl_pages($pages, $item['SourceIdentifier']);

// Get our PDF
print "Getting DJVU...\n";
$djvu_path = get_djvu($item['SourceIdentifier']);

// Get our Images
print "Getting Images...\n";
$ret = get_page_images($page_details, $item['SourceIdentifier']);
if (!$ret) {
	exit(1);
}

// Get the DJVU fata
$djvu = new PhpDjvu($djvu_path);

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
	$filename = $config['paths']['cache_image'].'/'.$p['FileNamePrefix'].'.jpg';
	$imagesize = getimagesize($filename);
	if ($imagesize[0] > $max_img_width_px) { $max_img_width_px = (int)($imagesize[0] * $config['resize_factor']); }
	if ($imagesize[1] > $max_img_height_px) { $max_img_height_px = (int)($imagesize[1] * $config['resize_factor']); }
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
$pdf = new PDF('P', 'mm', array($page_width_mm, $page_height_mm));
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0);

$params = [];
$c = 0;
foreach ($page_details as $p) {
	print chr(13)."Adding pages...($c)"; 
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

	// Greyscale the image
	if ($config['desaturate'] == true) {
		$cmd = "convert -colorspace Gray -separate -average "
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

	// Calculate the white space needed on the left and right
	$h_space = ($max_page_width_mm - ($img_width / $dpm)) / 2; 
	$v_space = ($max_page_height_mm - ($img_height / $dpm)) / 2; 
	// Get the lines, Add the text to the page
	$lines = $djvu->GetPageLines($p['FileNamePrefix'], $config['resize_factor'], $dpm);
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
//$pdf->SetKeywords(implode(', ', $temp));

$pdf->SetCreator($part['PartUrl']);

// All done!
$pdf->Output('F',$output_filename);
pdf_add_xmp($part, $output_filename);
