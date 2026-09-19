<?php

/*
|--------------------------------------------------------------------------
| Cambodia — addresses and delivery
|--------------------------------------------------------------------------
|
| A Cambodian delivery address is given as province → district (srok/khan) →
| commune (khum/sangkat) → village or street, usually with a landmark because
| street numbering is unreliable. Delivery is priced by how far the province is
| from the warehouse in Phnom Penh.
|
*/

return [

    /*
    | The 25 provinces, keyed by their English name so the stored value stays
    | readable in the database. 'km' is what the customer sees.
    */
    'provinces' => [
        'Phnom Penh' => ['km' => 'ភ្នំពេញ', 'zone' => 'city'],

        'Kandal' => ['km' => 'កណ្ដាល', 'zone' => 'near'],
        'Kampong Speu' => ['km' => 'កំពង់ស្ពឺ', 'zone' => 'near'],
        'Takeo' => ['km' => 'តាកែវ', 'zone' => 'near'],
        'Kampong Cham' => ['km' => 'កំពង់ចាម', 'zone' => 'near'],
        'Prey Veng' => ['km' => 'ព្រៃវែង', 'zone' => 'near'],
        'Svay Rieng' => ['km' => 'ស្វាយរៀង', 'zone' => 'near'],
        'Kampong Chhnang' => ['km' => 'កំពង់ឆ្នាំង', 'zone' => 'near'],
        'Tboung Khmum' => ['km' => 'ត្បូងឃ្មុំ', 'zone' => 'near'],

        'Banteay Meanchey' => ['km' => 'បន្ទាយមានជ័យ', 'zone' => 'far'],
        'Battambang' => ['km' => 'បាត់ដំបង', 'zone' => 'far'],
        'Kampong Thom' => ['km' => 'កំពង់ធំ', 'zone' => 'far'],
        'Kampot' => ['km' => 'កំពត', 'zone' => 'far'],
        'Kep' => ['km' => 'កែប', 'zone' => 'far'],
        'Koh Kong' => ['km' => 'កោះកុង', 'zone' => 'far'],
        'Kratie' => ['km' => 'ក្រចេះ', 'zone' => 'far'],
        'Mondulkiri' => ['km' => 'មណ្ឌលគិរី', 'zone' => 'far'],
        'Oddar Meanchey' => ['km' => 'ឧត្ដរមានជ័យ', 'zone' => 'far'],
        'Pailin' => ['km' => 'ប៉ៃលិន', 'zone' => 'far'],
        'Preah Sihanouk' => ['km' => 'ព្រះសីហនុ', 'zone' => 'far'],
        'Preah Vihear' => ['km' => 'ព្រះវិហារ', 'zone' => 'far'],
        'Pursat' => ['km' => 'ពោធិ៍សាត់', 'zone' => 'far'],
        'Ratanakiri' => ['km' => 'រតនគិរី', 'zone' => 'far'],
        'Siem Reap' => ['km' => 'សៀមរាប', 'zone' => 'far'],
        'Stung Treng' => ['km' => 'ស្ទឹងត្រែង', 'zone' => 'far'],
    ],

    /*
    | Delivery, per zone. Each zone carries its own price, its own free
    | threshold and how long it takes, so the rules can differ by distance
    | without any of them being hard-coded in a view.
    |
    | 'free_over' => null means that zone never ships free.
    */
    'delivery' => [
        'zones' => [
            'city' => [
                'label' => 'Phnom Penh',
                'fee' => 1.50,
                'free_over' => 20.00,
                'eta' => 'Same day, 2-4 hours',
                'eta_days' => 0,
            ],
            'near' => [
                'label' => 'Nearby provinces',
                'fee' => 2.50,
                'free_over' => null,
                'eta' => '1-2 days',
                'eta_days' => 2,
            ],
            'far' => [
                'label' => 'Other provinces',
                'fee' => 2.50,
                'free_over' => null,
                'eta' => '2-3 days',
                'eta_days' => 3,
            ],
        ],
    ],

    /*
    | How customers reach the shop. Telegram first — it is how most Cambodian
    | shops take questions about an order.
    */
    'shop' => [
        'telegram' => env('SHOP_TELEGRAM', 'setecmart'),
        'phone' => env('SHOP_PHONE', '012 345 678'),
        'email' => env('SHOP_EMAIL', 'hello@setecmart.com'),
        'address' => env('SHOP_ADDRESS', 'Phnom Penh, Cambodia'),
        'hours' => env('SHOP_HOURS', 'Every day, 7:00 - 20:00'),
    ],

    /*
    | Cambodian mobile numbers: a leading 0 then 8 or 9 digits (0XX XXX XXX /
    | 0XX XXX XXXX), or the same number written with the +855 country code.
    */
    'phone_regex' => '/^(?:\+?855[ -]?|0)([1-9]\d{7,8})$/',

];
