<?php

namespace App\Enums;

/** Every CMS list: only `published` rows reach the public API. */
enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
