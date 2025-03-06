# bhl-article-pdfs

This code creates an PDF from a segment (article) in BHL. The PDF content is a subset of the pages of the PDF at the Internet Archive for the corresponding item in BHL.

## Background

In order to full integrate with Unpaywall, BHL needs to present a PDF of the article found by Unpaywall. This script is meant to pre-generate (or re-generate) the PDFs to be later served from BHL.

## Setup

Copy the ``config.sample.php`` file to ``config.php`` and enter your BHL API Key into the appropriate variable.

The ``./cache`` folder is used for temporary storage to reduce overhead of API calls to BHL and the Internet Archive. Use the ``cache_lifetime`` value in the ``config.php`` file to control how often items in the cache are re-fetched. The default is one week based on the typical frequency of when new content is added to BHL.

## Usage: Command Line

    php generate.php ID

``[ID]`` is a Segment ID number for a BHL segment (article) such as the number ``304567`` in the URL ``https://www.biodiversitylibrary.org/part/304567``

## Usage: System Daemon

    sudo systemctl [status|start|stop] pdf-updater

This will run the pdf-generator as a system-level daemon. The config setting for parallel processing is in the code itself (pdf-generator.php), not in the config file. 

## Results

Executing the script creates one or more PDFs in the ``./output`` folder. 

