<?php

namespace Modules\Transaction\Hesabe\Models;

/**
 * This class contains all parameters of Response received
 *  after successful or failed payment
 *
 * @author Hesabe
 */
class HesabeCheckoutResponseModel
{
    public $status;
    public $code;
    public $message;
    public $response;
}
