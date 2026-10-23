<?php

/**
 * @file classes/ReferenceLetterhead.php
 *
 * Restricts public letterheads to plugin-owned raster images.
 */

namespace APP\plugins\generic\groupReview\classes;

use InvalidArgumentException;

class ReferenceLetterhead
{
    public static function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('/\AgroupReviewLetterhead\.(png|jpg|gif)\z/D', $name) === 1;
    }

    /** @return array{width:int, height:int} */
    public static function image(string $path, string $mime): array
    {
        $types = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG, 'image/gif' => IMAGETYPE_GIF];
        if (!isset($types[$mime]) || !is_file($path) || !is_readable($path)
            || filesize($path) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Letterheads must be PNG, JPEG or GIF images of at most 5 MB.');
        }
        set_error_handler(static function (): never {
            throw new InvalidArgumentException('The letterhead image could not be read.');
        });
        try {
            $size = getimagesize($path);
        } finally {
            restore_error_handler();
        }
        if (!$size || $size[2] !== $types[$mime] || $size[0] < 1 || $size[1] < 1
            || $size[0] > 10000 || $size[1] > 10000 || $size[0] * $size[1] > 20000000) {
            throw new InvalidArgumentException('The letterhead must be a valid raster image of at most 20 million pixels and 10000 pixels per side.');
        }
        return ['width' => $size[0], 'height' => $size[1]];
    }

    public static function validateValue(mixed $value, mixed $current): void
    {
        if ($value === null) {
            if (!empty($current['uploadName']) && !self::validName($current['uploadName'])) {
                throw new InvalidArgumentException('The stored letterhead filename is invalid. Contact the site administrator.');
            }
            return;
        }
        if (!is_array($value) || (isset($value['altText']) && (!is_string($value['altText']) || mb_strlen($value['altText']) > 1000))) {
            throw new InvalidArgumentException('The letterhead metadata is invalid.');
        }
        if (isset($value['temporaryFileId'])) {
            if ((!is_int($value['temporaryFileId']) && !is_string($value['temporaryFileId']))
                || filter_var($value['temporaryFileId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('The letterhead upload identifier is invalid.');
            }
        } elseif (!self::validName($value['uploadName'] ?? null)
            || ($value['uploadName'] ?? null) !== ($current['uploadName'] ?? null)) {
            throw new InvalidArgumentException('Letterheads must be uploaded; stored file metadata cannot be replaced.');
        }
    }
}
