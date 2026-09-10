<?php
// Saves an uploaded profile picture. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Domain;

use App\ValidationException;

/**
 * The same checks as FacilityImage (module 1), for the same reasons: the file
 * name and the Content-Type header both come from the browser, so both can be
 * lies. A file called me.jpg can hold PHP source, and a real .php file can be
 * sent with a Content-Type of image/jpeg. getimagesize() is the check that
 * cannot be talked round, because it has to parse the image header to answer.
 *
 * Where this differs from FacilityImage: a venue may have many photos over its
 * life, so those get a generated name. A player has exactly one picture, so it
 * is stored as the account's own id - static/profile/<baseUserId>.jpg. That
 * means replacing a picture overwrites the old one instead of leaving orphaned
 * files behind, and deleting an account leaves one predictable file to remove.
 *
 * The id is not taken from the request. It comes from the account the service
 * layer already resolved, so there is no path for a crafted form to write over
 * somebody else's picture. The name is still checked against the id pattern
 * before it is used, because a value that reaches a filesystem path is worth
 * checking twice.
 */
final class ProfileImage
{
    public const MAX_BYTES = 2097152;          // 2 MB - a profile picture is small

    /** Under public/, so the browser can load what is saved. */
    public const FOLDER = 'static/profile';

    /** extension => the type getimagesize() must report for it */
    public const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed>|null $file one entry from $_FILES
     * @return string|null the path to store, or null when nothing was uploaded
     * @throws ValidationException so the form can show why, next to the field
     */
    public static function save(?array $file, string $baseUserId): ?string
    {
        if (!is_array($file) || !isset($file['error'])) {
            return null;
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            self::reject('That picture is too large. The limit is 2 MB.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            self::reject('The picture could not be uploaded. Please try again.');
        }

        // Confirms the file really arrived through PHP's upload handling, and
        // is not some other path the request managed to name.
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            self::reject('The picture could not be uploaded. Please try again.');
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            self::reject('That picture is too large. The limit is 2 MB.');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            self::reject('Only JPG and PNG pictures are accepted.');
        }

        $details = @getimagesize((string) $file['tmp_name']);

        if ($details === false || !isset($details['mime'])) {
            self::reject('That file is not an image.');
        }

        if ($details['mime'] !== self::ALLOWED[$extension]) {
            self::reject('The picture contents do not match its file type.');
        }

        $id = self::safeId($baseUserId);
        $folder = self::folderPath();

        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
            self::reject('The upload folder is missing and could not be created.');
        }

        // jpeg and jpg are the same picture under two names, and a player who
        // swaps a PNG for a JPG would otherwise leave the old one behind and
        // still visible. Clear every spelling before writing the new one.
        self::removeFilesFor($id);

        $name = $id . '.' . $extension;

        if (!move_uploaded_file((string) $file['tmp_name'], $folder . DIRECTORY_SEPARATOR . $name)) {
            self::reject('The picture could not be saved. Please try again.');
        }

        return self::FOLDER . '/' . $name;
    }

    /** Removes a picture this class saved. Anything else is left alone. */
    public static function remove(?string $stored): void
    {
        if (!is_string($stored) || !str_starts_with($stored, self::FOLDER . '/')) {
            return;
        }

        $path = self::folderPath() . DIRECTORY_SEPARATOR . basename($stored);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * The browser caches by URL, and the URL never changes because the name is
     * the account id. Without this a player would upload a new picture and
     * keep being shown the old one.
     */
    public static function cacheBustedSrc(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        $src = imageSrc($stored);

        if (!str_starts_with($stored, self::FOLDER . '/')) {
            return $src;
        }

        $path = self::folderPath() . DIRECTORY_SEPARATOR . basename($stored);

        return is_file($path) ? $src . '?v=' . filemtime($path) : $src;
    }

    private static function removeFilesFor(string $id): void
    {
        foreach (array_keys(self::ALLOWED) as $extension) {
            $path = self::folderPath() . DIRECTORY_SEPARATOR . $id . '.' . $extension;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Ids are UUIDs, or the readable seed ids like usr-001. Anything else does
     * not get near a filesystem path - a name with a dot or a slash in it is
     * how an upload walks out of its folder.
     */
    private static function safeId(string $baseUserId): string
    {
        if (preg_match('/^[A-Za-z0-9-]{1,36}$/', $baseUserId) !== 1) {
            self::reject('The picture could not be saved.');
        }

        return $baseUserId;
    }

    private static function folderPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public'
             . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::FOLDER);
    }

    private static function reject(string $message): never
    {
        throw new ValidationException(['profilePicture' => $message]);
    }
}
