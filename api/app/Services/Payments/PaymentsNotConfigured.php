<?php

namespace App\Services\Payments;

use RuntimeException;

/** SSLCommerz store credentials are missing from .env. Online payment answers "not available"; nothing else breaks. */
class PaymentsNotConfigured extends RuntimeException {}
