<?php

namespace App\Constant;

class CommonConstant
{
    // Define constants for the payment statuses
    const STATUS_PAID = 0;
    const STATUS_HANDLED = 1;
    const STATUS_REFUND = 2;
    const STATUS_ATTEMPT = 3;
    const STATUS_ERROR = 4;
    const STATUS_VOID = 5;

    /**
     * Get the human-readable status.
     *
     * @return string
     */
    public static $paymentStatus = [
        self::STATUS_PAID => 'Paid',
        self::STATUS_HANDLED => 'Handled',
        self::STATUS_REFUND => 'Refund',
        self::STATUS_ATTEMPT => 'Attempt',
        self::STATUS_ERROR => 'Error',
        self::STATUS_VOID => 'Void',
    ];
}
