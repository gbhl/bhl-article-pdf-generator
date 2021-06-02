<?php
$config = [];

// ---------------------------------------------
// BHL API KEY - The Key used to identify our activity at BHL
// ALLOWED VALUES - A key from https://www.biodiversitylibrary.org/getapikey.aspx
//
// This is required.
// ---------------------------------------------
$config['bhl_api_key'] 	= '';

// ---------------------------------------------
// CACHE LIFETIME - How many days can pass before we re-fetch somnething from the web? 
// ALLOWED VALUES - Any integer number,
// 
// Note: PDFs from the Internet Archive will only be re-fetched if
// the cache is older than what is at the Internet Archive.
// ---------------------------------------------
$config['cache_lifetime'] = 7;


// ---------------------------------------------
// PATHS - Places where we save things
// Most of these don't need to be changed.
// ---------------------------------------------
$config['paths']                 = [];
$config['paths']['cache_pdf']    = './cache/pdf';
$config['paths']['cache_image']  = './cache/image';
$config['paths']['cache_resize'] = './cache/resize';
$config['paths']['cache_json']   = './cache/json';
$config['paths']['cache_djvu']   = './cache/djvu';
$config['paths']['tmp']          = './tmp';
$config['paths']['output']       = './output';

// ---------------------------------------------
// LOCAL SOURCE PATH - Location of local files to save the network.
// 
// If you have a local copy of the files, this is where they will be found.
// The default is the current directory which effectively turns it off.
// 
// Expects the structure in this folder to be: 
// 
//    I/Identifier00 (first letter, IA identifier)
// ---------------------------------------------
$config['local_source_path'] = './'; 

// ---------------------------------------------
// RESIZE FACTOR - Do we resize the images to make the resulting PDF smaller?
// ALLOWED VALUES - values from 0.10 to 1.00
// 
// Anything other than 1 will slow down the processing but result in larger files.
// ---------------------------------------------
$config['resize_factor'] = 1; // Expressed as a percent 0.50 = 50%.

// ---------------------------------------------
// DESATURATE - Convert to greyscale? 
// ALLOWED VALUES - TRUE or FALSE
// 
// This has little effect on the resulting PDF, 
// but is left since the code expects it 
// ---------------------------------------------
$config['desaturate'] = false;


