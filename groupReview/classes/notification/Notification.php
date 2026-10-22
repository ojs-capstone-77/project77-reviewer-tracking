<?php

/**
 * @file classes/notification/Notification.php
 *
 * Notification type constants for group review, retained from the delivered
 * student implementation.
 */

namespace APP\plugins\generic\groupReview\classes\notification;

class Notification extends \APP\notification\Notification
{
    public const NOTIFICATION_TYPE_POLL_CREATED = 0xA00000;
    public const NOTIFICATION_TYPE_POLL_CLOSING = 0xA00001;
    public const NOTIFICATION_TYPE_PARTICIPATION_SUBMITTED = 0xA00002;
}
