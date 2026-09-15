<?php
declare(strict_types=1);

/**
 * Shared inline-SVG icon set (24x24, stroke-based, outline style) so pages
 * don't each hand-roll their own <svg> markup. Returns raw SVG string —
 * caller embeds it directly, no escaping needed since it's not user input.
 */
function partambus_icon(string $name, int $size = 20): string
{
    $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"></path><path d="M5 10v10h14V10"></path><path d="M9.5 20v-6h5v6"></path>',
        'cart' => '<circle cx="9" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.5 3h2l2.6 12.2a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 2-1.6L21 8H6"></path>',
        'money' => '<rect x="2.5" y="6" width="19" height="12" rx="2"></rect><circle cx="12" cy="12" r="3"></circle><path d="M6 6v0M18 18v0"></path>',
        'invoice' => '<path d="M7 3h8l4 4v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"></path><path d="M14 3v4h4"></path><path d="M9 12h6M9 15.5h6M9 8.5h3"></path>',
        'box' => '<path d="M12 3 3.5 7.5 12 12l8.5-4.5Z"></path><path d="M3.5 7.5V16l8.5 4.5V12"></path><path d="M20.5 7.5V16L12 20.5"></path>',
        'users' => '<circle cx="9" cy="8" r="3.2"></circle><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"></path><path d="M16.5 5.5a3.2 3.2 0 0 1 0 6.2"></path><path d="M21 20c0-2.8-1.9-5.1-4.5-5.8"></path>',
        'gear' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 13.5a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V19a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"></path>',
        'pie' => '<path d="M12 2a10 10 0 1 0 10 10H12Z"></path><path d="M12 2v10h10A10 10 0 0 0 12 2Z"></path>',
        'bar' => '<line x1="5" y1="20" x2="5" y2="11"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="19" y1="20" x2="19" y2="14"></line>',
        'tag' => '<path d="M20.5 12.5 12.7 20.3a1.5 1.5 0 0 1-2.1 0l-7-7a1.5 1.5 0 0 1 0-2.1L11.4 3.4 20.5 4Z"></path><circle cx="15.5" cy="8.5" r="1.5"></circle>',
        'truck' => '<rect x="1.5" y="7" width="13" height="10" rx="1"></rect><path d="M14.5 10.5H18l3 3.2V17h-3"></path><circle cx="6" cy="18.5" r="1.6"></circle><circle cx="17" cy="18.5" r="1.6"></circle>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="16" rx="2"></rect><line x1="3.5" y1="10" x2="20.5" y2="10"></line><line x1="8" y1="3" x2="8" y2="7"></line><line x1="16" y1="3" x2="16" y2="7"></line>',
        'shield-check' => '<path d="M12 3 4.5 6v6c0 4.8 3.2 7.9 7.5 9 4.3-1.1 7.5-4.2 7.5-9V6Z"></path><path d="m9 12 2 2 4-4.5"></path>',
        'download' => '<path d="M12 4v11"></path><path d="m7 10.5 5 4.5 5-4.5"></path><path d="M4.5 19.5h15"></path>',
        'bell' => '<path d="M6 9.5a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 13.5 6 9.5Z"></path><path d="M10 18.5a2 2 0 0 0 4 0"></path>',
        'check' => '<path d="m4 12.5 5.5 5.5L20 7"></path>',
        'warning' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        'arrow-up' => '<line x1="12" y1="19" x2="12" y2="5"></line><polyline points="6 11 12 5 18 11"></polyline>',
        'arrow-down' => '<line x1="12" y1="5" x2="12" y2="19"></line><polyline points="6 13 12 19 18 13"></polyline>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"></polyline>',
        'arrow-right' => '<line x1="4" y1="12" x2="20" y2="12"></line><polyline points="13 5 20 12 13 19"></polyline>',
        'info' => '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="11" x2="12" y2="16"></line><circle cx="12" cy="8" r="0.5" fill="currentColor" stroke="currentColor"></circle>',
        'x-circle' => '<circle cx="12" cy="12" r="9"></circle><line x1="9" y1="9" x2="15" y2="15"></line><line x1="15" y1="9" x2="9" y2="15"></line>',
        'layers' => '<polygon points="12 3 21 8 12 13 3 8 12 3"></polygon><polyline points="3 13 12 18 21 13"></polyline><polyline points="3 17.5 12 22.5 21 17.5"></polyline>',
        'chevrons-left' => '<polyline points="11 17 6 12 11 7"></polyline><polyline points="18 17 13 12 18 7"></polyline>',
        'search' => '<circle cx="10.5" cy="10.5" r="7"></circle><line x1="20" y1="20" x2="15.5" y2="15.5"></line>',
        'edit' => '<path d="M4 20.5h4l11-11a2 2 0 0 0-4-4l-11 11v4Z"></path><line x1="13.5" y1="6.5" x2="17.5" y2="10.5"></line>',
        'printer' => '<path d="M6 9V3h12v6"></path><rect x="4.5" y="9" width="15" height="7" rx="1.5"></rect><path d="M6 15h12v6H6Z"></path>',
        'save' => '<path d="M5 3h11l3 3v15H5Z"></path><path d="M8 3v6h8V3"></path><path d="M8 21v-7h8v7"></path>',
        'trash' => '<path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
        'calculator' => '<rect x="4.5" y="2.5" width="15" height="19" rx="2"></rect><line x1="7.5" y1="6.5" x2="16.5" y2="6.5"></line><line x1="7.5" y1="11" x2="7.5" y2="11.01"></line><line x1="12" y1="11" x2="12" y2="11.01"></line><line x1="16.5" y1="11" x2="16.5" y2="11.01"></line><line x1="7.5" y1="15" x2="7.5" y2="15.01"></line><line x1="12" y1="15" x2="12" y2="15.01"></line><line x1="16.5" y1="15" x2="16.5" y2="15.01"></line><line x1="7.5" y1="19" x2="16.5" y2="19"></line>',
        'dot' => '<circle cx="12" cy="12" r="6" fill="currentColor" stroke="none"></circle>',
        'wallet' => '<rect x="2.5" y="6.5" width="19" height="13" rx="2"></rect><path d="M2.5 10.5h19"></path><circle cx="17" cy="14.5" r="1.3" fill="currentColor" stroke="none"></circle>',
        'plus-circle' => '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line>',
        'filter' => '<path d="M3 4h18l-7 8.5V19l-4 2v-8.5L3 4Z"></path>',
        'refresh' => '<path d="M3.5 12a8.5 8.5 0 0 1 14.4-6.1L20.5 8.5"></path><path d="M20.5 3.5v5h-5"></path><path d="M20.5 12a8.5 8.5 0 0 1-14.4 6.1L3.5 15.5"></path><path d="M3.5 20.5v-5h5"></path>',
        'box-off' => '<path d="M12 3 3.5 7.5 12 12l8.5-4.5Z"></path><path d="M3.5 7.5V16l8.5 4.5V12"></path><path d="M20.5 7.5V16L12 20.5"></path><line x1="3" y1="21" x2="21" y2="3"></line>',
        'trending-up' => '<polyline points="3 17 9.5 10.5 13.5 14.5 21 6.5"></polyline><polyline points="15 6.5 21 6.5 21 12.5"></polyline>',
        'sun' => '<circle cx="12" cy="12" r="4.5"></circle><line x1="12" y1="2.5" x2="12" y2="5"></line><line x1="12" y1="19" x2="12" y2="21.5"></line><line x1="4.2" y1="4.2" x2="6" y2="6"></line><line x1="18" y1="18" x2="19.8" y2="19.8"></line><line x1="2.5" y1="12" x2="5" y2="12"></line><line x1="19" y1="12" x2="21.5" y2="12"></line><line x1="4.2" y1="19.8" x2="6" y2="18"></line><line x1="18" y1="6" x2="19.8" y2="4.2"></line>',
        'moon' => '<path d="M20.5 14.5A8.5 8.5 0 1 1 9.5 3.5a7 7 0 0 0 11 11Z"></path>',
        'monitor' => '<rect x="2.5" y="4" width="19" height="13" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>',
        'globe' => '<circle cx="12" cy="12" r="9"></circle><line x1="3" y1="12" x2="21" y2="12"></line><path d="M12 3a13.5 13.5 0 0 1 0 18a13.5 13.5 0 0 1 0-18Z"></path>',
        'bag' => '<path d="M6 8h12l1.2 12.5a1.5 1.5 0 0 1-1.5 1.5H6.3a1.5 1.5 0 0 1-1.5-1.5Z"></path><path d="M9 8V6a3 3 0 0 1 6 0v2"></path>',
    ];

    $inner = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
