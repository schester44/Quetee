<?php 
namespace Chester\QueueManager;

class QueueManager {

  private $queue_dir;
  protected $_objects = array();

  function __construct ( $parameters = array() )
  {
    $this->queue_dir = @$parameters['path'] ?: $this->config->item( 'data_path' ) . 'queue/';

    if ( !is_dir( $this->queue_dir ) ) {
      
      $dir_created = @mkdir( $this->queue_dir, DIR_WRITE_MODE, true );
      
      if ( !$dir_created ) {
        $error = error_get_last();
        log_message( 'error', sprintf( "%s: Failed to create directory '{$this->queue_dir}': {$error['message']}", __METHOD__ ) );
      }
    }
  }

  public function __get( $var )
  {
    return get_instance()->$var;
  }

  public function getJobs( $job_type = null )
  {
    $items = $this->recursiveDirectories2Array( $this->queue_dir, $job_type );
    $allJobs = array();
    
    if ( empty( $items ) ) {
      return $allJobs;
    }
    
    foreach ( $items as $type => $files )
    {
      $jobs = array();

      foreach ( $files as $key => $file )
      { 
        $job_file = "{$this->queue_dir}{$type}/{$file}";

        if ( !file_exists( $job_file ) || !is_file( $job_file ) || is_dir( $job_file ) ) {
          continue;
        }

        $job = @json_decode( file_get_contents( $job_file ) );

        if ( $job === false ) {
          $error = error_get_last();
          log_message( 'error', sprintf( "%s: Failed to read '{$job_file}': {$error['message']}", __METHOD__ ) );
          continue;
        }

        $jobs[] = $job;
      }

      $allJobs[$type] = $jobs;
    }
    
    return $allJobs;
  }

  /**
   * Method to add jobs to the workload. New jobs will overwrite any existing jobs with the same ID
   * @param array $options - options for the job
   *  ------------ OPTIONS -------------
   *  class - required - string - the class that conthains the function we're trying to run
   *  function  - required - string - the function within the library that we're trying to run
   *  filename - required - sring - the filename of the file containing the class
   *  filepath - required - string - the location, relative of the APPPATH of the file
   *  type    - required - string - the type of job type/category (inliner|spreadsheet|screenshot|etc)
   *  params  - optional - array  - an optional array of params to pass to the function
   *  id      - optional - string - an id of the job, must be unique (by default, add will assign the job an ID). QueueManager will overwrite any jobs of the same type that use the same ID
   *  ----------------------------------
   * 
   */
  public function add( array $options )
  {
    if ( $this->config->item( 'enable_queues' ) !== true ) {
      return;
    }

    $defaults = array(
      'type'    => 'all',
      'filepath'  => 'libraries',
      'id'    => base_convert( md5( rand( 0, 10000 ) . rand( 0, 10000 ) . time() ), 10, 36 )
    );

    $options = array_merge( $defaults, $options );
    
    $required = array( 
      'type',
      'class',
      'function',
      'filename',
      'filepath'
      );

    foreach ( $required as $key => $field )
    {
      if ( !isset( $options[$field] ) ) {
        return false;
      }
    }

    $job = array(
      'id' => $options['id'],
      'start_time' => isset( $options['start_time'] ) ? $options['start_time'] : null,
      'process' => array( 
        'class'    => $options['class'],
        'function' => $options['function'],
        'filepath' => $options['filepath'],
        'filename' => $options['filename']
        ),
      'params' => isset( $options['params'] ) ? $options['params'] : array()
      );


    $job_type_dir = $this->queue_dir . "{$options['type']}/";


    if ( !is_dir( $job_type_dir ) ) {
      $dir_created = @mkdir( $job_type_dir, DIR_WRITE_MODE, true );

      if ( !$dir_created ) {
        $error = error_get_last();
        log_message( 'error', sprintf( "%s: Failed to create directory '{$job_type_dir}': {$error['message']}", __METHOD__ ) );
      }
    }

    $job_file = $job_type_dir . "{$options['id']}.json";

    $bytes = @file_put_contents( $job_file, json_encode( $job ) );
    
    if ( $bytes === false ) {
      $error = error_get_last();
      log_message( 'error', sprintf( "%s: Failed to write to '{$job_file}': {$error['message']}", __FUNCTION__ ) );

      return false;
    }

    log_message( 'info', sprintf( "%s: New {$options['type']} job added to the queue", __METHOD__ ) );
    log_message( 'debug', sprintf( '%s: Job info: %s', __METHOD__, var_export( $job, true ) ) );

    return true;
  }

  public function process()
  {
    $jobs_by_type = $this->getJobs();

    foreach( $jobs_by_type as $type => $jobs )
    {
      foreach( $jobs as $job )
      {
        // if a job has a specified start time, dont run it until its ready.
        if ( !is_null( $job->start_time ) && $job->start_time > time() ) {
          log_message( 'debug', sprintf("%s, Job id #{$job->id} skipped.", __METHOD__ ) );
          continue;
        }

        $processed = $this->runJob( $job );

        if ( !$processed ) {
          log_message( 'error', sprintf( "%s: Failed to process {$type} job {$job->id}", __METHOD__ ) );
          continue;
        }

        $this->delete( $job->id, $type );
      }
    }
  }

  public function delete( $id, $type = "*" )
  {
    $files = glob( $this->queue_dir . "{$type}/{$id}.json" );

    foreach( $files as $file )
    {
      $deleted = @unlink( $file );
      
      if ( !$deleted ) {
        $error = error_get_last();
        log_message( 'error', sprintf( "%s: Failed to delete '{$file}': {$error['message']}", __METHOD__ ) );
      }
    }
  }

  public function exists( $id, $type = '*' )
  {
    $id = $id ?: '*';

    $pathnames = glob( $this->queue_dir . "{$type}/{$id}.json" );

    return ( is_array( $pathnames ) && count( $pathnames ) > 0 );
  }

  /** 
  Jobs need to return a true boolean to be considered successful. Will trigger an error if not.
   */
  private function runJob( $job, $log = true )
  {
    // we're trying to run a job from within the libraries directory so lets load the library
    if ( strtolower( $job->process->filepath ) === 'libraries' ) {
      $lib = $job->process->class . $job->process->function;
      $this->load->library( $job->process->class, null, $lib );

      return call_user_func_array( array( $this->$lib, $job->process->function ), $job->params );
    }

    $filepath = APPPATH . "{$job->process->filepath}/{$job->process->filename}";

    if ( isset( $this->_objects[$job->process->class] ) ) {
      if ( !method_exists( $this->_objects[$job->process->class], $job->process->function ) ) {
        return false;
      }
    } else {
      class_exists( $job->process->class, FALSE ) OR require_once(  $filepath );
      
      if ( !class_exists( $job->process->class ) OR ! method_exists( $job->process->class, $job->process->function ) ) {
        return false;
      }
  
      $this->_objects[$job->process->class] = new $job->process->class();
    }

    return call_user_func_array( array( $this->_objects[$job->process->class], $job->process->function ), $job->params );
  }

  private function recursiveDirectories2Array( $dir, $jobType = null )
  {
    $result = array();

    $items = @scandir( $dir );

    if ( $items === false ) {
      $error = error_get_last();
      log_message( 'error', sprintf( "%s: Failed to scan directory '{$this->queue_dir}': {$error['message']}", __METHOD__ ) );

      return $result;
    }

    foreach ( $items as $key => $value )
    { 
          if ( in_array( $value, array( '.','..', '.DS_Store', '.gitignore' ) ) ) {
            continue;
          }
            
        if ( is_dir( "{$dir}/{$value}" ) ) { 
          
            $result[$value] = $this->recursiveDirectories2Array( "{$dir}/{$value}" );

            if ( $jobType === $value ) {
              return $result;
            }

        } else { 
          $result[] = $value;
        }
      } 
      return $result; 
  }
}