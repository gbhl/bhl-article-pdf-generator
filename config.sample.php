<?php
	$config = [];

	/*
		BHL API Key (string)
		Request a key at: https://www.biodiversitylibrary.org/getapikey.aspx
	*/
	$config['bhl_api_key'] 	= '';

	/*
		Cache Lifetime (int, days)
		How long before we re-fetch API results or a PDF from the web? 
		
		Note: PDFs from the Internet Archive will only be re-fetched if
		the cache is older than what is at the Internet Archive.
	*/ 
	$config['cache_lifetime'] 	   = '7';

	// Places where we save things
	$config['paths']                 = [];
	$config['paths']['cache_pdf']    = './cache/pdf';
	$config['paths']['cache_image']  = './cache/image';
	$config['paths']['cache_resize'] = './cache/resize';
	$config['paths']['cache_json']   = './cache/json';
	$config['paths']['cache_djvu']   = './cache/djvu';
	$config['paths']['tmp']          = './tmp';
	$config['paths']['output']       = './output';
	$config['resize_factor']         = 1; // Expressed as a percent 0.50 = 50%.