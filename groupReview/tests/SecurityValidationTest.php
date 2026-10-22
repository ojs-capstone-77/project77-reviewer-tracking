<?php

/**
 * @file tests/SecurityValidationTest.php
 *
 * php groupReview/tests/SecurityValidationTest.php
 */

require_once dirname(__DIR__) . '/classes/ReferenceLetterhead.php';
require_once dirname(__DIR__) . '/classes/security/RequestValidation.php';

use APP\plugins\generic\groupReview\classes\ReferenceLetterhead;
use APP\plugins\generic\groupReview\classes\security\RequestValidation;

$checks = 0;
function checkSecurity(bool $condition, string $name): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $checks++;
}
function rejectSecurity(callable $action, string $name): void
{
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        checkSecurity(true, $name);
        return;
    }
    throw new RuntimeException($name . ': expected rejection');
}

try {
    foreach (['groupReviewLetterhead.png', 'groupReviewLetterhead.jpg', 'groupReviewLetterhead.gif'] as $name) {
        checkSecurity(ReferenceLetterhead::validName($name), 'Plugin-owned raster filename');
    }
    foreach (['../2/logo.png', '..\\2\\logo.png', 'logo.png', 'groupReviewLetterhead.html',
        'groupReviewLetterhead.svg', "groupReviewLetterhead.png\n", ['name']] as $name) {
        checkSecurity(!ReferenceLetterhead::validName($name), 'Untrusted filename rejected');
    }
    $current = ['uploadName' => 'groupReviewLetterhead.png', 'name' => 'Original.png', 'width' => 1, 'height' => 1];
    ReferenceLetterhead::validateValue($current + ['altText' => 'Accessible description'], $current);
    checkSecurity(true, 'Existing metadata accepted');
    ReferenceLetterhead::validateValue(null, $current);
    checkSecurity(true, 'Owned image can be removed');
    foreach ([['uploadName' => '../2/logo.png'], ['uploadName' => 'groupReviewLetterhead.jpg'],
        ['temporaryFileId' => []], ['temporaryFileId' => '1suffix'], ['temporaryFileId' => 0],
        ['temporaryFileId' => 1, 'altText' => []], ['temporaryFileId' => 1, 'altText' => str_repeat('a', 1001)]] as $value) {
        rejectSecurity(fn () => ReferenceLetterhead::validateValue($value, $current), 'Forged metadata blocked');
    }
    rejectSecurity(fn () => ReferenceLetterhead::validateValue(null, ['uploadName' => '../2/logo.png']), 'Previously forged deletion path blocked');

    $image = tempnam(sys_get_temp_dir(), 'grp-security-');
    $html = tempnam(sys_get_temp_dir(), 'grp-security-');
    try {
        file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX4sAAAAASUVORK5CYII='));
        file_put_contents($html, '<!doctype html><script>window.securityTest = true;</script>');
        checkSecurity(ReferenceLetterhead::image($image, 'image/png') === ['width' => 1, 'height' => 1], 'Actual PNG accepted');
        file_put_contents($image, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
        checkSecurity(ReferenceLetterhead::image($image, 'image/gif') === ['width' => 1, 'height' => 1], 'Actual GIF accepted');
        foreach (['text/html', 'text/javascript', 'image/svg+xml', 'application/pdf'] as $mime) {
            rejectSecurity(fn () => ReferenceLetterhead::image($html, $mime), 'Active content cannot be published as a letterhead');
        }
        // MIME spoofing still needs a genuine image header.
        rejectSecurity(fn () => ReferenceLetterhead::image($image, 'image/jpeg'), 'Declared MIME must match image bytes');
        rejectSecurity(fn () => ReferenceLetterhead::image($html, 'image/png'), 'HTML with a spoofed image MIME is rejected');
        rejectSecurity(fn () => ReferenceLetterhead::image($image . '.missing', 'image/png'), 'Missing upload is rejected');
        file_put_contents($image, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 10001, 1) . "\x08\x02\x00\x00\x00");
        rejectSecurity(fn () => ReferenceLetterhead::image($image, 'image/png'), 'Image dimensions are bounded before decoding');
        file_put_contents($image, str_repeat('x', 5 * 1024 * 1024 + 1));
        clearstatcache(true, $image);
        rejectSecurity(fn () => ReferenceLetterhead::image($image, 'image/png'), 'Oversized uploads rejected before decoding');
    } finally {
        unlink($image);
        unlink($html);
    }
    $request = new class {
        public array $vars = [];
        public function getUserVar($key) { return $this->vars[$key] ?? null; }
    };
    foreach (['pollId', 'formId', 'submissionId', 'reviewRoundId', 'slotId', 'reviewerId'] as $key) {
        foreach ([[], '1 OR 1=1', '1suffix', '0', '-1', true] as $bad) {
            $request->vars = [$key => $bad];
            rejectSecurity(fn () => RequestValidation::validate($request), "Invalid identifier: {$key}");
        }
        $request->vars = [$key => '42'];
        RequestValidation::validate($request);
        checkSecurity(true, "Valid identifier: {$key}");
    }
    foreach (['noMeeting', 'sendReminder', 'preview', 'multiple'] as $key) {
        foreach ([[], 'false', 'yes', '2'] as $bad) {
            $request->vars = [$key => $bad];
            rejectSecurity(fn () => RequestValidation::validate($request), "Invalid flag: {$key}");
        }
    }
    foreach (['memberIds', 'slotIds', 'selectedUserIds'] as $key) {
        foreach (['1', [['1']], ['1tail'], array_fill(0, 101, 1)] as $bad) {
            $request->vars = [$key => $bad];
            rejectSecurity(fn () => RequestValidation::validate($request), "Invalid identifier list: {$key}");
        }
        $request->vars = [$key => ['1', 2]];
        RequestValidation::validate($request);
        checkSecurity(true, "Valid identifier list: {$key}");
    }
    foreach (['letterContent', 'fileName', 'meetingUrl', 'notes', 'name', 'label', 'typeKey', 'valueKey',
        'generalComments', 'submissionComment', 'configurationToken', 'values'] as $key) {
        $request->vars = [$key => [['nested']]];
        rejectSecurity(fn () => RequestValidation::validate($request), "Malformed text: {$key}");
    }
    $request->vars = ['letterContent' => str_repeat('a', 100001)];
    rejectSecurity(fn () => RequestValidation::validate($request), 'Bound PDF input size');
    $request->vars = ['fileName' => str_repeat('a', 256)];
    rejectSecurity(fn () => RequestValidation::validate($request), 'Bound download filename');
    $request->vars = ['year' => [], 'sort' => [], 'columns' => []];
    RequestValidation::validate($request);
    checkSecurity(true, 'Grid/year defaults remain owned by their existing validators');
    echo "Passed {$checks} security validation checks.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL {$error}\n");
    exit(1);
}
