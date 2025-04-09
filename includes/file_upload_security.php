<?php
function secure_file_upload($file, $allowed_types, $max_size, $destination) {
    // Check file size
    if ($file['size'] > $max_size) {
        throw new Exception("File is too large");
    }
    
    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $file_type = $finfo->file($file['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception("Invalid file type");
    }
    
    // Generate safe filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $upload_path = $destination . $new_filename;
    
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception("Failed to upload file");
    }
    
    return $new_filename;
}
?>
