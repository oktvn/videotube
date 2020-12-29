<?php

class VideoProcessor {

    private $con;
    private $sizeLimit = 500000000; 
    private $allowedTypes = array("mp4", "flv", "webm", "mkv", "vob", "ogv", "avi", "wmv");
    private $ffmpegBinPath = "bin";

    public function __construct($con) {
        $this->con = $con;
    }

    public function upload($videoUploadData) {
        $targetDir = "uploads/videos/";
        $videoData = $videoUploadData->videoDataArray;

        $tempFilePath = $targetDir . uniqid() . basename($videoData["name"]);

        $tempFilePath = str_replace(" ", "_", $tempFilePath);

        $isValidData = $this->processData($videoData, $tempFilePath);

        if(!$isValidData) {
            return false;
        }
        
        if(move_uploaded_file($videoData["tmp_name"], $tempFilePath)) {
            //echo "File moved successfuly";

            // Create final file path
            $finalFilePath = $targetDir . uniqid() . ".mp4";

            if(!$this->insertVideoData($videoUploadData, $finalFilePath)) {
                echo "Upload failed (SQL error).";
                return false;
            }

            if(!$this->convertVideoToMp4($tempFilePath, $finalFilePath)) {
                echo "Upload failed (conversion error).";
                return false;
            }

            if(!$this->deleteFile($tempFilePath)) {
                echo "Upload failed (deletion error).";
                return false;
            }

            if(!$this->generateThumbnails($finalFilePath)) {
                echo "Upload failed (thumbnail generation error).";
                return false;
            }

            return true;
        }
    } /* End of upload() */

    private function processData($videoData, $filePath) {
        // Check extension
        $videoType = pathInfo($filePath, PATHINFO_EXTENSION);

        // Check file size
        if(!$this->isValidSize($videoData)) {
            echo "Uploaded file is too large. Please make sure it's under " . $this->sizeLimit / 1000000000 . " GB.<br>";
            return false;
        }

        else if (!$this->isValidType($videoType)) {
            echo "Invalid file type.<br>";
            return false;
        }

        else if ($this->hasError($videoData)) {
            echo "Error.<br>" . $videoData["error"];
            return false;
        }

        // Success!
        return true;
    }

    //-----------------------------------------------------------------------

    // Helpers
    private function isValidSize($data) {
        return $data["size"] <= $this->sizeLimit;
    }

    private function isValidType($type) {
        $lowercased = strtolower($type);
        return in_array($lowercased, $this->allowedTypes);
    }

    private function hasError($data) {
        return $data["error"] != 0;
    }


    private function insertVideoData($uploadData, $filePath) {
        $query = $this->con->prepare(
            "INSERT INTO videos(title, uploadedBy, description, privacy, category, filePath) VALUES(:title, :uploadedBy, :description, :privacy, :category, :filePath)"
                                    );
        $query->bindParam(":title", $uploadData->title);
        $query->bindParam(":uploadedBy", $uploadData->uploadedBy);
        $query->bindParam(":description", $uploadData->description);
        $query->bindParam(":privacy", $uploadData->privacy);
        $query->bindParam(":category", $uploadData->category);
        $query->bindParam(":filePath", $filePath);

        return $query->execute();

    }

    public function convertVideoToMp4($tempFilePath, $finalFilePath) {
        // FFmpeg
        $ffmpegCall = "$this->ffmpegBinPath/ffmpeg -i $tempFilePath $finalFilePath 2>&1";

        $outputLog = array();

        exec($ffmpegCall, $outputLog, $returnCode);

        if ($returnCode != 0 ) {
            // Failed
            foreach($outputLog as $line) {
                echo "CONVERTVIDEOTOMP4: " . $line . "<br>";
            }
            return false;
        }
        return true;
    }

    private function deleteFile($filePath) {
        if (!unlink($filePath)) {
            echo "Could not delete file! \n";
            return false;
        }
        return true;
    }

    public function generateThumbnails($filePath) {
        $thumbnailSize = "210x118";
        $thumbnailCount = 3;
        $thumbnailPath = "uploads/videos/thumbnails";

        $duration = $this->getVideoDuration($filePath);

        //echo "duration: $duration";
        $videoId = $this->con->lastInsertId();
        $this->updateDuration($duration, $videoId);

        echo "duration: $duration \n";
        echo "video ID: $videoId";

        for ($num = 1; $num <= $thumbnailCount; $num++) {
            $imageName = uniqid() . ".jpg";
            $interval = ($duration * 0.8) / $thumbnailCount * $num;
            $thumbnailFullPath = "$thumbnailPath/$videoId-$imageName";
            
            // THIS IS VERY BAD. Tofix.
            $ffmpegCall = "$this->ffmpegBinPath/ffmpeg -i $filePath -ss $interval -s $thumbnailSize -vframes 1 $thumbnailFullPath 2>&1";

            $outputLog = array();

            exec($ffmpegCall, $outputLog, $returnCode);

            if ($returnCode != 0 ) {
                echo $ffmpegCall;
                foreach($outputLog as $line) {
                    echo "GENERATETHUMBS: " . $line . "<br>";
                }
            }

            $query = $this->con->prepare("INSERT INTO thumbnails(videoID, filePath, selected)
                                        VALUES(:videoId, :filePath, :selected)");
            $query->bindParam(":videoId", $videoId);
            $query->bindParam(":filePath", $thumbnailFullPath);
            $query->bindParam(":selected", $selected);        
            
            $selected = $num == 1 ? : 0;

            $success = $query->execute();

            if(!$success) {
                echo "Error inserting thumbnail \n";
                return false;
            }
        }
            return true;
    }

    private function getVideoDuration($filePath) {
        return (int)shell_exec("$this->ffmpegBinPath/ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $filePath");
    }

    private function updateDuration($duration, $videoId) {
        // SQL insert
        $hours = floor($duration / 3600);
        $mins = floor(($duration - ($hours*3600)) / 60);
        $secs = floor($duration % 60);

        $hours = ($hours < 1) ? "" : $hours . ":";
        $mins = ($mins < 10) ? "0" . $mins . ":" : $mins . ":";
        $secs = ($secs < 10) ? "0" . $secs : $secs;

        $duration = $hours . $mins . $secs;

        $query = $this->con->prepare("UPDATE videos SET duration=:duration WHERE id=:videoId");
        $query->bindParam(":duration", $duration);
        $query->bindParam(":videoId", $videoId);
        $query->execute();

    }
    //-----------------------------------------
}

?>