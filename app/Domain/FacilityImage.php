<?php
// Saves an uploaded venue photo. Author: Goh Jian Yu

namespace App\Domain;

use App\ValidationException;

// Only the four image types the brief allows are accepted, and the type is read
// from the file's own contents rather than from anything the browser sent. Both
// the filename and the Content-Type header come from the client, so both can be
// lies: photo.jpg can hold PHP source, and a real .php file can be sent with a
// Content-Type of image/jpeg. getimagesize() is the check that cannot be
// talked round, because it has to actually parse the image header.
//
// The stored name is thrown away too. A generated name means an upload cannot
// overwrite an existing file, cannot walk out of the folder with ../, and
// cannot arrive with a double extension like shell.php.jpg.
class FacilityImage
{
    const MAX_BYTES = 3145728;

    // folder under public/, so the browser can load what is saved
    const FOLDER = 'static/uploads/facilities';

    // extension => the type getimagesize() must report for it
    const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    // Returns the path to store in the database, or null when the owner did not
    // pick a file. Throws ValidationException so the form can show the reason
    // next to the field, like every other rule.
    public static function save($file)
    {
        if (!is_array($file) || !isset($file['error'])) {
            return null;
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            FacilityImage::reject('That image is too large. The limit is 3 MB.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            FacilityImage::reject('The image could not be uploaded. Please try again.');
        }

        // Confirms the file really came through PHP's upload handling and is not
        // some other path the request managed to name.
        if (!is_uploaded_file($file['tmp_name'])) {
            FacilityImage::reject('The image could not be uploaded. Please try again.');
        }

        if ($file['size'] > FacilityImage::MAX_BYTES) {
            FacilityImage::reject('That image is too large. The limit is 3 MB.');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset(FacilityImage::ALLOWED[$extension])) {
            FacilityImage::reject('Only JPG, JPEG, PNG and WEBP images are accepted.');
        }

        // The real check: getimagesize() returns false unless the bytes parse as
        // an image, and it reports the type it actually found.
        $details = @getimagesize($file['tmp_name']);

        if ($details === false || !isset($details['mime'])) {
            FacilityImage::reject('That file is not an image.');
        }

        if ($details['mime'] !== FacilityImage::ALLOWED[$extension]) {
            FacilityImage::reject('The image contents do not match its file type.');
        }

        $folder = FacilityImage::folderPath();

        if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
            FacilityImage::reject('The upload folder is missing and could not be created.');
        }

        $name = uuid() . '.' . $extension;

        if (!move_uploaded_file($file['tmp_name'], $folder . DIRECTORY_SEPARATOR . $name)) {
            FacilityImage::reject('The image could not be saved. Please try again.');
        }

        return FacilityImage::FOLDER . '/' . $name;
    }

    // Removes a photo this module saved. An outside link is left alone, and so
    // is anything outside the upload folder.
    public static function remove($stored)
    {
        if (!is_string($stored) || strpos($stored, FacilityImage::FOLDER . '/') !== 0) {
            return;
        }

        $name = basename($stored);
        $path = FacilityImage::folderPath() . DIRECTORY_SEPARATOR . $name;

        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function folderPath()
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public'
             . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, FacilityImage::FOLDER);
    }

    private static function reject($message)
    {
        throw new ValidationException(['image' => $message]);
    }
}
