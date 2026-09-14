<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\Passports\PassportScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport scan upload from the booking modal's traveller step. Answers with a single-use token (sent back with the
 * booking to attach the scan) and OCR suggestions the traveller checks. Never fails the booking: OCR problems come back
 * as status `unavailable`.
 */
class PublicPassportScanController extends Controller
{
    public function store(Request $request, PassportScanner $scanner): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.config('bhabaghure.passport_ocr.max_upload_kb')],
        ], [
            'file.*' => __('booking.scan_unreadable'),
        ]);

        $result = $scanner->scan($request->file('file'), $request->ip());

        return response()->json(['data' => $result], Response::HTTP_CREATED);
    }
}
