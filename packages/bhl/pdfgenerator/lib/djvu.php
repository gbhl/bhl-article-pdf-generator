<?php

class PhpDjvu {

	public $filename = '';
	public $pages = [];
	private $page_sequences = [];

	public function __construct($filename = null) {
		$this->filename = $filename;
		$this->_init();
	}

	public function File($filename = '') {
		$this->filename = $filename;
		$this->_init();
	}
	
	public function GetPageBySequence($seq) {
		if (isset($this->page_sequences[$seq])) {
			return $this->page_sequences[$seq];
		}
		return null;
	}

	public function GetPageWords($page, $factor = 1, $dpi = 0) {
		if (!isset($this->pages[$page])) {
			throw new Exception('Page ID '.$page.' not found.');
		}
		if ($dpi == 0) { $dpi = $page['dpi']; }
		$page = $this->pages[$page];

		$ret = [];
		foreach ($page['lines'] as $l) {
			foreach ($l['words'] as $w) {
				$ret[] = array(
					'text' => $w['text'],
					'x' => $w['x1'] * $factor / $dpi,
					'y' => $w['y1'] * $factor / $dpi,
					'w' => ($w['x2'] - $w['x1']) * $factor / $dpi,
					'h' => ($w['y2'] - $w['y1']) * $factor / $dpi,
				);
			}
		}
		return $ret;
	}


	public function GetPageLines($page, $factor = 1, $dpi = 0) {
		if (!isset($this->pages[$page])) {
			throw new Exception('Page ID '.$page.' not found.');
		}
		if ($dpi == 0) { $dpi = $page['dpi']; }
		
		$page = $this->pages[$page];
		$ret = [];
		foreach ($page['lines'] as $line) {
			$ret[] = array(
				'words'=> $line['words'],
				'text' => $line['text'],
				'x'    => $line['x1'] * $factor / $dpi,
				'y'    => $line['y1'] * $factor / $dpi,
				'w'    => ($line['x2'] - $line['x1']) * $factor  / $dpi,
				'h'    => ($line['y2'] - $line['y1']) * $factor  / $dpi,
			);
		}
		return $ret;
	}

	private function _init() {
		if ($this->filename) {
			$this->xml = simplexml_load_file($this->filename);
			$this->xml->BODY->documentData = null; // TODO remove this when we're done testing

			$this->_parse_pages();
		}
	}

	private function _parse_pages() {
		// Cycle through the pages
		$seq = 1;
		foreach ($this->xml->BODY->OBJECT as $page) {
			// Get the identifying info for the page
			$page_name = $this->_get_object_param($page, 'PAGE');
			$dpi = $this->_get_object_param($page, 'DPI');
			$page_name = preg_replace('/\..{3,4}$/', '', $page_name);
			
			// Get the words on the page in an array
			$page_lines = $this->_parse_words($page);

			// Add them to our main array 
			$this->pages[$page_name] = array(
				'name' => $page_name,
				'dpi' => $dpi,
				'lines' => $page_lines,
				'sequence' => $seq
			);
			$seq++;
			$this->page_sequences[] = $page_name;
		}
	}

	private function _parse_words($page) {
		$ret = [];
		// Cycle through the hiddentext and columns
		foreach ($page->HIDDENTEXT->PAGECOLUMN as $col) {					
			if (!isset($col->REGION->PARAGRAPH)) { continue; }
			foreach ($col->REGION->PARAGRAPH as $par) { // Paragraphs in the column
				if (!isset($par->LINE)) { continue; }
				foreach ($par->LINE as $ln) { // Lines in the paragraph
					$words = [];
					$line_text = [];
					$min_w = 10000000;
					$min_h = 10000000;
					$max_w = 0;
					$max_h = 0;
					if (!isset($ln->WORD)) { continue; } 
					foreach ($ln->WORD as $word) { // Words in the line
						// Get the word coordinates. 
						// The coordinates are in the order: (X1,Y2,X2,Y1,Unused)
						$coords = explode(',', (string)$word['coords']);
						$words[] = array(
							'text' => (string)$word,
							'x1' => $coords[0],
							'y1' => $coords[3],
							'x2' => $coords[2],
							'y2' => $coords[1]
						);
						$line_text[] = (string)$word;
						if ($min_w > $coords[0]) { $min_w = $coords[0]; }
						if ($min_h > $coords[3]) { $min_h = $coords[3]; }
						if ($max_w < $coords[2]) { $max_w = $coords[2]; }
						if ($max_h < $coords[1]) { $max_h = $coords[1]; }
					} // foreach WORD
					$ret[] = array(
						'words' => $words,
						'text' => implode(' ', $line_text),
						'x1' => $min_w,
						'y1' => $min_h,
						'x2' => $max_w,
						'y2' => $max_h
					);
				} // foreach LINE
			} // foreach PARAGRAPH
		} // foreach PAGECOLUMN
		return $ret;
	}

	private function _get_object_param($object, $param) {
		foreach ($object->PARAM as $o) {
			if((string)$o['name'] == $param) {
				return (string)$o['value'];
			}
		}
	}


}
