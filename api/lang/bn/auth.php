<?php

// Numbers in these messages (e.g. :seconds) are substituted as Latin digits by Laravel; screens that show
// them to customers render their own copy through @bhabaghure/format instead of these strings.
return [

    'failed' => 'এই তথ্যের সাথে কোনো অ্যাকাউন্ট মেলেনি।',
    'password' => 'পাসওয়ার্ডটি সঠিক নয়।',
    'throttle' => 'অনেকবার চেষ্টা করা হয়েছে। :seconds সেকেন্ড পরে আবার চেষ্টা করুন।',
    'suspended' => 'এই অ্যাকাউন্টটি স্থগিত। অ্যাডমিনের সাথে যোগাযোগ করুন।',
    'password_change_required' => 'চালিয়ে যেতে নতুন পাসওয়ার্ড দিন।',
    'session_expired' => 'সেশন শেষ হয়েছে। আবার সাইন ইন করুন।',
    'unauthenticated' => 'অনুগ্রহ করে সাইন ইন করুন।',
    'forbidden' => 'এই কাজের অনুমতি আপনার নেই।',

    // দুই ধাপে সাইন ইন (docs/two-step-sign-in.md)।
    'two_factor' => [
        'code_required' => 'অথেনটিকেটর অ্যাপের ৬ অঙ্কের কোডটি দিন।',
        'invalid_code' => 'কোডটি সঠিক নয়, অথবা আগেই ব্যবহার হয়েছে। অথেনটিকেটর অ্যাপের সর্বশেষ কোড, অথবা একটি রিকভারি কোড দিন।',
        'too_many_attempts' => 'অনেকবার ভুল কোড দেওয়া হয়েছে। :seconds সেকেন্ড পরে আবার চেষ্টা করুন।',
        'setup_required' => 'চালিয়ে যাওয়ার আগে দুই ধাপে সাইন ইন চালু করুন: সুপার অ্যাডমিন এটি বাধ্যতামূলক করেছেন।',
        'already_on' => 'দুই ধাপে সাইন ইন আগেই চালু আছে।',
        'not_started' => 'আগে সেটআপ শুরু করুন: QR কোড স্ক্যান করে একটি কোড দিন।',
        'not_on' => 'দুই ধাপে সাইন ইন চালু নেই।',
        'wrong_password' => 'পাসওয়ার্ডটি সঠিক নয়।',
        'required_by_policy' => 'সুপার অ্যাডমিন দুই ধাপে সাইন ইন বাধ্যতামূলক করেছেন, তাই এটি বন্ধ করা যাবে না। ফোন হারালে সুপার অ্যাডমিনকে রিসেট করতে বলুন।',
        'super_admin_only' => 'শুধু সুপার অ্যাডমিন কারও দুই ধাপে সাইন ইন রিসেট করতে পারেন।',
        'reset_own' => 'নিজেরটি প্রোফাইল থেকে কোড দিয়ে বন্ধ করুন, অথবা অন্য একজন সুপার অ্যাডমিনকে বলুন।',
    ],

    // Neutral on purpose: never confirm that a number or address belongs to a customer.
    'code_throttled' => 'কিছুক্ষণ আগে একটি কোড পাঠানো হয়েছে। :seconds সেকেন্ড পরে আবার চাইতে পারবেন।',
    'code_undeliverable' => 'এই মুহূর্তে লগইন কোড পাঠানো যাচ্ছে না। অনুগ্রহ করে আমাদের অফিসে ফোন বা WhatsApp করুন।',
    'code_invalid' => 'কোডটি ভুল অথবা মেয়াদ শেষ। নতুন কোড চান।',
    'name_required' => 'লগইন শেষ করতে আপনার নাম লিখুন।',
    'portal_disabled' => 'এই নম্বরের জন্য পোর্টালে লগইন বন্ধ রাখা হয়েছে। অনুগ্রহ করে আমাদের অফিসে যোগাযোগ করুন।',

];
