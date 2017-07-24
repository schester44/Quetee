# QueueManager


A job queue manager / task runner. Used for scheduling functions that should run at a later time (typically via a cronjob);

**This currently has a codeigniter depedency but can be removed fairly easily**


## API


 * add - the meat and potatoes of this class. Adds a job to the system that can be ran at a later date

```	
$this->queue->add( array( 
	    'id' => 3,
	    'type' => 'exportSlide', (string)
	    'start_time' => '1498771394' (unix timestamp)
	    **'class' => 'Slides', (string)
	    **'function' => 'export', (string)
	    **'filename' => 'Slides.php', (string)
	    **'filepath' => 'controllers/', (string - relative path from the application/ directory)
	    'params' => array( $slide, 'someotherparams', 'andmore' ) (array)
) );
``` 
			
_It is recommended that you pass an ID with the parameters to prevent duplicate jobs for the same resource. If an ID is not passed in, a random ID will be generated for the job._

----------
		
* process - runs all unprocessed jobs. will delete the job after it has completed.

```
$this->queue->process();
```


----------


* delete - deletes any jobs with the ID passed. Can pass in a type to limit what type of jobs are deleted
	* id (int)**
	* type (string)

```
$this->queue->delete( 3, 'exportSlide' );
```

----------


* exists - checks if a job with a given ID exists. Can optionally pass in a job type for a more granular search.
	* id (int)**
	* type (string)

```
$this->queue->exists( 3, 'exportSlide' );
```

----------


* getJobs -  Returns an array of all jobs. Can pass a job type for a more granular search.
	* type (string)
```
$this->queue->getJobs( 'exportSlide' );
```
### ** - denotes a required field
	