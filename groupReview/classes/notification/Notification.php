<?php

namespace APP\plugins\generic\groupReview\classes\notification;

/** Notification types retained from the delivered student implementation. */
class Notification extends \APP\notification\Notification
{
    public const NOTIFICATION_TYPE_POLL_CREATED = 0xA00000;
    public const NOTIFICATION_TYPE_POLL_CLOSING = 0xA00001;
}
