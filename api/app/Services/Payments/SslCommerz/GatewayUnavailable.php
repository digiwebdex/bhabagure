<?php

namespace App\Services\Payments\SslCommerz;

use RuntimeException;

/** SSLCommerz could not be reached or answered with something unusable. Nothing about the payment is concluded. */
class GatewayUnavailable extends RuntimeException {}
