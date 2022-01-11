# Config File Instructions

## Message Queue Settings

The message queue that is used to hold the ID numbers of BHL Segments that need to be created or re-generated.

**mq.hostname** _(string)_ - Hostname of the RabbitMQ instance

**mq.port** _(string)_ - Port for the RabbitMQ server. Default "5672"

**mq.username** _(string)_ - RabbitMQ username

**mq.password** _(string)_ - RabbitMQ password

**mq.queue_name** _(string)_ - Name of the queue from which to pull.

## BHL Section

Settings with which to connect to BHL to gather information. At this time, we need a live database connection since the API doesn't (yet?) offer all the data we need to collect.

**bhl.api_key** _(guid)_ - Used to identify our activity at BHL. Get one at https: //www.biodiversitylibrary.org/getapikey.aspx

**bhl.db.type** _(string)_ - May be 'dblib' or 'mssql'

**bhl.db.host** _(string)_ - Database Host name or IP Address

**bhl.db.dbname** _(string)_ - Database name.

**bhl.db.port** _(string)_ - Should be "1433"

**bhl.db.charset** _(string)_ - Should be "utf8"

**bhl.db.username** _(string)_ - Database username.

**bhl.db.password** _(string)_ - Database password.

## Cache Settings

Let's be nice to the web and our network and do some cachine. In a full re-generation of the PDFs on BHL, this is essentially meaningless, but it does allow us to minimize disk usage while downloading from the web.

**cache.lifetime** _(int)_ - How long in days should things stay in the cache before being deleted or re-fetched from the web?

**cache.paths** _(object) - Where to store the various cached items. The defaults are probably good.

## PDF Generation Settings

These directly influence how we create the PDFs, where to put our results and where to find the source images.

**image.resize** _(float, 0.0-1.0)_ - Scale image by this factor before adding to the pdf. Default "0.5".

**paths.output** _(path) - Where do save PDFs go when they are done? A directory sructure will be created based on the ID numbers: "/#/#/bhl-segment-######.pdf"

**paths.local_source** _(path) - Where can we go instead of the web to get image files? Expects the structure in this folder to be "I/Identifier00".

## Other Settings

Misc. Defaults are good.

**max_memory** _(megabytes)_ - How much memory is PHP allowed to use. "1536M" is the minimum.

**logging.filename** _(path)_ - Where is our log file?

