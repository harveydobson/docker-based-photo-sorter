# docker-based-photo-sorter
A quick project to sort my 2TB+ of photos into dated folders.

This script is designed for photos and videos, but will sort all files into folders.

### Supported handling

 - For image files (mime_content_types: `image/*`) it will check for the true creation date using EXIF before comparing system file dates using `filemtime()` and `filectime()`.
 - For video files (mime_content_types: `video/*`, `audio/mp4`,`application/octet-stream`) it will check for a creation date using FFMPEG before comparing system file dates using `filemtime()` and `filectime()`. For some reason videos from some devices are treated as mime_type `audio/mp4`.
 - For all other files, it will assume the date using using `filemtime()` and `filectime()`. 



### There are 3 processes.

 - Indexing
   - The main process facilitats the indexing, this will scan the files, pick up meta data such as creation time and then store in a database. 
 - Hashing
   - The hashing processes are facilitated by the workers. These run along side the indexing process, calculating a checksum to identify duplicate files. This checksum is updated in the database.
   - The workers will sometimes overtake the indexing process if processing old photos (smaller file sizes). If this happens, they will sleep for 5 - 30 seconds each to allow the queue to build up again.
 - Moving
   - Once the indexing process has completed and all files have checksums assigned, the main process will start moving the files to named directories.
   - If the indexing process has completed but not all files have checksums assigned, the moving process will sleep for 15 seconds before checking again.
   - Unique images are moved to an output folder `output/YYYY-MM-DD/`. If the meta data identifies a make and model for the device, it will create a sub-directory inside this folder `output/YYYY-MM-DD/make_model/` this is useful if you are organising photos from multiple devices say a phone and camera.
   - Upon moving, the content of the file isn't changed, but the script will add the 'Creation Date/Date Taken' timestamp to the start of the filename i.e. `DSC08812.JPG` becomes `20200421143144_DSC08812.JPG`. This is done both to create a truely unique filename, but also for improved chronological viewing as there will be times when
   - Finally once moved, the script will create a `file_index_backup_<date>.sql` export of the 'files' table for future use. I added this feature in right at the end as I managed to corrupt my database by forcing docker to quit. It helps for this kind of recovery, but more so it helps should you ever want to pick up where you left off further down the line.  

## To use

Dependencies: `docker`, `docker-compose`

- Copy `.env.example` to `.env` and update the `DATA_DIR` path to point to the directory you want sorting.
- Run `./start.sh`  in your shell terminal or `./start.bat` for cmd / powershell. 


### Fine tuning & Troubleshooting

 - You can modify the `docker-compose.yaml` to adjust the number of workers, either add more definitions or comment out the existing ones. 
 - If you run into an issue or change directory, you should remove the mariadb/mysql data directory before re-processing...  If you are doing small jobs,  you may want to unmap this completely. If you have this data directory mapped, the script can start where it left off, which can be useful for large directories that may take several days to process. 
 - You can connect to the MySQL database on port `33306` (notice the three 3s). `Username: root Password: root`
 
