<?php

namespace App\Services\Reviews;

use RuntimeException;

/** A customer's review that someone has already approved or rejected (docs/customer-reviews.md). */
final class ReviewAlreadyDecided extends RuntimeException {}
