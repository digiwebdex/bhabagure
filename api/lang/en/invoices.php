<?php

return [
    'issued_frozen' => 'This invoice can\'t be changed here: a booking\'s invoice is changed on its booking, and a cancelled one stays as it is.',
    'below_paid' => 'BDT :paid has already been paid on this invoice, so its total can\'t be less than that. Reverse the payment first to go lower.',
    'needs_a_line' => 'An invoice needs at least one line and a total above zero.',
    'issued_no_delete' => 'An issued invoice is never deleted — the customer has a copy. Void it instead.',
    'reminder_needs_issue' => 'Issue this invoice before reminding anyone about it.',
    'reminder_no_address' => 'There is no phone number or email address on file to send this to.',
    'phone_invalid' => 'Enter a Bangladeshi mobile number, e.g. 01711-000000.',
    'product_exists' => 'A product with this name already exists — pick it from the list.',
];
