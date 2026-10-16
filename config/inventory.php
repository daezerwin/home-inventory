<?php

return [

    /*
    | Hosts product photos may be downloaded from, comma separated. Leave
    | empty to allow any public host; private and internal addresses are
    | always refused.
    */
    'image_hosts' => array_values(array_filter(array_map(
        fn ($host) => strtolower(trim($host)),
        explode(',', (string) env('INVENTORY_IMAGE_HOSTS', '')),
    ))),

    /*
    | Downloaded photos are scaled down to fit this many pixels on their
    | longest side and stored as WebP. Set to 0 to keep the original file.
    */
    'image_max_size' => (int) env('INVENTORY_IMAGE_MAX_SIZE', 600),

];
