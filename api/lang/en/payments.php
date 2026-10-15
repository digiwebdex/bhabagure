<?php

return [
    'not_reversible' => 'This entry can’t be reversed: it is an online payment, a fee line, a reversal, or already reversed.',
    'opening_exists' => 'This account already has its opening balance. Correct it with a balance adjustment entry.',
    'exceeds_due' => 'The payment is more than what is still due on this deal.',
    'deal_closed' => 'This deal is void and takes no payments.',
    'void_has_payments' => 'Reverse the payments on this deal before voiding it.',
    // Edit and Delete on the cash book (docs/transaction-edits.md).
    'edit_nothing' => 'Nothing was changed.',
    'edit_exceeds_due' => 'That is more than is owed on it (the entry\'s own amount counted back in).',
    'edit_category' => 'That category doesn\'t go with money in this direction.',
    'correction_refused' => [
        'is_reversal' => 'This entry cancels another one; it can\'t be changed itself.',
        'reversed' => 'This entry was already edited or deleted.',
        'online' => 'Online payments are refunded through the gateway, not changed here.',
        'vat' => 'A VAT payment can\'t be changed here.',
        'fee_line' => 'Charge and fee lines follow their payment.',
        'payroll' => 'A salary paid from Payroll: delete it here and pay it again from Payroll.',
        'bonus' => 'A bonus payout: delete it here and pay it again from Bonus.',
        'charge' => 'This payment came with a bKash charge: delete it and record it again on the booking.',
    ],
    // Payment requests (docs/payment-requests.md): the customer reads these on the booking page and in the portal.
    'requests' => [
        'booking_closed' => 'This booking is closed and takes no more payments. Please contact our office.',
        'nothing_due' => 'Nothing is due on this booking.',
        'over_due' => 'That is more than is still due on this booking.',
        'unknown_account' => 'Choose one of the accounts shown under How to pay.',
        'too_many_pending' => 'Your earlier payments are still being checked. Please wait until we confirm them.',
        'duplicate_reference' => 'A payment with this transaction or reference number was already sent.',
        'already_decided' => 'This request was already approved or rejected.',
        'bkash_charge' => 'A bKash charge needs the bKash charge set in Site settings and the transaction ID on the request.',
    ],
];
