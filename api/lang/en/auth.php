<?php

return [

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'suspended' => 'This account is suspended. Contact an administrator.',
    'password_change_required' => 'Set a new password before continuing.',
    'session_expired' => 'Your session has ended. Please sign in again.',
    'unauthenticated' => 'Please sign in.',
    'forbidden' => 'You do not have permission to do this.',

    // Two-step sign-in with an authenticator app (docs/two-step-sign-in.md). The admin is English only.
    'two_factor' => [
        'code_required' => 'Enter the 6-digit code from your authenticator app.',
        'invalid_code' => 'That code isn’t right, or it was already used. Enter the newest code from your authenticator app, or a recovery code.',
        'too_many_attempts' => 'Too many wrong codes. Try again in :seconds seconds.',
        'setup_required' => 'Set up two-step sign-in before continuing: the super admin requires it.',
        'already_on' => 'Two-step sign-in is already on.',
        'not_started' => 'Start the setup first: scan the QR code, then enter a code.',
        'not_on' => 'Two-step sign-in isn’t on.',
        'wrong_password' => 'That password isn’t right.',
        'required_by_policy' => 'The super admin requires two-step sign-in, so it can’t be turned off. If your phone is lost, ask the super admin to reset it.',
        'super_admin_only' => 'Only the super admin can reset someone’s two-step sign-in.',
        'reset_own' => 'Turn your own off from your profile, with a code; or ask another super admin.',
    ],

    // Neutral on purpose: never confirm that a number or address belongs to a customer.
    'code_throttled' => 'A code was sent recently. You can ask for another in :seconds seconds.',
    'code_undeliverable' => 'We can\'t send sign-in codes right now. Please call or WhatsApp our office.',
    'code_invalid' => 'That code is wrong or has expired. Ask for a new one.',
    'name_required' => 'Tell us your name to finish signing in.',
    'portal_disabled' => 'Signing in to the portal is turned off for this number. Please contact our office.',

];
