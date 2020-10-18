<?PHP

$log = '';
ini_set('max_execution_time', 0); // 0 = Unlimited
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit','6G');
error_reporting(E_ALL);

echo date('Y-m-d H:i:s');

class PhotoSorter
{
    private $data_path = '/home/data';
    private $output_dir = '/home/data/output/';
    private $log_path = '/home/log/';
    private $start_time = '';
    private $mode = 'inactive';

    public function __construct($p_data_path)
    {
        $this->data_path = $p_data_path;
        $this->start_time = time();
        $this->output_dir = "$this->data_path/output/";
    }

    public static function run($mode='full', $data_path='/home/data')
    {
        $ps = new PhotoSorter($data_path);

        $ps->mode = $mode;

        echo "=================================\n";
        echo "== Photo Sync " . date('Y-m-d H:i:s',  $ps->start_time) . "\n";
        echo "=================================\n";

        $files = array();
        $duplicates = array();

        switch($mode)
        {
            case 'full':
                
                if($ps->indexPhotos())
                    $ps->writeToLog("Completed indexing. Starting file hashing...");
                    if($ps->hashPhotos())
                        $ps->writeToLog("Completed file hashing. Starting moving process...");
                        if($ps->moveIndexedFiles())
                            $ps->writeToLog("Completed moving process. Full Run Succeded!");
            break;

            case 'index':
                if($ps->indexPhotos())
                    $ps->writeToLog("Completed indexing");
            break;
            
            case 'hash':
                if($ps->hashPhotos())
                    $ps->writeToLog("Completed file hashing");
            break;

            case 'move':
                if($ps->moveIndexedFiles())
                    $ps->writeToLog("Completed moving process");
            break;

            default:
                $ps->writeToLog('Please select a mode');
            break;
        }

    }

    function indexPhotos()
    {
        if(file_exists('moving.status'))
        {
            unlink('moving.status');
        }

        touch('index.status');

        // Connect to database
        $pdo = $this->connectToDatabase();
        
        // Create files database table (if not already existing)
        $this->createFilesTable($pdo);

        // Check for any already seen files (if table already existed)
        $already_seen_files = $this->identifyAlreadySeenFiles($pdo);

        // Recursive search for all files in data directory
        $matches = $this->rglob($this->data_path, '*.*');

        // Cycle through matches
        foreach($matches as $file)
        {

            // check if file exists
            if(!file_exists($file))
            {
                $this->writeToLog("Indexed file no longer exists $file");
                continue;
            } else {
                $this->writeToLog("Seen $file");
            }

            // check if already in database
            if(in_array($file, $already_seen_files))
            {
                 $this->writeToLog("Skipping as seen before");
                 continue;
            }

            $meta_data = PhotoSorter::getFileMetaData($file);
        
            try {
                $stmt = $pdo->prepare('INSERT INTO files (filename, source, date, filesize, make, model, mime_type) VALUES (:filename, :source, :date, :filesize, :make, :model, :mime_type)');
                $stmt->execute($meta_data);
                $user = $stmt->fetch();
            } catch (\PDOException $e) {
                $this->writeToLog($e->getMessage() . ' - ' . $e->getCode());
                die;
            }
        }


        unlink('index.status');
        return TRUE;
    }

    private function moveIndexedFiles()
    {
        if(file_exists('index.status'))
        {
            unlink('index.status');
        }

        touch('moving.status');

        $pdo = $this->connectToDatabase();

        $data = $pdo->query("SELECT * FROM files WHERE checksum IS NULL")->fetchAll();
        if (count($data) > 0) {
            $this->writeToLog("Moving process on standby... awaiting checksum completion. Will check again in 15 seconds...");
            sleep(15);
            $this->moveIndexedFiles();
            return FALSE;
        }

        $output_directory = $this->output_dir;

        $seen_checksums = array();
        
        if(!file_exists($output_directory))
            mkdir($output_directory);
        
        $stmt = $pdo->query('SELECT * FROM files WHERE duplicate != 1
                            ORDER BY CASE
                            WHEN `filename`    LIKE "%COPY%"  THEN 20
                            WHEN `filename`        LIKE "% (%" THEN 10
                            WHEN `filename`        LIKE "%(%" THEN 5
                            WHEN `filename`         LIKE "%_%" THEN 1
                            ELSE 0
                            END');
        
        while ($file = $stmt->fetch())
        {
        
            $full_path = $file['source'] . $file['filename'];
        
            if(!empty($seen_checksums[$file['checksum']]))
            {
                $this->writeToLog("Skipping duplicate $full_path");
                
                $pdo->query('UPDATE files SET updated = 1, duplicate = 1 WHERE file_id = ' . $file['file_id']);
                continue;
            } else {
                $seen_checksums[$file['checksum']] = $full_path;
            }
        
            if($file['updated'] == 1)
            {
                $this->writeToLog("File already moved (marked as updated)");
                continue;
            }
        
            if(file_exists($full_path))
            {
                $new_file_path = $output_directory . date('Y-m-d', strtotime($file['date'])) . '/';
                
                if(!file_exists($new_file_path))
                    mkdir($new_file_path);
        
                if(!empty($file['make']))
                {
                    $new_file_path .= $file['make'];
                    
                    if(!empty($file['model']))
                    {
                        $new_file_path .= '_' . $file['model'];
                    }
            
                    $new_file_path .= '/';
                }
        
                if(!file_exists($new_file_path))
                    mkdir($new_file_path);
        
                $new_filename = date('YmdHis', strtotime($file['date'])) . '_' . $file['filename'];
        
                $new_file_full_path = $new_file_path . $new_filename;
        
                rename($full_path, $new_file_full_path);
                touch($new_file_full_path, strtotime($file['date'])); // set the correct date back
        
                $this->writeToLog(": Moved " . $file['filename'] . ' from ' . $file['source'] . ' to ' . $new_file_path);
        
                $pdo->query('UPDATE files SET updated = 1 WHERE file_id = ' . $file['file_id']);
        
            } else {
                $this->writeToLog("Source file no longer exists $full_path");
            }
        }

        $this->writeToLog("Writing file index backup to /home/data/output/file_index_backup.sql - START");
        exec('mysqldump -h db -u root -proot photo-sorter files > /home/data/output/file_index_backup-'.date('Y-m-d-H-i-s').'.sql');
        $this->writeToLog("Writing file index backup to /home/data/output/file_index_backup.sql COMPLETE");

        unlink('moving.status');
        return TRUE;
    }


    private function hashPhotos()
    {
        $pdo = $this->connectToDatabase();
        // once connected, wait 5 seconds for things to get populated. 
        sleep(5);

        $this->writeToLog("Process has woken up. Ready to start...");
        $this->recursiveWorker($pdo);
    }

    private function recursiveWorker($pdo)
    {
        $memory = memory_get_usage();                       // KB  // MB
        $this->writeToLog("Memory allocation at " . ($memory / 1024 / 1024 ) . "M/" . ini_get('memory_limit'));
        $stmt = $pdo->query("SELECT * FROM files WHERE checksum IS NULL ORDER BY RAND() LIMIT 1");
        
        $file = $stmt->fetch();
    
        if(empty($file)) {
            $this->writeToLog("Nothing left in queue...");
            if(file_exists('index.status'))
            {
                $this->writeToLog("Indexing still in progress... will go to sleep");
                sleep(rand(5,30));
            } else {
                $this->writeToLog("My work here is done");
                return true;
            }
        }

        $full_path = $file['source'] . $file['filename'];
        
        if(!file_exists($full_path))
        {
            $this->writeToLog("File $full_path no longer exists..");
            $this->recursiveWorker($pdo);
            return false;
        }

        $this->writeToLog("Calculating checksum for " . $full_path);
        
        $hash = hash_file('md5', $full_path);

        $pdo->query("UPDATE files SET checksum = '$hash' WHERE file_id = '$file[file_id]'");
        
        unset($hash);
        unset($full_path);
        unset($file);

        $this->writeToLog("Saved.. Moving On");

        $this->recursiveWorker($pdo);
    }

    public static function getFileMetaData($file)
    {
        $mime_type = mime_content_type($file);

        $filename   = basename($file);
        $source     = str_replace($filename, '', $file);
        $filesize   = '';
        $make       = '';
        $model      = '';
        

        // If it's an image, we'll look for EXIF data
        if(strpos($mime_type, 'image/') === 0)
        {
            $exif = exif_read_data($file, 0, true);

            if(!empty($exif))
            {

                // Identify file's real creation date from EXIF data
                if(!empty($exif['FILE']['FileDateTime']))
                {
                    $date_file = date('Y-m-d H:i:s', $exif['FILE']['FileDateTime']);
                    // set this as default date for now
                    $date = $date_file;
                }

                if(!empty($exif['EXIF']['DateTimeOriginal']) || !empty($exif['EXIF']['DateTimeDigitized']))
                {
                    $date_exif_org = date('Y-m-d H:i:s', strtotime($exif['EXIF']['DateTimeOriginal']));
                    $date_exif_dig = date('Y-m-d H:i:s', strtotime($exif['EXIF']['DateTimeDigitized']));

                    // Work out oldest EXIF date
                    if($date_exif_org < $date_exif_dig)
                    {
                        $date_exif = $date_exif_org;
                    } else {
                        $date_exif = $date_exif_dig;
                    }
                    
                    // set this as default date for now
                    $date = $date_exif;
                }

                // Compare both to find the oldest date
                if(!empty($exif['FILE']) && !empty($exif['EXIF']))
                {
                    if($date_file < $date_exif)
                    {
                        $date = $date_file;
                    } else {
                        $date = $date_exif;
                    }
                }

                // Pick up FileSize while we're here
                $filesize = $exif['FILE']['FileSize'];

                // Also check EXIF for Make / Model info as we can further sort by device
                if(!empty($exif['IFD0']))
                {
                    $make = $exif['IFD0']['Make'];
                    $model = $exif['IFD0']['Model'];
                }

            }

        }

        // For photos we can process with FMPEG
        if( strpos($mime_type, 'video/') === 0 || 
            strpos($mime_type, 'audio/mp4') === 0 || 
            strpos($mime_type, 'application/octet-stream') === 0 )
        {
            // See if we can work out the date from FFMPEG
            $ffmpeg = shell_exec("ffprobe -v quiet '$file' -print_format json -show_entries stream");
            $ffmpeg = json_decode($ffmpeg);
            $date = '';

            if(!empty($ffmpeg->streams)) {
                foreach($ffmpeg->streams as $stream)
                {            
                    if(!empty($date))
                        continue;

                    $date = empty($stream->tags->creation_time) ? '' : date('Y-m-d H:i:s', strtotime($stream->tags->creation_time));
                }
            }

/*
            SAMPLE OUTPUT
            stdClass Object
            php_1  | (
            php_1  |     [programs] => Array
            php_1  |         (
            php_1  |         )
            php_1  |
            php_1  |     [streams] => Array
            php_1  |         (
            php_1  |             [0] => stdClass Object
            php_1  |                 (
            php_1  |                     [index] => 0
            php_1  |                     [codec_name] => h264
            php_1  |                     [codec_long_name] => H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10
            php_1  |                     [profile] => High
            php_1  |                     [codec_type] => video
            php_1  |                     [codec_time_base] => 316393/18990000
            php_1  |                     [codec_tag_string] => avc1
            php_1  |                     [codec_tag] => 0x31637661
            php_1  |                     [width] => 1920
            php_1  |                     [height] => 1080
            php_1  |                     [coded_width] => 1920
            php_1  |                     [coded_height] => 1088
            php_1  |                     [has_b_frames] => 0
            php_1  |                     [sample_aspect_ratio] => 1:1
            php_1  |                     [display_aspect_ratio] => 16:9
            php_1  |                     [pix_fmt] => yuvj420p
            php_1  |                     [level] => 40
            php_1  |                     [color_range] => pc
            php_1  |                     [color_space] => smpte170m
            php_1  |                     [color_transfer] => smpte170m
            php_1  |                     [color_primaries] => smpte170m
            php_1  |                     [chroma_location] => left
            php_1  |                     [refs] => 1
            php_1  |                     [is_avc] => true
            php_1  |                     [nal_length_size] => 4
            php_1  |                     [r_frame_rate] => 30/1
            php_1  |                     [avg_frame_rate] => 9495000/316393
            php_1  |                     [time_base] => 1/90000
            php_1  |                     [start_pts] => 0
            php_1  |                     [start_time] => 0.000000
            php_1  |                     [duration_ts] => 632786
            php_1  |                     [duration] => 7.030956
            php_1  |                     [bit_rate] => 20233175
            php_1  |                     [bits_per_raw_sample] => 8
            php_1  |                     [nb_frames] => 211
            php_1  |                     [disposition] => stdClass Object
            php_1  |                         (
            php_1  |                             [default] => 1
            php_1  |                             [dub] => 0
            php_1  |                             [original] => 0
            php_1  |                             [comment] => 0
            php_1  |                             [lyrics] => 0
            php_1  |                             [karaoke] => 0
            php_1  |                             [forced] => 0
            php_1  |                             [hearing_impaired] => 0
            php_1  |                             [visual_impaired] => 0
            php_1  |                             [clean_effects] => 0
            php_1  |                             [attached_pic] => 0
            php_1  |                             [timed_thumbnails] => 0
            php_1  |                         )
            php_1  |
            php_1  |                     [tags] => stdClass Object
            php_1  |                         (
            php_1  |                             [creation_time] => 2019-08-31T13:49:02.000000Z
            php_1  |                             [language] => eng
            php_1  |                             [handler_name] => VideoHandle
            php_1  |                         )
            php_1  |
            php_1  |                 )
            php_1  |
            php_1  |             [1] => stdClass Object
            php_1  |                 (
            php_1  |                     [index] => 1
            php_1  |                     [codec_name] => aac
            php_1  |                     [codec_long_name] => AAC (Advanced Audio Coding)
            php_1  |                     [profile] => LC
            php_1  |                     [codec_type] => audio
            php_1  |                     [codec_time_base] => 1/48000
            php_1  |                     [codec_tag_string] => mp4a
            php_1  |                     [codec_tag] => 0x6134706d
            php_1  |                     [sample_fmt] => fltp
            php_1  |                     [sample_rate] => 48000
            php_1  |                     [channels] => 2
            php_1  |                     [channel_layout] => stereo
            php_1  |                     [bits_per_sample] => 0
            php_1  |                     [r_frame_rate] => 0/0
            php_1  |                     [avg_frame_rate] => 0/0
            php_1  |                     [time_base] => 1/48000
            php_1  |                     [start_pts] => 0
            php_1  |                     [start_time] => 0.000000
            php_1  |                     [duration_ts] => 337969
            php_1  |                     [duration] => 7.041021
            php_1  |                     [bit_rate] => 95732
            php_1  |                     [max_bit_rate] => 96000
            php_1  |                     [nb_frames] => 329
            php_1  |                     [disposition] => stdClass Object
            php_1  |                         (
            php_1  |                             [default] => 1
            php_1  |                             [dub] => 0
            php_1  |                             [original] => 0
            php_1  |                             [comment] => 0
            php_1  |                             [lyrics] => 0
            php_1  |                             [karaoke] => 0
            php_1  |                             [forced] => 0
            php_1  |                             [hearing_impaired] => 0
            php_1  |                             [visual_impaired] => 0
            php_1  |                             [clean_effects] => 0
            php_1  |                             [attached_pic] => 0
            php_1  |                             [timed_thumbnails] => 0
            php_1  |                         )
            php_1  |
            php_1  |                     [tags] => stdClass Object
            php_1  |                         (
            php_1  |                             [creation_time] => 2019-08-31T13:49:02.000000Z
            php_1  |                             [language] => eng
            php_1  |                             [handler_name] => SoundHandle
            php_1  |                         )
            php_1  |
            php_1  |                 )
            php_1  |
            php_1  |         )
            php_1  |
            php_1  | )
*/
            // IF still can't work out date. Some custom handling for different naming formats
            
            $masks[] = 'YYYYMMDDHHIISS';
            $masks[] = 'YYYYMMDD_HHIISS';

            foreach($masks as $datemask)
            {
                if(empty($date)) 
                {
                    $date = PhotoSorter::datemask($filename, $datemask);
                    
                    if($date == '1970-01-01 00:00:00')
                    {
                        $date = '';
                    } else {
                        if(!empty($date))
                            echo '\n determined a date: ' . $date . '\n';
                    }

                } else {
                    continue;
                }
            }

            
            
        }

        // If date is invalid, unset date
        if(!empty($date) && $date == '1970-01-01 00:00:00')
        {
            $date = '';
        }

        if(empty($date))
        {
            // If not a photo, let's look at the file system dates available
            $date_created = filectime($file);
            $date_modified = filectime($file);

            // Find the oldest date as this is most likely to be the real creation date
            if($date_created > $date_modified)
            {
                $date = date('Y-m-d H:i:s', $date_modified);
            } else {
                $date = date('Y-m-d H:i:s', $date_created);
            }
        }

        // Set filesize if NaN or undefined
        if(empty($filesize) || !is_int($filesize))
            $filesize = filesize($file);

        $this_file = [];
        $this_file = [
            'filename' => $filename,
            'source' => $source,
            'date' => $date,
            'filesize' => $filesize,
            'make' => $make,
            'model' => $model,
            'mime_type' => $mime_type
        ];

        return $this_file;

    }

    public static function datemask($filename, $mask)
    {
        // separators
        $regex_masks['_'] = '([_])';
        $regex_masks[' '] = '([\ ])';
        $regex_masks['-'] = '([-])';

        // extension
        $regex_masks['.EXT'] = '([.][a-z0-9][a-z0-9][a-z0-9])';

        // date fields
        $regex_masks['YYYY'] = '([12]\d{3})';
        $regex_masks['MM'] = '(0[1-9]|1[0-2])';
        $regex_masks['DD'] = '(0[1-9]|[12]\d|3[01])';
        $regex_masks['HH'] = '([0-2]\d)';
        $regex_masks['II'] = '([0-5]\d)';
        $regex_masks['SS'] = '([0-5]\d)';

        // remove leading or ending chars
        $regex_masks['X'] = '';

        foreach($regex_masks as $key=>$regex)
        {
            $mask = str_replace($key, $regex, $mask);
        }

        preg_match('/' . $mask . '/', $filename, $matches);

        $format = 'Y-m-d H:i:s';

        if(!is_array($matches) || empty($matches))
        {
            return false;
        }
        
        $date = date($format, strtotime($matches[0]));

        $d = DateTime::createFromFormat($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        if($d && $d->format($format) === $date && $date != '1970-01-01 00:00:00')
        {
            return $date;
        } else {
            return FALSE;
        }        
    }

    private function rglob($dir, $pattern, $matches=array())
    {
        $dir_list = glob($dir . '*/');

        $pattern_match = glob($dir . $pattern);

        $matches = array_merge($matches, $pattern_match);
        
        foreach($dir_list as $directory)
        {        

            if(substr($directory, 0, strlen($this->output_dir)) != $this->output_dir)
            {
                $this->writeToLog("Scanning $directory");
                $matches = $this->rglob($directory, $pattern, $matches);
            }
        }

        return $matches;
    }

    private function connectToDatabase($host='db', $db='photo-sorter', $user='root', $pass='root', $charset='utf8mb4')
    {   
        $timeout_seconds = 5;

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
             $pdo = new PDO($dsn, $user, $pass, $options);
             $this->writeToLog("Connection to database succeeded");  
             // Increase prepared statement count limit
             $pdo->query('set global max_prepared_stmt_count=5000000;');
             $pdo->query('SET GLOBAL max_connections = 1000000;');
             return $pdo;
        } catch (\PDOException $e) {
             $this->writeToLog($e->getMessage() . ' - ' . $e->getCode());
             $this->writeToLog("Will try again in $timeout_seconds...");
             sleep($timeout_seconds);
             return $this->connectToDatabase();
        }
    }

    private function tableExists(PDO $pdo, $table) {

        // Try a select statement against the table
        // Run it in try/catch in case PDO is in ERRMODE_EXCEPTION.
        try {
            $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
        } catch (Exception $e) {
            // We got an exception == table not found
            return FALSE;
        }
    
        // Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
        return $result !== FALSE;
    }

    function createFilesTable(PDO &$pdo)
    {
        if(!$this->tableExists($pdo, 'files'))
        {
            $pdo->query("CREATE TABLE `files` (
                `file_id`  int(255) UNSIGNED NOT NULL AUTO_INCREMENT ,
                `filename`  varchar(200) NULL ,
                `source`  varchar(1000) NULL ,
                `date`  timestamp NULL DEFAULT CURRENT_TIMESTAMP ,
                `checksum`  varchar(200) NULL ,
                `filesize`  bigint(255) UNSIGNED NULL ,
                `make`  varchar(50) NULL ,
                `model`  varchar(50) NULL ,
                `mime_type`  varchar(50) NULL ,
                `updated`  int(1) NULL DEFAULT 0 ,
                `duplicate`  int(1) NULL DEFAULT 0 ,
                PRIMARY KEY (`file_id`)
                )");
                return TRUE;
        }

        return FALSE;
    }

    function identifyAlreadySeenFiles(PDO &$pdo)
    {
        $already_seen_files = array();

        $stmt = $pdo->query('SELECT source,filename FROM files');
        while($row = $stmt->fetch())
        {
            $already_seen_files[$row['source'] . $row['filename']] = $row['source'] . $row['filename'];
        }

        return $already_seen_files;
    }


    function writeToLog($string)
    {
        $my_log_path = $this->log_path . date('Y-m-d-H-i', $this->start_time) . '/';

        if(!file_exists($my_log_path))
        {
            mkdir($my_log_path);
        }

        $log_string = date('Y-m-d H:i:s') . ': ' . $string . "\n";

        echo $log_string;

        $file = $my_log_path . $this->mode . '-' . gethostname() . '.log';

        file_put_contents($file, $log_string, FILE_APPEND);

    }

}