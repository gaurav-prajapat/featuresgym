<?php
function optimize_image($source_path, $destination_path, $quality = 85) {
    $info = getimagesize($source_path);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source_path);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source_path);
    } else {
        return false;
    }
    
    // Resize if needed
    $max_width = 1200;
    $width = imagesx($image);
    $height = imagesy($image);
    
    if ($width > $max_width) {
        $new_height = ($max_width / $width) * $height;
        $tmp = imagecreatetruecolor($max_width, $new_height);
        imagecopyresampled($tmp, $image, 0, 0, 0, 0, $max_width, $new_height, $width, $height);
        $image = $tmp;
    }
    
    // Save optimized image
    return imagejpeg($image, $destination_path, $quality);
}
?>
