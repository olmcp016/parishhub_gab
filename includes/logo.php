<?php
/**
 * PARISHHUB — Parish Logo Helper
 *
 * To use your own logo instead of the default "P" crest, just save your
 * image as public/img/logo.png (recommended: a square image, at least
 * 200x200px, transparent background works best). Every crest across the
 * site — sidebar, public nav, auth pages — will automatically pick it up.
 * No code changes needed. Remove/rename the file to fall back to the
 * default text crest again.
 */

function hasParishLogo(): bool
{
    return file_exists(BASE_PATH . '/public/img/logo.png');
}

/** Markup for the crest — an <img> if a logo has been added, else the "P" fallback. */
function crestMarkup(string $alt = 'Parish Logo'): string
{
    if (hasParishLogo()) {
        return '<img src="' . url('public/img/logo.png') . '" alt="' . e($alt) . '" class="crest-img">';
    }
    return 'P';
}
