<?php
/**
 * Katalog naskladňování produktů — JEDINÝ zdroj pravdy pro výčty a skládání
 * názvu/popisu. Základ vznikl jako port naskladňovací Mac appky (macapp/app.py),
 * ale po odstranění importu z appky je CRM autorita pro nově přidané parametry.
 * Používá: products.php (JS dostane json_encode), api/product_create.php
 * (server znovu počítá a validuje), api/export_products_csv.php.
 */

const AFX_APPLE_COLORS = ['Black', 'White', 'Space Black', 'Space Gray', 'Silver', 'Gold', 'Graphite', 'Sierra Blue', 'Pacific Blue', 'Blue', 'Green', 'Alpine Green', 'Pink', 'Purple', 'Deep Purple', 'Red', 'Midnight', 'Starlight', 'Yellow', 'Coral', 'Natural Titanium', 'Blue Titanium', 'White Titanium', 'Black Titanium', 'Desert Titanium', 'Ultramarine', 'Teal', 'Cosmic Orange', 'Mist Blue'];
const AFX_ANDROID_COLORS = ['Black', 'White', 'Blue', 'Green', 'Grey', 'Silver', 'Gold', 'Purple', 'Red'];
const AFX_COMPUTER_COLORS = ['Black', 'White', 'Silver', 'Grey', 'Space Gray', 'Graphite', 'Blue', 'Red'];
const AFX_ACCESSORY_COLORS = ['Black', 'White', 'Grey', 'Silver', 'Transparent', 'Blue', 'Green', 'Pink', 'Purple', 'Red'];
const AFX_CAPS = ['16 GB', '32 GB', '64 GB', '128 GB', '256 GB', '512 GB', '1 TB', '2 TB'];
// RAM/jádra mají i telefony, tablety, konzole… — menší hodnoty pro ne-Macy (31.7. rozšířeno)
const AFX_RAMS = ['2 GB', '3 GB', '4 GB', '6 GB', '8 GB', '12 GB', '16 GB', '18 GB', '24 GB', '32 GB', '36 GB', '48 GB', '64 GB', '96 GB', '128 GB'];
const AFX_CPU_CORES = ['2', '4', '6', '8', '10', '11', '12', '14', '16', '20', '24', '28', '32'];
const AFX_GPU_CORES = ['3', '4', '5', '6', '8', '10', '14', '16', '18', '19', '20', '30', '32', '38', '40', '60', '76', '80'];
const AFX_GRADE_LABELS = ['Nový', 'Zánovní', 'A – jako nové', 'B – mírné stopy', 'C – viditelné opotřebení', 'D – silné opotřebení'];
const AFX_PRODEJNY = [['Karlín', 'karlin'], ['Černá Růže', 'vaclavak']];
const AFX_PROCESSOR_FAMILIES = ['AMD', 'Intel', 'M chip - ARM'];

const AFX_PROCESSORS_AMD = [
    'AMD A4 / A6 / A8 / A10 (2010–2016)',
    'AMD FX-4000 / FX-6000 / FX-8000 (2011–2016)',
    'AMD Ryzen 3 1000 (Zen, 2017)',
    'AMD Ryzen 5 1000 (Zen, 2017)',
    'AMD Ryzen 7 1000 (Zen, 2017)',
    'AMD Ryzen 3 2000 (Zen+, 2018)',
    'AMD Ryzen 5 2000 (Zen+, 2018)',
    'AMD Ryzen 7 2000 (Zen+, 2018)',
    'AMD Ryzen 3 3000 (Zen 2, 2019)',
    'AMD Ryzen 5 3000 (Zen 2, 2019)',
    'AMD Ryzen 7 3000 (Zen 2, 2019)',
    'AMD Ryzen 9 3000 (Zen 2, 2019)',
    'AMD Ryzen 3 4000U/H (Zen 2, 2020)',
    'AMD Ryzen 5 4000U/H (Zen 2, 2020)',
    'AMD Ryzen 7 4000U/H (Zen 2, 2020)',
    'AMD Ryzen 3 5000 (Zen 3, 2020/2021)',
    'AMD Ryzen 5 5000 (Zen 3, 2020/2021)',
    'AMD Ryzen 7 5000 (Zen 3, 2020/2021)',
    'AMD Ryzen 9 5000 (Zen 3, 2020/2021)',
    'AMD Ryzen 5 6000U/H (Zen 3+, 2022)',
    'AMD Ryzen 7 6000U/H (Zen 3+, 2022)',
    'AMD Ryzen 9 6000U/H (Zen 3+, 2022)',
    'AMD Ryzen 5 7000 (Zen 4, 2022/2023)',
    'AMD Ryzen 7 7000 (Zen 4, 2022/2023)',
    'AMD Ryzen 9 7000 (Zen 4, 2022/2023)',
    'AMD Ryzen 5 8000G/U/H (Zen 4, 2024)',
    'AMD Ryzen 7 8000G/U/H (Zen 4, 2024)',
    'AMD Ryzen 9 8000G/U/H (Zen 4, 2024)',
    'AMD Ryzen AI 300 (Zen 5, 2024+)',
    'AMD Ryzen 5 9000 (Zen 5, 2024+)',
    'AMD Ryzen 7 9000 (Zen 5, 2024+)',
    'AMD Ryzen 9 9000 (Zen 5, 2024+)',
];
const AFX_PROCESSORS_INTEL = [
    'Intel Core i3 (1. generace / 2010)',
    'Intel Core i5 (1. generace / 2010)',
    'Intel Core i7 (1. generace / 2010)',
    'Intel Core i3 (2. generace / Sandy Bridge)',
    'Intel Core i5 (2. generace / Sandy Bridge)',
    'Intel Core i7 (2. generace / Sandy Bridge)',
    'Intel Core i3 (3. generace / Ivy Bridge)',
    'Intel Core i5 (3. generace / Ivy Bridge)',
    'Intel Core i7 (3. generace / Ivy Bridge)',
    'Intel Core i3 (4. generace / Haswell)',
    'Intel Core i5 (4. generace / Haswell)',
    'Intel Core i7 (4. generace / Haswell)',
    'Intel Core i3 (5. generace / Broadwell)',
    'Intel Core i5 (5. generace / Broadwell)',
    'Intel Core i7 (5. generace / Broadwell)',
    'Intel Core i3 (6. generace / Skylake)',
    'Intel Core i5 (6. generace / Skylake)',
    'Intel Core i7 (6. generace / Skylake)',
    'Intel Core i3 (7. generace / Kaby Lake)',
    'Intel Core i5 (7. generace / Kaby Lake)',
    'Intel Core i7 (7. generace / Kaby Lake)',
    'Intel Core i3 (8. generace / Coffee Lake)',
    'Intel Core i5 (8. generace / Coffee Lake)',
    'Intel Core i7 (8. generace / Coffee Lake)',
    'Intel Core i9 (8. generace / Coffee Lake)',
    'Intel Core i3 (9. generace / Coffee Lake Refresh)',
    'Intel Core i5 (9. generace / Coffee Lake Refresh)',
    'Intel Core i7 (9. generace / Coffee Lake Refresh)',
    'Intel Core i9 (9. generace / Coffee Lake Refresh)',
    'Intel Core i3 (10. generace / Ice Lake nebo Comet Lake)',
    'Intel Core i5 (10. generace / Ice Lake nebo Comet Lake)',
    'Intel Core i7 (10. generace / Ice Lake nebo Comet Lake)',
    'Intel Core i9 (10. generace / Comet Lake)',
    'Intel Core i3 (11. generace / Tiger Lake)',
    'Intel Core i5 (11. generace / Tiger Lake)',
    'Intel Core i7 (11. generace / Tiger Lake)',
    'Intel Core i9 (11. generace / Rocket Lake)',
    'Intel Core i3 (12. generace / Alder Lake)',
    'Intel Core i5 (12. generace / Alder Lake)',
    'Intel Core i7 (12. generace / Alder Lake)',
    'Intel Core i9 (12. generace / Alder Lake)',
    'Intel Core i3 (13. generace / Raptor Lake)',
    'Intel Core i5 (13. generace / Raptor Lake)',
    'Intel Core i7 (13. generace / Raptor Lake)',
    'Intel Core i9 (13. generace / Raptor Lake)',
    'Intel Core i3 (14. generace / Raptor Lake Refresh)',
    'Intel Core i5 (14. generace / Raptor Lake Refresh)',
    'Intel Core i7 (14. generace / Raptor Lake Refresh)',
    'Intel Core i9 (14. generace / Raptor Lake Refresh)',
    'Intel Core Ultra 5 (Series 1 / Meteor Lake)',
    'Intel Core Ultra 7 (Series 1 / Meteor Lake)',
    'Intel Core Ultra 9 (Series 1 / Meteor Lake)',
    'Intel Core Ultra 5 200 (Lunar/Arrow Lake)',
    'Intel Core Ultra 7 200 (Lunar/Arrow Lake)',
    'Intel Core Ultra 9 200 (Lunar/Arrow Lake)',
];
const AFX_PROCESSORS_M_ARM = [
    'Apple M1 (2020)',
    'Apple M1 Pro (2021)',
    'Apple M1 Max (2021)',
    'Apple M1 Ultra (2022)',
    'Apple M2 (2022)',
    'Apple M2 Pro (2023)',
    'Apple M2 Max (2023)',
    'Apple M2 Ultra (2023)',
    'Apple M3 (2023)',
    'Apple M3 Pro (2023)',
    'Apple M3 Max (2023)',
    'Apple M4 (2024)',
    'Apple M4 Pro (2024)',
    'Apple M4 Max (2024)',
    'Apple M5 (2025+)',
];

const AFX_IPHONES = ['iPhone 6s', 'iPhone 7', 'iPhone 7 Plus', 'iPhone 8', 'iPhone 8 Plus', 'iPhone X', 'iPhone XR', 'iPhone XS', 'iPhone XS Max', 'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max', 'iPhone 12 mini', 'iPhone 12', 'iPhone 12 Pro', 'iPhone 12 Pro Max', 'iPhone 13 mini', 'iPhone 13', 'iPhone 13 Pro', 'iPhone 13 Pro Max', 'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max', 'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max', 'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max', 'iPhone 17', 'iPhone 17 Pro', 'iPhone 17 Pro Max', 'iPhone SE 2020', 'iPhone SE 2022'];
const AFX_IPADS = ['iPad 9', 'iPad 10', 'iPad 11', 'iPad mini 6', 'iPad mini 7', 'iPad Air 4', 'iPad Air 5', 'iPad Air 11″', 'iPad Air 13″', 'iPad Pro 11″', 'iPad Pro 12,9″', 'iPad Pro 13″'];
const AFX_MACBOOKS_AIR = [
    'MacBook Air 13″ (2017) Intel', 'MacBook Air 13″ (2018) Intel', 'MacBook Air 13″ (2019) Intel', 'MacBook Air 13″ (2020) Intel',
    'MacBook Air 13″ M1 (2020)', 'MacBook Air 13″ M2 (2022)', 'MacBook Air 15″ M2 (2023)',
    'MacBook Air 13″ M3 (2024)', 'MacBook Air 15″ M3 (2024)', 'MacBook Air 13″ M4 (2025)', 'MacBook Air 15″ M4 (2025)',
];
const AFX_MACBOOKS_PRO = [
    'MacBook Pro 13″ (2015) Intel', 'MacBook Pro 13″ (2016) Intel', 'MacBook Pro 13″ (2017) Intel', 'MacBook Pro 13″ (2018) Intel', 'MacBook Pro 13″ (2019) Intel', 'MacBook Pro 13″ (2020) Intel',
    'MacBook Pro 15″ (2015) Intel', 'MacBook Pro 15″ (2016) Intel', 'MacBook Pro 15″ (2017) Intel', 'MacBook Pro 15″ (2018) Intel', 'MacBook Pro 15″ (2019) Intel', 'MacBook Pro 16″ (2019) Intel',
    'MacBook Pro 13″ M1 (2020)', 'MacBook Pro 13″ M2 (2022)',
    'MacBook Pro 14″ M1 Pro (2021)', 'MacBook Pro 14″ M1 Max (2021)', 'MacBook Pro 16″ M1 Pro (2021)', 'MacBook Pro 16″ M1 Max (2021)',
    'MacBook Pro 14″ M2 Pro (2023)', 'MacBook Pro 14″ M2 Max (2023)', 'MacBook Pro 16″ M2 Pro (2023)', 'MacBook Pro 16″ M2 Max (2023)',
    'MacBook Pro 14″ M3 (2023)', 'MacBook Pro 14″ M3 Pro (2023)', 'MacBook Pro 14″ M3 Max (2023)', 'MacBook Pro 16″ M3 Pro (2023)', 'MacBook Pro 16″ M3 Max (2023)',
    'MacBook Pro 14″ M4 (2024)', 'MacBook Pro 14″ M4 Pro (2024)', 'MacBook Pro 14″ M4 Max (2024)', 'MacBook Pro 16″ M4 Pro (2024)', 'MacBook Pro 16″ M4 Max (2024)',
    'MacBook Pro 14″ M5 (2025)',
];
const AFX_IMACS = [
    'iMac 21,5″ (2012) Intel', 'iMac 27″ (2012) Intel', 'iMac 21,5″ (2013) Intel', 'iMac 27″ (2013) Intel',
    'iMac 21,5″ (2014) Intel', 'iMac 27″ Retina 5K (2014) Intel', 'iMac 21,5″ (2015) Intel', 'iMac 27″ Retina 5K (2015) Intel',
    'iMac 21,5″ (2017) Intel', 'iMac 27″ Retina 5K (2017) Intel', 'iMac 21,5″ (2019) Intel', 'iMac 27″ Retina 5K (2019) Intel',
    'iMac 27″ Retina 5K (2020) Intel', 'iMac 24″ M1 (2021)', 'iMac 24″ M3 (2023)', 'iMac 24″ M4 (2024)',
];
const AFX_MAC_MINI = [
    'Mac mini (2010) Intel', 'Mac mini (2011) Intel', 'Mac mini (2012) Intel', 'Mac mini (2014) Intel',
    'Mac mini (2018) Intel', 'Mac mini M1 (2020)', 'Mac mini M2 (2023)', 'Mac mini M2 Pro (2023)',
    'Mac mini M4 (2024)', 'Mac mini M4 Pro (2024)',
];
const AFX_MAC_STUDIO = [
    'Mac Studio M1 Max (2022)', 'Mac Studio M1 Ultra (2022)', 'Mac Studio M2 Max (2023)',
    'Mac Studio M2 Ultra (2023)', 'Mac Studio M4 Max (2025)', 'Mac Studio M4 Ultra (2025)',
];
const AFX_MAC_PRO = [
    'Mac Pro (2010) Intel', 'Mac Pro (2012) Intel', 'Mac Pro (2013) Intel',
    'Mac Pro (2019) Intel', 'Mac Pro M2 Ultra (2023)',
];
const AFX_APPLE_WATCHES = [
    'Apple Watch Series 3', 'Apple Watch Series 4', 'Apple Watch Series 5', 'Apple Watch Series 6',
    'Apple Watch SE (1. gen)', 'Apple Watch Series 7', 'Apple Watch Series 8', 'Apple Watch Ultra',
    'Apple Watch SE (2. gen)', 'Apple Watch Series 9', 'Apple Watch Ultra 2', 'Apple Watch Series 10',
];
const AFX_AIRPODS = [
    'AirPods (2. generace)', 'AirPods (3. generace)', 'AirPods (4. generace)',
    'AirPods Pro (1. generace)', 'AirPods Pro (2. generace)', 'AirPods Max',
];
const AFX_APPLE_TV = ['Apple TV HD', 'Apple TV 4K (1. generace)', 'Apple TV 4K (2. generace)', 'Apple TV 4K (3. generace)'];
const AFX_HOMEPODS = ['HomePod (1. generace)', 'HomePod mini', 'HomePod (2. generace)'];

const AFX_BRAND_MODELS = [
    'Samsung' => [
        'Telefon' => [
            'Galaxy S II', 'Galaxy S III', 'Galaxy S4', 'Galaxy S5', 'Galaxy S6', 'Galaxy S6 edge',
            'Galaxy S7', 'Galaxy S7 edge', 'Galaxy S8', 'Galaxy S8+', 'Galaxy S9', 'Galaxy S9+',
            'Galaxy S10e', 'Galaxy S10', 'Galaxy S10+', 'Galaxy S10 5G', 'Galaxy S20', 'Galaxy S20+',
            'Galaxy S20 Ultra', 'Galaxy S20 FE', 'Galaxy S21', 'Galaxy S21+', 'Galaxy S21 Ultra',
            'Galaxy S21 FE', 'Galaxy S22', 'Galaxy S22+', 'Galaxy S22 Ultra', 'Galaxy S23',
            'Galaxy S23+', 'Galaxy S23 Ultra', 'Galaxy S23 FE', 'Galaxy S24', 'Galaxy S24+',
            'Galaxy S24 Ultra', 'Galaxy S24 FE', 'Galaxy S25', 'Galaxy S25+', 'Galaxy S25 Ultra',
            'Galaxy Note 8', 'Galaxy Note 9', 'Galaxy Note 10', 'Galaxy Note 10+', 'Galaxy Note 20',
            'Galaxy Note 20 Ultra', 'Galaxy Z Fold', 'Galaxy Z Fold2', 'Galaxy Z Fold3',
            'Galaxy Z Fold4', 'Galaxy Z Fold5', 'Galaxy Z Fold6', 'Galaxy Z Flip', 'Galaxy Z Flip3',
            'Galaxy Z Flip4', 'Galaxy Z Flip5', 'Galaxy Z Flip6', 'Galaxy A3', 'Galaxy A5',
            'Galaxy A7', 'Galaxy A8', 'Galaxy A10', 'Galaxy A12', 'Galaxy A13', 'Galaxy A14',
            'Galaxy A15', 'Galaxy A16', 'Galaxy A20e', 'Galaxy A21s', 'Galaxy A22', 'Galaxy A23',
            'Galaxy A25', 'Galaxy A30s', 'Galaxy A31', 'Galaxy A32', 'Galaxy A33', 'Galaxy A34',
            'Galaxy A35', 'Galaxy A40', 'Galaxy A41', 'Galaxy A42', 'Galaxy A50', 'Galaxy A51',
            'Galaxy A52', 'Galaxy A52s', 'Galaxy A53', 'Galaxy A54', 'Galaxy A55', 'Galaxy A56',
            'Galaxy A70', 'Galaxy A71', 'Galaxy A72', 'Galaxy A73', 'Galaxy M21', 'Galaxy M31',
            'Galaxy M51', 'Galaxy M52', 'Galaxy M53', 'Galaxy M54',
        ],
        'Tablet' => [
            'Galaxy Tab 2', 'Galaxy Tab 3', 'Galaxy Tab 4', 'Galaxy Tab A 8.0', 'Galaxy Tab A 10.1',
            'Galaxy Tab A7', 'Galaxy Tab A7 Lite', 'Galaxy Tab A8', 'Galaxy Tab A9', 'Galaxy Tab A9+',
            'Galaxy Tab Active 2', 'Galaxy Tab Active 3', 'Galaxy Tab Active4 Pro', 'Galaxy Tab Active5',
            'Galaxy Tab S', 'Galaxy Tab S2', 'Galaxy Tab S3', 'Galaxy Tab S4', 'Galaxy Tab S5e',
            'Galaxy Tab S6', 'Galaxy Tab S6 Lite', 'Galaxy Tab S7', 'Galaxy Tab S7+', 'Galaxy Tab S7 FE',
            'Galaxy Tab S8', 'Galaxy Tab S8+', 'Galaxy Tab S8 Ultra', 'Galaxy Tab S9', 'Galaxy Tab S9+',
            'Galaxy Tab S9 Ultra', 'Galaxy Tab S9 FE', 'Galaxy Tab S9 FE+', 'Galaxy Tab S10+',
            'Galaxy Tab S10 Ultra',
        ],
        'Notebook' => ['Galaxy Book Flex2 (2021)', 'Galaxy Book Ion2 (2021)', 'Galaxy Book Pro (2021)', 'Galaxy Book Pro 360 (2021)', 'Galaxy Book2 (2022)', 'Galaxy Book2 360 (2022)', 'Galaxy Book2 Pro (2022)', 'Galaxy Book2 Pro 360 (2022)', 'Galaxy Book3 (2023)', 'Galaxy Book3 360 (2023)', 'Galaxy Book3 Pro (2023)', 'Galaxy Book3 Pro 360 (2023)', 'Galaxy Book3 Ultra (2023)', 'Galaxy Book4 (2024)', 'Galaxy Book4 360 (2024)', 'Galaxy Book4 Pro (2024)', 'Galaxy Book4 Pro 360 (2024)', 'Galaxy Book4 Ultra (2024)', 'Galaxy Book4 Edge (2024)', 'Galaxy Book5 Pro (2025)', 'Galaxy Book5 Pro 360 (2025)', 'Galaxy Chromebook 2 (2021)'],
        'Hodinky' => ['Galaxy Gear', 'Gear S2', 'Gear S3', 'Galaxy Watch', 'Galaxy Watch Active', 'Galaxy Watch Active2', 'Galaxy Watch3', 'Galaxy Watch4', 'Galaxy Watch4 Classic', 'Galaxy Watch5', 'Galaxy Watch5 Pro', 'Galaxy Watch6', 'Galaxy Watch6 Classic', 'Galaxy Watch7', 'Galaxy Watch Ultra', 'Galaxy Fit', 'Galaxy Fit2', 'Galaxy Fit3'],
        'Sluchátka' => ['Galaxy Buds', 'Galaxy Buds+', 'Galaxy Buds Live', 'Galaxy Buds Pro', 'Galaxy Buds2', 'Galaxy Buds2 Pro', 'Galaxy Buds FE', 'Galaxy Buds3', 'Galaxy Buds3 Pro'],
    ],
    'Xiaomi' => [
        'Telefon' => [
            'Mi 8', 'Mi 8 Lite', 'Mi 9', 'Mi 9T', 'Mi 9T Pro', 'Mi 10', 'Mi 10T', 'Mi 10T Pro',
            'Mi 11', 'Mi 11 Lite', 'Mi 11 Ultra', 'Xiaomi 11T', 'Xiaomi 11T Pro', 'Xiaomi 12',
            'Xiaomi 12 Pro', 'Xiaomi 12T', 'Xiaomi 12T Pro', 'Xiaomi 13', 'Xiaomi 13 Pro',
            'Xiaomi 13T', 'Xiaomi 13T Pro', 'Xiaomi 14', 'Xiaomi 14 Ultra', 'Xiaomi 14T',
            'Xiaomi 14T Pro', 'Xiaomi 15', 'Xiaomi 15 Ultra', 'Mi Mix 2', 'Mi Mix 3', 'Mi Mix Fold',
            'Xiaomi Mix Fold 2', 'Xiaomi Mix Fold 3', 'Poco F1', 'Poco F2 Pro', 'Poco F3',
            'Poco F4', 'Poco F5', 'Poco F5 Pro', 'Poco F6', 'Poco F6 Pro', 'Poco X3',
            'Poco X3 Pro', 'Poco X4 Pro', 'Poco X5', 'Poco X5 Pro', 'Poco X6', 'Poco X6 Pro',
            'Redmi Note 7', 'Redmi Note 8', 'Redmi Note 8 Pro', 'Redmi Note 9', 'Redmi Note 9 Pro',
            'Redmi Note 10', 'Redmi Note 10 Pro', 'Redmi Note 11', 'Redmi Note 11 Pro',
            'Redmi Note 12', 'Redmi Note 12 Pro', 'Redmi Note 13', 'Redmi Note 13 Pro',
            'Redmi Note 14', 'Redmi Note 14 Pro', 'Redmi 9', 'Redmi 10', 'Redmi 12',
            'Redmi 13', 'Redmi 14C',
        ],
        'Tablet' => ['Mi Pad 4', 'Mi Pad 4 Plus', 'Xiaomi Pad 5', 'Xiaomi Pad 5 Pro', 'Xiaomi Pad 6', 'Xiaomi Pad 6 Pro', 'Xiaomi Pad 6S Pro', 'Xiaomi Pad 7', 'Xiaomi Pad 7 Pro', 'Redmi Pad', 'Redmi Pad SE', 'Redmi Pad Pro'],
        'Hodinky' => ['Mi Watch', 'Mi Watch Lite', 'Xiaomi Watch S1', 'Xiaomi Watch S1 Active', 'Xiaomi Watch S3', 'Xiaomi Watch 2', 'Xiaomi Watch 2 Pro', 'Xiaomi Smart Band 6', 'Xiaomi Smart Band 7', 'Xiaomi Smart Band 8', 'Xiaomi Smart Band 9', 'Redmi Watch 2 Lite', 'Redmi Watch 3', 'Redmi Watch 4', 'Redmi Watch 5'],
    ],
    'Asus' => [
        'Telefon' => ['Zenfone 4', 'Zenfone 5', 'Zenfone 5Z', 'Zenfone 6', 'Zenfone 7', 'Zenfone 7 Pro', 'Zenfone 8', 'Zenfone 8 Flip', 'Zenfone 9', 'Zenfone 10', 'Zenfone 11 Ultra', 'ROG Phone', 'ROG Phone II', 'ROG Phone 3', 'ROG Phone 5', 'ROG Phone 6', 'ROG Phone 7', 'ROG Phone 8', 'ROG Phone 9'],
        'Tablet' => ['Transformer Pad TF300', 'Transformer Book T100', 'ZenPad 8', 'ZenPad 10', 'VivoTab', 'ROG Flow Z13'],
        'Notebook' => ['ZenBook 13 UX325 (2020)', 'ZenBook 14 UX425 (2020)', 'Zenbook 14 OLED UX3402 (2022)', 'Zenbook 14 OLED UX3405 (2024)', 'Zenbook S 13 OLED UX5304 (2023)', 'Zenbook S 14 UX5406 (2024)', 'Zenbook Pro 14 Duo UX8402 (2022)', 'Zenbook Duo UX8406 (2024)', 'Vivobook 14 X1404 (2023)', 'Vivobook 15 X1504 (2023)', 'Vivobook S 14 OLED K5404 (2023)', 'Vivobook S 15 OLED K5504 (2023)', 'Vivobook Pro 15 OLED K6502 (2023)', 'ExpertBook B9 B9400 (2021)', 'ExpertBook B9 B9403 (2023)', 'TUF Gaming A15 FA506 (2020)', 'TUF Gaming A15 FA507 (2022)', 'TUF Gaming A15 FA507N (2023)', 'TUF Gaming F15 FX506 (2020)', 'TUF Gaming F15 FX507 (2022)', 'ROG Zephyrus G14 GA401 (2020)', 'ROG Zephyrus G14 GA402 (2022)', 'ROG Zephyrus G16 GU603 (2023)', 'ROG Zephyrus G16 GU605 (2024)', 'ROG Strix G15 G513 (2021)', 'ROG Strix G16 G614 (2023)', 'ROG Strix Scar 16 G634 (2023)', 'ProArt Studiobook 16 OLED H7604 (2023)'],
        'Počítač' => ['ROG Strix G10', 'ROG Strix G15DK', 'ROG Strix G16CHR', 'ROG G20', 'ROG G22CH', 'ProArt Station', 'ExpertCenter', 'ASUS Mini PC PN50', 'ASUS Mini PC PN64'],
    ],
    'Doogee' => [
        'Telefon' => ['Doogee S40', 'Doogee S41', 'Doogee S55', 'Doogee S58 Pro', 'Doogee S59 Pro', 'Doogee S68 Pro', 'Doogee S86', 'Doogee S88 Pro', 'Doogee S89 Pro', 'Doogee S95 Pro', 'Doogee S96 Pro', 'Doogee S98', 'Doogee S98 Pro', 'Doogee S99', 'Doogee S100', 'Doogee S110', 'Doogee S118', 'Doogee V10', 'Doogee V20', 'Doogee V30', 'Doogee V30T', 'Doogee V31GT', 'Doogee V Max', 'Doogee N30', 'Doogee N40 Pro', 'Doogee N50', 'Doogee N55'],
    ],
    'Honor' => [
        'Telefon' => ['Honor 8', 'Honor 9', 'Honor 10', 'Honor 20', 'Honor 20 Pro', 'Honor 30', 'Honor 50', 'Honor 70', 'Honor 90', 'Honor 200', 'Honor 200 Pro', 'Honor Magic4 Pro', 'Honor Magic5 Pro', 'Honor Magic6 Pro', 'Honor Magic V2', 'Honor Magic V3', 'Honor X6', 'Honor X7', 'Honor X8', 'Honor X9', 'Honor X50', 'Honor X60', 'Honor Play', 'Honor View 10', 'Honor View 20'],
        'Tablet' => ['Honor Pad 5', 'Honor Pad 6', 'Honor Pad 7', 'Honor Pad 8', 'Honor Pad 9', 'Honor Pad X8', 'Honor Pad X9', 'Honor MagicPad 2'],
        'Notebook' => ['MagicBook 14 (2020)', 'MagicBook 14 (2021)', 'MagicBook 14 (2022)', 'MagicBook 14 (2023)', 'MagicBook 14 Pro (2023)', 'MagicBook 15 (2020)', 'MagicBook 15 (2021)', 'MagicBook 16 (2021)', 'MagicBook 16 Pro (2021)', 'MagicBook X14 (2022)', 'MagicBook X14 (2023)', 'MagicBook X14 (2024)', 'MagicBook X15 (2022)', 'MagicBook X16 (2024)', 'MagicBook Art 14 (2024)'],
    ],
    'Huawei' => [
        'Telefon' => ['Huawei P8', 'Huawei P9', 'Huawei P10', 'Huawei P20', 'Huawei P20 Pro', 'Huawei P30', 'Huawei P30 Pro', 'Huawei P40', 'Huawei P40 Pro', 'Huawei P50 Pro', 'Huawei P60 Pro', 'Huawei Pura 70', 'Huawei Pura 70 Pro', 'Huawei Mate 8', 'Huawei Mate 9', 'Huawei Mate 10 Pro', 'Huawei Mate 20', 'Huawei Mate 20 Pro', 'Huawei Mate 30 Pro', 'Huawei Mate 40 Pro', 'Huawei Mate 50 Pro', 'Huawei Mate 60 Pro', 'Huawei Nova 5T', 'Huawei Nova 9', 'Huawei Nova 10', 'Huawei Nova 11', 'Huawei Nova 12', 'Huawei Y6', 'Huawei Y7', 'Huawei Y9'],
        'Tablet' => ['MediaPad M3', 'MediaPad M5', 'MediaPad T3', 'MediaPad T5', 'MatePad 10.4', 'MatePad 11', 'MatePad 11.5', 'MatePad Pro 10.8', 'MatePad Pro 11', 'MatePad Pro 12.6', 'MatePad Pro 13.2'],
        'Notebook' => ['MateBook D 14 (2020)', 'MateBook D 14 (2021)', 'MateBook D 14 (2022)', 'MateBook D 14 (2023)', 'MateBook D 14 (2024)', 'MateBook D 15 (2020)', 'MateBook D 15 (2021)', 'MateBook D 15 (2022)', 'MateBook D 16 (2022)', 'MateBook D 16 (2024)', 'MateBook 13 (2020)', 'MateBook 14 (2020)', 'MateBook 14 (2021)', 'MateBook 14 (2022)', 'MateBook 14 (2024)', 'MateBook X Pro (2020)', 'MateBook X Pro (2021)', 'MateBook X Pro (2022)', 'MateBook X Pro (2023)', 'MateBook X Pro (2024)', 'MateBook 16 (2021)', 'MateBook 16s (2022)', 'MateBook E (2022)'],
        'Hodinky' => ['Huawei Watch', 'Huawei Watch 2', 'Huawei Watch GT', 'Huawei Watch GT 2', 'Huawei Watch GT 2 Pro', 'Huawei Watch GT 3', 'Huawei Watch GT 4', 'Huawei Watch GT 5', 'Huawei Watch 3', 'Huawei Watch 4', 'Huawei Watch Fit', 'Huawei Watch Fit 2', 'Huawei Watch Fit 3'],
    ],
    'Motorola' => [
        'Telefon' => ['Moto G', 'Moto G2', 'Moto G3', 'Moto G4', 'Moto G5', 'Moto G6', 'Moto G7', 'Moto G8', 'Moto G9', 'Moto G10', 'Moto G20', 'Moto G30', 'Moto G50', 'Moto G60', 'Moto G72', 'Moto G73', 'Moto G84', 'Moto G85', 'Moto E', 'Moto E4', 'Moto E5', 'Moto E6', 'Moto E7', 'Moto E13', 'Moto E22', 'Moto X', 'Moto X Style', 'Moto X4', 'Moto Z', 'Moto Z2 Play', 'Moto Z3 Play', 'Motorola Edge', 'Motorola Edge 20', 'Motorola Edge 30', 'Motorola Edge 40', 'Motorola Edge 50', 'Razr 2019', 'Razr 5G', 'Razr 40', 'Razr 40 Ultra', 'Razr 50', 'Razr 50 Ultra'],
        'Tablet' => ['Moto Tab G20', 'Moto Tab G62', 'Moto Tab G70', 'Moto Tab G84', 'Moto Tab G85'],
    ],
    'Nokia' => [
        'Telefon' => ['Nokia Lumia 520', 'Nokia Lumia 630', 'Nokia Lumia 735', 'Nokia Lumia 830', 'Nokia Lumia 930', 'Nokia 1', 'Nokia 2', 'Nokia 3', 'Nokia 5', 'Nokia 6', 'Nokia 7 Plus', 'Nokia 8', 'Nokia 8 Sirocco', 'Nokia 9 PureView', 'Nokia C20', 'Nokia C21', 'Nokia C30', 'Nokia C32', 'Nokia G10', 'Nokia G11', 'Nokia G20', 'Nokia G21', 'Nokia G22', 'Nokia G42', 'Nokia X10', 'Nokia X20', 'Nokia X30', 'Nokia XR20', 'Nokia XR21'],
        'Tablet' => ['Nokia N1', 'Nokia T10', 'Nokia T20', 'Nokia T21'],
    ],
    'Lenovo' => [
        'Telefon' => ['Lenovo K5', 'Lenovo K6', 'Lenovo K8 Note', 'Lenovo K10 Note', 'Lenovo P2', 'Lenovo Z5', 'Lenovo Z6 Pro', 'Lenovo Legion Phone Duel', 'Lenovo Legion Phone Duel 2'],
        'Tablet' => ['Lenovo Tab M7', 'Lenovo Tab M8', 'Lenovo Tab M9', 'Lenovo Tab M10', 'Lenovo Tab M11', 'Lenovo Tab P10', 'Lenovo Tab P11', 'Lenovo Tab P11 Pro', 'Lenovo Tab P12', 'Lenovo Tab P12 Pro', 'Lenovo Yoga Tab 3', 'Lenovo Yoga Tab 11', 'Lenovo Yoga Tab 13', 'Lenovo Legion Tab', 'Lenovo IdeaTab'],
        'Notebook' => ['ThinkPad T14 Gen 1 (2020)', 'ThinkPad T14 Gen 2 (2021)', 'ThinkPad T14 Gen 3 (2022)', 'ThinkPad T14 Gen 4 (2023)', 'ThinkPad T14 Gen 5 (2024)', 'ThinkPad T14s Gen 1 (2020)', 'ThinkPad T14s Gen 2 (2021)', 'ThinkPad T14s Gen 3 (2022)', 'ThinkPad T14s Gen 4 (2023)', 'ThinkPad T14s Gen 5 (2024)', 'ThinkPad T16 Gen 1 (2022)', 'ThinkPad T16 Gen 2 (2023)', 'ThinkPad T16 Gen 3 (2024)', 'ThinkPad X1 Carbon Gen 8 (2020)', 'ThinkPad X1 Carbon Gen 9 (2021)', 'ThinkPad X1 Carbon Gen 10 (2022)', 'ThinkPad X1 Carbon Gen 11 (2023)', 'ThinkPad X1 Carbon Gen 12 (2024)', 'ThinkPad X1 Yoga Gen 5 (2020)', 'ThinkPad X1 Yoga Gen 6 (2021)', 'ThinkPad X1 Yoga Gen 7 (2022)', 'ThinkPad X1 Yoga Gen 8 (2023)', 'ThinkPad X1 2-in-1 Gen 9 (2024)', 'ThinkPad X13 Gen 1 (2020)', 'ThinkPad X13 Gen 2 (2021)', 'ThinkPad X13 Gen 3 (2022)', 'ThinkPad X13 Gen 4 (2023)', 'ThinkPad X13 Gen 5 (2024)', 'ThinkPad P14s Gen 1 (2020)', 'ThinkPad P14s Gen 2 (2021)', 'ThinkPad P14s Gen 3 (2022)', 'ThinkPad P14s Gen 4 (2023)', 'ThinkPad P14s Gen 5 (2024)', 'ThinkPad P1 Gen 3 (2020)', 'ThinkPad P1 Gen 4 (2021)', 'ThinkPad P1 Gen 5 (2022)', 'ThinkPad P1 Gen 6 (2023)', 'ThinkPad P1 Gen 7 (2024)', 'ThinkPad E14 Gen 2 (2020)', 'ThinkPad E14 Gen 3 (2021)', 'ThinkPad E14 Gen 4 (2022)', 'ThinkPad E14 Gen 5 (2023)', 'ThinkPad E14 Gen 6 (2024)', 'ThinkPad E15 Gen 2 (2020)', 'ThinkPad E15 Gen 3 (2021)', 'ThinkPad E15 Gen 4 (2022)', 'ThinkPad E16 Gen 1 (2023)', 'ThinkPad E16 Gen 2 (2024)', 'IdeaPad 3 14 (2020)', 'IdeaPad 3 15 (2021)', 'IdeaPad 5 14 (2020)', 'IdeaPad 5 15 (2021)', 'IdeaPad Slim 5 14 (2023)', 'IdeaPad Slim 5 16 (2024)', 'IdeaPad Flex 5 14 (2021)', 'Yoga Slim 7 14 (2020)', 'Yoga Slim 7 Pro 14 (2021)', 'Yoga Slim 7 Pro X (2022)', 'Yoga 7 14 (2022)', 'Yoga 9 14 (2023)', 'Legion 5 15 (2020)', 'Legion 5 15 (2021)', 'Legion 5 16 (2022)', 'Legion 5 Pro 16 (2021)', 'Legion 5 Pro 16 (2022)', 'Legion 7 16 (2021)', 'Legion Pro 5 16 (2023)', 'Legion Pro 7 16 (2023)', 'LOQ 15IRH8 (2023)', 'LOQ 15AHP9 (2024)', 'ThinkBook 14 G2 (2020)', 'ThinkBook 14 G4 (2022)', 'ThinkBook 14 G6 (2023)', 'ThinkBook 14 G7 (2024)', 'ThinkBook 16 G4 (2022)', 'ThinkBook 16 G6 (2023)', 'ThinkBook 16 G7 (2024)'],
        'Počítač' => ['ThinkCentre M720', 'ThinkCentre M920', 'ThinkCentre M70q', 'ThinkCentre M80q', 'ThinkCentre M90q', 'IdeaCentre 3', 'IdeaCentre 5', 'IdeaCentre AIO 3', 'IdeaCentre AIO 5', 'Legion T5', 'Legion T7', 'ThinkStation P330', 'ThinkStation P360', 'ThinkStation P520', 'ThinkStation P620'],
    ],
    'Dell' => [
        'Notebook' => ['XPS 13 9300 (2020)', 'XPS 13 9310 (2020/2021)', 'XPS 13 Plus 9320 (2022)', 'XPS 13 9340 (2024)', 'XPS 14 9440 (2024)', 'XPS 15 9500 (2020)', 'XPS 15 9510 (2021)', 'XPS 15 9520 (2022)', 'XPS 15 9530 (2023)', 'XPS 16 9640 (2024)', 'XPS 17 9700 (2020)', 'XPS 17 9710 (2021)', 'XPS 17 9720 (2022)', 'XPS 17 9730 (2023)', 'Inspiron 14 5406 (2020)', 'Inspiron 14 5410 (2021)', 'Inspiron 14 5420 (2022)', 'Inspiron 14 5430 (2023)', 'Inspiron 14 5440 (2024)', 'Inspiron 15 5502 (2020)', 'Inspiron 15 5510 (2021)', 'Inspiron 15 3520 (2022)', 'Inspiron 15 3530 (2023)', 'Inspiron 16 5620 (2022)', 'Inspiron 16 5630 (2023)', 'Latitude 5410 (2020)', 'Latitude 5420 (2021)', 'Latitude 5430 (2022)', 'Latitude 5440 (2023)', 'Latitude 5450 (2024)', 'Latitude 7410 (2020)', 'Latitude 7420 (2021)', 'Latitude 7430 (2022)', 'Latitude 7440 (2023)', 'Latitude 7450 (2024)', 'Precision 3560 (2021)', 'Precision 3570 (2022)', 'Precision 3580 (2023)', 'Precision 3590 (2024)', 'Precision 5550 (2020)', 'Precision 5560 (2021)', 'Precision 5570 (2022)', 'Precision 5680 (2023)', 'Precision 5690 (2024)', 'Dell G15 5510 (2021)', 'Dell G15 5520 (2022)', 'Dell G15 5530 (2023)', 'Alienware m15 R3 (2020)', 'Alienware m15 R4 (2021)', 'Alienware m15 R7 (2022)', 'Alienware m16 R1 (2023)', 'Alienware m16 R2 (2024)', 'Alienware x14 R1 (2022)', 'Alienware x14 R2 (2023)', 'Alienware x16 R1 (2023)', 'Alienware x16 R2 (2024)'],
        'Počítač' => ['OptiPlex 3020', 'OptiPlex 3040', 'OptiPlex 3050', 'OptiPlex 3060', 'OptiPlex 3070', 'OptiPlex 3080', 'OptiPlex 3090', 'OptiPlex 5000', 'OptiPlex 7000', 'Precision T1700', 'Precision 3430', 'Precision 3630', 'Precision 5820', 'Precision 7820', 'XPS Desktop', 'Inspiron Desktop', 'Vostro Desktop', 'Alienware Aurora R10', 'Alienware Aurora R12', 'Alienware Aurora R13', 'Alienware Aurora R15', 'Alienware Aurora R16'],
    ],
    'HP' => [
        'Notebook' => ['EliteBook 840 G7 (2020)', 'EliteBook 840 G8 (2021)', 'EliteBook 840 G9 (2022)', 'EliteBook 840 G10 (2023)', 'EliteBook 840 G11 (2024)', 'EliteBook 845 G7 (2020)', 'EliteBook 845 G8 (2021)', 'EliteBook 845 G9 (2022)', 'EliteBook 845 G10 (2023)', 'EliteBook 845 G11 (2024)', 'EliteBook 860 G9 (2022)', 'EliteBook 860 G10 (2023)', 'EliteBook 860 G11 (2024)', 'Elite Dragonfly G2 (2021)', 'Elite Dragonfly G3 (2022)', 'Dragonfly G4 (2023)', 'ProBook 430 G7 (2020)', 'ProBook 430 G8 (2021)', 'ProBook 430 G9 (2022)', 'ProBook 430 G10 (2023)', 'ProBook 430 G11 (2024)', 'ProBook 440 G7 (2020)', 'ProBook 440 G8 (2021)', 'ProBook 440 G9 (2022)', 'ProBook 440 G10 (2023)', 'ProBook 440 G11 (2024)', 'ProBook 450 G7 (2020)', 'ProBook 450 G8 (2021)', 'ProBook 450 G9 (2022)', 'ProBook 450 G10 (2023)', 'ProBook 450 G11 (2024)', 'ZBook Firefly 14 G7 (2020)', 'ZBook Firefly 14 G8 (2021)', 'ZBook Firefly 14 G9 (2022)', 'ZBook Firefly 14 G10 (2023)', 'ZBook Firefly 14 G11 (2024)', 'ZBook Studio G7 (2020)', 'ZBook Studio G8 (2021)', 'ZBook Studio G9 (2022)', 'ZBook Studio G10 (2023)', 'ZBook Fury 15 G7 (2020)', 'ZBook Fury 15 G8 (2021)', 'ZBook Fury 16 G9 (2022)', 'ZBook Fury 16 G10 (2023)', 'ZBook Fury 16 G11 (2024)', 'Spectre x360 13 (2020)', 'Spectre x360 14 (2021)', 'Spectre x360 14 (2022)', 'Spectre x360 14 (2023)', 'Spectre x360 14 (2024)', 'Spectre x360 16 (2022)', 'Spectre x360 16 (2023)', 'Envy x360 13 (2020)', 'Envy x360 13 (2022)', 'Envy x360 14 (2024)', 'Envy x360 15 (2021)', 'Envy x360 15 (2023)', 'Envy x360 16 (2024)', 'Pavilion 14 (2020)', 'Pavilion 14 (2022)', 'Pavilion 15 (2020)', 'Pavilion 15 (2022)', 'Pavilion Plus 14 (2023)', 'Pavilion x360 14 (2021)', 'OMEN 15 (2020)', 'OMEN 16 (2021)', 'OMEN 16 (2023)', 'OMEN 16 (2024)', 'OMEN 17 (2023)', 'Victus 15 (2022)', 'Victus 15 (2023)', 'Victus 16 (2021)', 'Victus 16 (2023)', 'HP 250 G8 (2021)', 'HP 250 G9 (2022)', 'HP 250 G10 (2023)', 'HP 255 G8 (2021)', 'HP 255 G9 (2022)', 'HP 255 G10 (2023)'],
        'Počítač' => ['Pavilion Desktop', 'Envy Desktop', 'OMEN 25L', 'OMEN 30L', 'OMEN 40L', 'OMEN 45L', 'Victus 15L', 'EliteDesk 800', 'ProDesk 400', 'ProDesk 600', 'Z2 Workstation', 'Z4 Workstation', 'Z6 Workstation', 'Z8 Workstation', 'HP All-in-One 22', 'HP All-in-One 24', 'HP All-in-One 27'],
    ],
    'Acer' => [
        'Notebook' => ['Aspire 3 A315-58 (2021)', 'Aspire 3 A315-59 (2022)', 'Aspire 3 A315-24P (2023)', 'Aspire 5 A515-55 (2020)', 'Aspire 5 A515-56 (2021)', 'Aspire 5 A515-57 (2022)', 'Aspire 5 A515-58M (2023)', 'Aspire 7 A715-42G (2021)', 'Aspire 7 A715-76G (2022)', 'Swift 3 SF314-42 (2020)', 'Swift 3 SF314-511 (2021)', 'Swift 3 SF314-512 (2022)', 'Swift X SFX14-41G (2021)', 'Swift X SFX14-51G (2022)', 'Swift X 14 SFX14-71G (2023)', 'Swift Go 14 SFG14-71 (2023)', 'Swift Go 14 SFG14-72 (2024)', 'Swift Go 16 SFG16-71 (2023)', 'Swift Go 16 SFG16-72 (2024)', 'Spin 3 SP314-54N (2020)', 'Spin 3 SP314-55N (2021)', 'Spin 5 SP513-54N (2020)', 'Spin 5 SP514-51N (2022)', 'TravelMate P2 TMP214 (2020)', 'TravelMate P2 TMP215 (2021)', 'TravelMate P4 TMP414 (2022)', 'TravelMate P6 TMP614 (2023)', 'Predator Helios 300 PH315-53 (2020)', 'Predator Helios 300 PH315-54 (2021)', 'Predator Helios 300 PH315-55 (2022)', 'Predator Helios Neo 16 PHN16-71 (2023)', 'Predator Helios Neo 16 PHN16-72 (2024)', 'Predator Triton 500 SE PT516-51s (2021)', 'Nitro 5 AN515-55 (2020)', 'Nitro 5 AN515-57 (2021)', 'Nitro 5 AN515-58 (2022)', 'Nitro 16 AN16-41 (2023)', 'Nitro 16 AN16-42 (2024)', 'Nitro V 15 ANV15-51 (2023)', 'Chromebook Spin 713 (2020)', 'Chromebook Spin 714 (2022)'],
        'Počítač' => ['Aspire TC', 'Aspire XC', 'Veriton X', 'Veriton M', 'Predator Orion 3000', 'Predator Orion 5000', 'Nitro 50', 'ConceptD 300', 'ConceptD 500'],
    ],
    'MSI' => [
        'Notebook' => ['Modern 14 B11M (2021)', 'Modern 14 C12M (2022)', 'Modern 14 C13M (2023)', 'Modern 15 A11M (2021)', 'Modern 15 B12M (2022)', 'Modern 15 B13M (2023)', 'Prestige 14 Evo A11M (2021)', 'Prestige 14 Evo A12M (2022)', 'Prestige 14 Evo B13M (2023)', 'Prestige 13 Evo A13M (2023)', 'Prestige 13 AI Evo A14M (2024)', 'Prestige 16 Evo A13M (2023)', 'Prestige 16 AI Evo B1M (2024)', 'Summit E13 Flip Evo A11MT (2021)', 'Summit E13 Flip Evo A12MT (2022)', 'Summit E13 Flip Evo A13MT (2023)', 'Summit E14 Flip Evo A12MT (2022)', 'Summit E14 Flip Evo A13MT (2023)', 'Katana GF66 11UE (2021)', 'Katana GF66 12UE (2022)', 'Katana 15 B12V (2023)', 'Katana 15 B13V (2023)', 'Katana 17 B13V (2023)', 'Cyborg 15 A12V (2023)', 'Cyborg 15 A13V (2023)', 'Thin GF63 10SC (2020)', 'Thin GF63 11UC (2021)', 'Thin GF63 12VE (2023)', 'Pulse GL66 11UE (2021)', 'Pulse GL66 12UE (2022)', 'Stealth 15M A11UE (2021)', 'Stealth 15M A12UE (2022)', 'Stealth 14 Studio A13V (2023)', 'Stealth 16 Studio A13V (2023)', 'Stealth 16 AI Studio A1V (2024)', 'Vector GP66 11UG (2021)', 'Vector GP66 12UG (2022)', 'Vector GP68 HX 13V (2023)', 'Vector GP68 HX 14V (2024)', 'Raider GE66 10SGS (2020)', 'Raider GE66 11UH (2021)', 'Raider GE66 12U (2022)', 'Raider GE78 HX 13V (2023)', 'Raider GE78 HX 14V (2024)', 'Creator Z16 A11U (2021)', 'Creator Z16 A12U (2022)', 'CreatorPro Z16P B12U (2022)', 'Titan GT77 12U (2022)', 'Titan GT77 HX 13V (2023)', 'Titan 18 HX A14V (2024)'],
        'Počítač' => ['MAG Infinite', 'MAG Codex', 'MEG Trident X', 'Trident 3', 'Aegis RS', 'Creator P50', 'Cubi 5', 'Cubi N', 'Pro DP21', 'Pro DP130'],
    ],
    'Sony' => [
        'Telefon' => ['Xperia Z', 'Xperia Z1', 'Xperia Z2', 'Xperia Z3', 'Xperia Z5', 'Xperia X', 'Xperia X Compact', 'Xperia X Performance', 'Xperia XZ', 'Xperia XZ1', 'Xperia XZ2', 'Xperia XZ3', 'Xperia 1', 'Xperia 1 II', 'Xperia 1 III', 'Xperia 1 IV', 'Xperia 1 V', 'Xperia 1 VI', 'Xperia 5', 'Xperia 5 II', 'Xperia 5 III', 'Xperia 5 IV', 'Xperia 5 V', 'Xperia 10', 'Xperia 10 II', 'Xperia 10 III', 'Xperia 10 IV', 'Xperia 10 V', 'Xperia 10 VI', 'Xperia Pro', 'Xperia Pro-I', 'Xperia Ace'],
        'Herní konzole' => ['PlayStation 3 Slim', 'PlayStation 3 Super Slim', 'PlayStation 4', 'PlayStation 4 Slim', 'PlayStation 4 Pro', 'PlayStation 5', 'PlayStation 5 Digital Edition', 'PlayStation 5 Slim', 'PlayStation 5 Pro', 'PSP', 'PSP Go', 'PlayStation Vita', 'PlayStation Portal'],
        'Sluchátka' => ['WH-1000XM3', 'WH-1000XM4', 'WH-1000XM5', 'WF-1000XM3', 'WF-1000XM4', 'WF-1000XM5', 'WF-C500', 'WF-C700N', 'WH-CH720N', 'WH-XB910N', 'LinkBuds', 'LinkBuds S', 'Pulse 3D', 'Pulse Elite', 'Pulse Explore'],
    ],
    'Nintendo' => [
        'Herní konzole' => ['Nintendo DS Lite', 'Nintendo DSi', 'Nintendo 3DS', 'Nintendo 3DS XL', 'Nintendo 2DS', 'New Nintendo 3DS', 'New Nintendo 3DS XL', 'Nintendo Wii', 'Nintendo Wii U', 'Nintendo Switch', 'Nintendo Switch Lite', 'Nintendo Switch OLED', 'Nintendo Switch 2'],
    ],
    'Microsoft' => [
        'Herní konzole' => ['Xbox 360 S', 'Xbox 360 E', 'Xbox One', 'Xbox One S', 'Xbox One X', 'Xbox Series S', 'Xbox Series X'],
        'Tablet' => ['Surface RT', 'Surface 2', 'Surface 3', 'Surface Pro 3', 'Surface Pro 4', 'Surface Pro 5', 'Surface Pro 6', 'Surface Pro 7', 'Surface Pro 7+', 'Surface Pro 8', 'Surface Pro 9', 'Surface Pro 10', 'Surface Pro 11', 'Surface Go', 'Surface Go 2', 'Surface Go 3', 'Surface Go 4'],
        'Notebook' => ['Surface Laptop 3 13.5″ (2020)', 'Surface Laptop 3 15″ (2020)', 'Surface Laptop 4 13.5″ (2021)', 'Surface Laptop 4 15″ (2021)', 'Surface Laptop 5 13.5″ (2022)', 'Surface Laptop 5 15″ (2022)', 'Surface Laptop 6 13.5″ (2024)', 'Surface Laptop 6 15″ (2024)', 'Surface Laptop 7 13.8″ (2024)', 'Surface Laptop 7 15″ (2024)', 'Surface Laptop Go (2020)', 'Surface Laptop Go 2 (2022)', 'Surface Laptop Go 3 (2023)', 'Surface Laptop Studio (2021)', 'Surface Laptop Studio 2 (2023)', 'Surface Book 3 13.5″ (2020)', 'Surface Book 3 15″ (2020)'],
    ],
];

function afxProductManufacturers(): array {
    return ['Apple', 'Samsung', 'Xiaomi', 'Asus', 'Doogee', 'Honor', 'Huawei', 'Motorola', 'Nokia',
        'Lenovo', 'Dell', 'HP', 'Acer', 'MSI', 'Sony', 'Nintendo', 'Microsoft'];
}

function afxBrandProductTypes(string $manufacturer, string $k, array $typeIds, array $colors = AFX_ANDROID_COLORS): array {
    $out = [];
    foreach ($typeIds as $id) {
        $out[] = ['id' => $id, 'manuf' => $manufacturer, 'k' => $k, 'cap' => true,
            'ram' => false, 'gen' => false,
            'colors' => in_array($id, ['Notebook', 'Počítač'], true) ? AFX_COMPUTER_COLORS : $colors,
            'models' => AFX_BRAND_MODELS[$manufacturer][$id] ?? []];
    }
    return $out;
}

function afxProductAccessoryTypes(): array {
    static $types = null;
    if ($types === null) {
        $ids = [
            'Adaptér',
            'Nabíječka',
            'Kabel USB-C',
            'Kabel Lightning',
            'Kabel Micro USB',
            'Kabel USB-A',
            'Kabel HDMI',
            'Redukce / hub',
            'Obal / kryt',
            'Ochranné sklo',
            'Fólie',
            'Klávesnice',
            'Myš',
            'Sluchátka',
            'Reproduktor',
            'Powerbanka',
            'MagSafe příslušenství',
            'Držák / stojan',
            'Dock / stanice',
            'Řemínek',
            'Stylus / Apple Pencil',
            'AirTag / lokátor',
            'Externí disk / box',
            'Čtečka karet',
        ];
        $types = array_map(static fn($id): array => [
            'id' => $id,
            'manuf' => '',
            'k' => '',
            'cap' => false,
            'ram' => false,
            'gen' => false,
            'colors' => AFX_ACCESSORY_COLORS,
            'models' => [],
            'accessory' => true,
        ], $ids);
    }
    return $types;
}

/** Typy zařízení: id → [výrobce, K-kód kategorie, cap?, ram?, gen?, barvy, modely]
 *  POZOR: 'ram' už NEznamená „má RAM pole" (RAM/jádra bere každý typ) — znamená
 *  „macový formát názvu" (32 GB/512 GB SSD). Ne-Macy skládají „8 GB RAM 256 GB". */
function afxProductTypes(): array {
    static $types = null;
    if ($types === null) {
        $types = array_merge([
            ['id' => 'iPhone', 'manuf' => 'Apple', 'k' => 'K00039', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_APPLE_COLORS, 'models' => AFX_IPHONES],
            ['id' => 'iPad', 'manuf' => 'Apple', 'k' => 'K00041', 'cap' => true, 'ram' => false, 'gen' => true, 'colors' => AFX_APPLE_COLORS, 'models' => AFX_IPADS],
            ['id' => 'MacBook', 'manuf' => 'Apple', 'k' => '', 'kmatch' => ['K00143', 'K00144'], 'cap' => true, 'ram' => true, 'gen' => false,
                'colors' => ['Space Gray', 'Silver', 'Space Black', 'Starlight', 'Midnight', 'Sky Blue'], 'models' => array_merge(AFX_MACBOOKS_PRO, AFX_MACBOOKS_AIR)],
            ['id' => 'iMac', 'manuf' => 'Apple', 'k' => '', 'cap' => true, 'ram' => true, 'gen' => false, 'colors' => ['Silver', 'Blue', 'Green', 'Pink', 'Purple', 'Yellow', 'Orange'], 'models' => AFX_IMACS],
            ['id' => 'Mac mini', 'manuf' => 'Apple', 'k' => '', 'cap' => true, 'ram' => true, 'gen' => false, 'colors' => ['Silver', 'Space Gray'], 'models' => AFX_MAC_MINI],
            ['id' => 'Mac Studio', 'manuf' => 'Apple', 'k' => '', 'cap' => true, 'ram' => true, 'gen' => false, 'colors' => ['Silver'], 'models' => AFX_MAC_STUDIO],
            ['id' => 'Mac Pro', 'manuf' => 'Apple', 'k' => '', 'cap' => true, 'ram' => true, 'gen' => false, 'colors' => ['Silver', 'Black'], 'models' => AFX_MAC_PRO],
            ['id' => 'Apple Watch', 'manuf' => 'Apple', 'k' => '', 'cap' => false, 'ram' => false, 'gen' => false, 'colors' => AFX_APPLE_COLORS, 'models' => AFX_APPLE_WATCHES],
            ['id' => 'AirPods', 'manuf' => 'Apple', 'k' => '', 'cap' => false, 'ram' => false, 'gen' => false, 'colors' => ['White', 'Midnight', 'Blue', 'Purple', 'Orange'], 'models' => AFX_AIRPODS],
            ['id' => 'Apple TV', 'manuf' => 'Apple', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => ['Black'], 'models' => AFX_APPLE_TV],
            ['id' => 'HomePod', 'manuf' => 'Apple', 'k' => '', 'cap' => false, 'ram' => false, 'gen' => false, 'colors' => ['White', 'Midnight', 'Space Gray', 'Yellow', 'Orange', 'Blue'], 'models' => AFX_HOMEPODS],
        ],
        afxBrandProductTypes('Samsung', 'K00135', ['Telefon', 'Tablet', 'Notebook', 'Hodinky', 'Sluchátka']),
        afxBrandProductTypes('Xiaomi', 'K00136', ['Telefon', 'Tablet', 'Hodinky']),
        afxBrandProductTypes('Asus', 'K00137', ['Telefon', 'Tablet', 'Notebook', 'Počítač']),
        afxBrandProductTypes('Doogee', 'K00138', ['Telefon']),
        afxBrandProductTypes('Honor', 'K00139', ['Telefon', 'Tablet', 'Notebook']),
        afxBrandProductTypes('Huawei', 'K00140', ['Telefon', 'Tablet', 'Notebook', 'Hodinky']),
        afxBrandProductTypes('Motorola', 'K00141', ['Telefon', 'Tablet']),
        afxBrandProductTypes('Nokia', 'K00142', ['Telefon', 'Tablet']),
        afxBrandProductTypes('Lenovo', '', ['Telefon', 'Tablet', 'Notebook', 'Počítač']),
        afxBrandProductTypes('Dell', '', ['Notebook', 'Počítač']),
        afxBrandProductTypes('HP', '', ['Notebook', 'Počítač']),
        afxBrandProductTypes('Acer', '', ['Notebook', 'Počítač']),
        afxBrandProductTypes('MSI', '', ['Notebook', 'Počítač']),
        afxBrandProductTypes('Sony', '', ['Telefon', 'Herní konzole', 'Sluchátka']),
        afxBrandProductTypes('Nintendo', '', ['Herní konzole']),
        afxBrandProductTypes('Microsoft', '', ['Herní konzole', 'Tablet', 'Notebook']),
        [
            ['id' => 'Telefon', 'manuf' => '', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Tablet', 'manuf' => '', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Notebook', 'manuf' => '', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_COMPUTER_COLORS, 'models' => []],
            ['id' => 'Počítač', 'manuf' => '', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_COMPUTER_COLORS, 'models' => []],
            ['id' => 'Hodinky', 'manuf' => '', 'k' => '', 'cap' => false, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Herní konzole', 'manuf' => '', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_COMPUTER_COLORS, 'models' => []],
        ]);
    }
    return $types;
}

function afxProductProcessors(): array {
    return [
        'AMD' => AFX_PROCESSORS_AMD,
        'Intel' => AFX_PROCESSORS_INTEL,
        'M chip - ARM' => AFX_PROCESSORS_M_ARM,
    ];
}

function afxProductProcessorFamiliesForType(array $t): array {
    return (($t['manuf'] ?? '') === 'Apple')
        ? ['Intel', 'M chip - ARM']
        : ['AMD', 'Intel'];
}

function afxProductHasProcessorFields(string $typeId, string $model = ''): bool {
    $hay = mb_strtolower(trim($typeId . ' ' . $model));
    if ($hay === '') return false;
    foreach (['macbook', 'notebook', 'laptop', 'počítač', 'pocitac', 'computer', 'desktop', 'pc', 'imac', 'mac mini', 'mac studio', 'mac pro'] as $needle) {
        if (str_contains($hay, $needle)) return true;
    }
    return false;
}

function afxProcessorDisplay(string $family, string $model): string {
    $family = trim($family);
    $model = trim($model);
    if ($family === '') return '';
    if ($model === '') return $family;
    $lowModel = mb_strtolower($model);
    if ($family !== 'M chip - ARM' && str_contains($lowModel, mb_strtolower($family))) {
        return $model;
    }
    if ($family === 'M chip - ARM') {
        if (preg_match('/^(apple\s+)?m\d/iu', $model)) return $model;
        return 'Apple ' . $model;
    }
    return $family . ' ' . $model;
}

function afxProductSpecText(string $value): string {
    $value = mb_strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function afxProductTitleHasProcessor(string $titleBase, string $processor): bool {
    $hay = afxProductSpecText($titleBase);
    $needle = afxProductSpecText($processor);
    if ($hay === '' || $needle === '') return false;
    $needleNoBrand = trim(preg_replace('/^(apple|amd|intel)\s+/u', '', $needle) ?? $needle);
    if (preg_match('/\bm\d(?:\s+(?:pro|max|ultra))?\b/u', $needle, $m) && str_contains($hay, $m[0])) return true;
    return str_contains($hay, $needle) || ($needleNoBrand !== '' && str_contains($hay, $needleNoBrand));
}

function afxProductProcessorTitlePart(string $titleBase, string $processor): string {
    if ($processor === '' || afxProductTitleHasProcessor($titleBase, $processor)) return '';
    $part = $processor;
    if (preg_match('/^(Intel|AMD|Apple)\s+/u', $part, $m)) {
        $hay = ' ' . afxProductSpecText($titleBase) . ' ';
        if (str_contains($hay, ' ' . mb_strtolower($m[1]) . ' ')) {
            $part = preg_replace('/^(Intel|AMD|Apple)\s+/u', '', $part) ?? $part;
        }
    }
    return trim($part);
}

function afxProductTypeMatchesCategory(array $t, string $categoryCode): bool {
    if ($categoryCode === '') return false;
    if (($t['k'] ?? '') === $categoryCode) return true;
    return in_array($categoryCode, $t['kmatch'] ?? [], true);
}

function afxProductTypeById(string $id, string $manufacturer = ''): array {
    $id = trim($id);
    $manufacturer = trim($manufacturer);
    $legacyBrandTypes = array_diff(afxProductManufacturers(), ['Apple']);
    if ($manufacturer === '' && in_array($id, $legacyBrandTypes, true)) {
        $manufacturer = $id;
        $id = 'Telefon';
    } elseif ($manufacturer === '' && in_array($id, ['MacBook Pro', 'MacBook Air'], true)) {
        $manufacturer = 'Apple';
        $id = 'MacBook';
    }
    if ($manufacturer === '') {
        foreach (afxProductAccessoryTypes() as $t) {
            if ($t['id'] === $id) return $t;
        }
    }
    foreach (afxProductTypes() as $t) {
        if ($t['id'] === $id && $manufacturer !== '' && $t['manuf'] === $manufacturer) return $t;
    }
    foreach (afxProductTypes() as $t) {
        if ($t['id'] === $id && ($t['manuf'] ?? '') === '') {
            $t['manuf'] = $manufacturer;
            return $t;
        }
    }
    foreach (afxProductTypes() as $t) {
        if ($t['id'] === $id) return $t;
    }
    // vlastní (neznámý) typ — jako GENERIC_TYPE v appce
    return ['id' => $id, 'manuf' => $manufacturer, 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => [], 'models' => []];
}

function afxProductCategoryCode(array $t, string $model): string {
    $id = (string)($t['id'] ?? '');
    if (($t['manuf'] ?? '') === 'Apple' && $id === 'MacBook') {
        $low = mb_strtolower($model);
        if (str_contains($low, 'macbook air')) return 'K00144';
        if (str_contains($low, 'macbook pro')) return 'K00143';
    }
    return (string)($t['k'] ?? '');
}

function afxProductDisplayModel(array $t, string $model): string {
    $typeId = trim((string)($t['id'] ?? ''));
    $manufacturer = trim((string)($t['manuf'] ?? ''));
    $displayModel = trim($model);
    if ($displayModel === '') return '';
    $lowModel = mb_strtolower($displayModel);
    if ($typeId !== '' && str_starts_with($lowModel, mb_strtolower($typeId))) return $displayModel;
    if ($manufacturer !== '' && str_starts_with($lowModel, mb_strtolower($manufacturer))) return $displayModel;
    if ($manufacturer !== '' && $manufacturer !== 'Apple') return $displayModel;
    if ($typeId !== '') return $typeId . ' ' . $displayModel;
    return $displayModel;
}

function afxProductTitleModel(array $t, string $displayModel): string {
    $manufacturer = trim((string)($t['manuf'] ?? ''));
    if ($manufacturer !== '' && $manufacturer !== 'Apple'
        && !str_starts_with(mb_strtolower($displayModel), mb_strtolower($manufacturer))) {
        return $manufacturer . ' ' . $displayModel;
    }
    return $displayModel;
}

/**
 * Složení kompletního „řádku" produktu z polí formuláře — port form_row()+build_title().
 * $in: typ, model, cap, color, grade (celý label nebo token), battery, price, serial,
 *      ram, cpu, gpu, processor_family, processor_model, rocnik, generace, sold(bool), stock_key, image_url,
 *      pcr_status, pcr_text, code (hotový product_code), added (Y-m-d H:i)
 * Vrací: ['title', 'short_desc', 'assoc' => klíče raw_csv, 'manuf', 'k', 'grade_token', 'display_model']
 */
function afxProductAssemble(array $in): array {
    $t = afxProductTypeById(trim((string)($in['typ'] ?? '')), trim((string)($in['manufacturer'] ?? '')));
    $model = trim((string)($in['model'] ?? ''));
    $cap = $t['cap'] ? trim((string)($in['cap'] ?? '')) : '';
    $color = trim((string)($in['color'] ?? ''));
    // RAM/jádra bere KAŽDÝ typ (mají je i telefony/tablety/konzole — přání 31.7.);
    // příznak $t['ram'] už neřídí VSTUP, jen „macový" formát názvu (X/Y SSD) níže.
    $ram = trim((string)($in['ram'] ?? ''));
    $cpu = trim((string)($in['cpu'] ?? ''));
    $gpu = trim((string)($in['gpu'] ?? ''));
    $processorFamily = trim((string)($in['processor_family'] ?? ''));
    if (!in_array($processorFamily, afxProductProcessorFamiliesForType($t), true) || !afxProductHasProcessorFields((string)$t['id'], $model)) {
        $processorFamily = '';
    }
    $processorModel = $processorFamily !== '' ? trim((string)($in['processor_model'] ?? '')) : '';
    $processorDisplay = afxProcessorDisplay($processorFamily, $processorModel);
    $bat = trim((string)($in['battery'] ?? ''));
    $bat = rtrim(str_replace('%', '', $bat));   // ukládá se bez %, do CSV s " %"
    $bat = trim($bat);
    $rocnik = trim((string)($in['rocnik'] ?? ''));
    $generace = $t['gen'] ? trim((string)($in['generace'] ?? '')) : '';
    $sold = !empty($in['sold']);
    $priceStr = trim((string)($in['price'] ?? ''));

    // grade: „A – jako nové" → „A"; prázdné → „A" (přesně grade_val() z appky)
    $gradeRaw = trim((string)($in['grade'] ?? ''));
    $gradeToken = $gradeRaw !== '' ? trim(explode(' ', $gradeRaw)[0]) : 'A';

    $displayModel = afxProductDisplayModel($t, $model);
    $titleModel = afxProductTitleModel($t, $displayModel);
    $categoryCode = afxProductCategoryCode($t, $displayModel);

    // build_title()
    if ($t['ram']) {
        // Mac: PŘESNÁ parita s appkou — „32 GB/512 GB SSD"
        if ($ram !== '' && $cap !== '') { $mem = $ram . '/' . $cap . ' SSD'; }
        elseif ($ram !== '') { $mem = $ram . ' RAM'; }
        else { $mem = $cap; }
    } else {
        // ostatní typy: RAM bez „SSD" žargonu — „8 GB RAM 256 GB"
        $memParts = [];
        if ($ram !== '') { $memParts[] = $ram . ' RAM'; }
        if ($cap !== '') { $memParts[] = $cap; }
        $mem = implode(' ', $memParts);
    }
    $coresParts = [];
    if ($cpu !== '') $coresParts[] = $cpu . ' CPU';
    if ($gpu !== '') $coresParts[] = $gpu . ' GPU';
    $cores = implode(' ', $coresParts);
    $specParts = [];
    $processorTitlePart = afxProductProcessorTitlePart($titleModel, $processorDisplay);
    if ($processorTitlePart !== '') $specParts[] = $processorTitlePart;
    if ($cores !== '' && $mem !== '') { $specParts[] = $cores . ', ' . $mem; }
    elseif ($cores !== '' || $mem !== '') { $specParts[] = $cores !== '' ? $cores : $mem; }
    $spec = implode(', ', $specParts);
    // Stav (grade) do NÁZVU nepatří (přání 1.8.2026) — zůstává jen v parametru
    // [PARAMETER "Stav"], v popisu a v buňce Stav (CRM tabulka i e-shop).
    $titleParts = array_filter([$titleModel, $spec, $color], static fn($p) => $p !== '');
    $title = trim(implode(' ', $titleParts));

    // SHORT_DESCRIPTION — pořadí přesně dle form_row()
    $sd = [];
    if ($gradeToken !== '') $sd[] = 'Stav: ' . $gradeToken;
    if ($bat !== '') $sd[] = 'Kondice baterie: ' . $bat . ' %';
    if ($processorDisplay !== '') $sd[] = 'Procesor: ' . $processorDisplay;
    if ($cpu !== '') $sd[] = 'Jader CPU: ' . $cpu;
    if ($gpu !== '') $sd[] = 'Jader GPU: ' . $gpu;
    if ($ram !== '') $sd[] = 'RAM: ' . $ram;
    if ($cap !== '') $sd[] = 'Úložiště: ' . $cap;
    if ($color !== '') $sd[] = 'Barva: ' . $color;
    if ($rocnik !== '') $sd[] = 'Ročník: ' . $rocnik;
    if ($generace !== '') $sd[] = 'Generace: ' . $generace;
    $sd[] = 'Zvláštní režim DPH §90 (použité zboží)';
    $shortDesc = implode(' | ', $sd);

    $stockVal = $sold ? '0' : '1';
    $stockKey = (string)($in['stock_key'] ?? '');
    $code = (string)($in['code'] ?? '');
    $added = (string)($in['added'] ?? date('Y-m-d H:i'));
    $pcrStatus = (string)($in['pcr_status'] ?? '');
    $pcrText = (string)($in['pcr_text'] ?? '');

    $assoc = [
        '[PRODUCT_CODE]' => $code,
        '[ACTIVE_YN]' => '1',
        '[TITLE]' => $title,
        '[MANUFACTURER]' => $t['manuf'],
        '[CATEGORIES]' => $categoryCode,
        '[AVAILABILITY]' => $sold ? 'Vyprodáno' : 'Skladem',
        '[STOCK]' => $stockVal,
        '[PRICE_ORIGINAL "Výchozí"]' => $priceStr,
        '[IS_PRICES_WITH_VAT_YN]' => '1',
        '[VAT]' => '0',
        '[SHORT_DESCRIPTION]' => $shortDesc,
        '[PARAMETER "Výrobce"]' => $t['manuf'],
        '[PARAMETER "Typ zařízení"]' => (string)$t['id'],
        '[PARAMETER "Model"]' => $displayModel,
        '[PARAMETER "Kapacita"]' => $cap,
        '[PARAMETER "Barva"]' => $color,
        '[PARAMETER "Stav"]' => $gradeToken,
        '[PARAMETER "Baterie"]' => $bat !== '' ? $bat . ' %' : '',
        '[STOCK_STOCK "karlin"]' => $stockKey === 'karlin' ? $stockVal : '',
        '[STOCK_STOCK "vaclavak"]' => $stockKey === 'vaclavak' ? $stockVal : '',
        '[PARAMETER "RAM"]' => $ram,
        '[PARAMETER "Výrobce procesoru"]' => $processorFamily,
        '[PARAMETER "Procesor"]' => $processorDisplay,
        'PRIDANO' => $added,
        'PRODUKT_TYP' => (string)$t['id'],
        'CPU_JADRA' => $cpu,
        'GPU_JADRA' => $gpu,
        'PROCESOR_TYP' => $processorFamily,
        'PROCESOR_MODEL' => $processorModel,
        '[PARAMETER "Ročník"]' => $rocnik,
        '[PARAMETER "Generace"]' => $generace,
        'PCR_DATUM' => ($pcrStatus !== '' && $pcrStatus !== 'notimei') ? $added : '',
        'PCR_VYSLEDEK' => $pcrText,
        '[IMAGES]' => (string)($in['image_url'] ?? ''),
    ];

    return [
        'title' => $title,
        'short_desc' => $shortDesc,
        'assoc' => $assoc,
        'manuf' => $t['manuf'],
        'k' => $categoryCode,
        'grade_token' => $gradeToken,
        'display_model' => $displayModel,
        'battery_csv' => $bat !== '' ? $bat . ' %' : '',
    ];
}

/** Kanonická hlavička exportu skladu — pro export CSV. */
function afxProductCsvHeader(): array {
    return ['[PRODUCT_CODE]', '[ACTIVE_YN]', '[TITLE]', '[MANUFACTURER]', '[CATEGORIES]', '[AVAILABILITY]',
        '[STOCK]', '[PRICE_ORIGINAL "Výchozí"]', '[IS_PRICES_WITH_VAT_YN]', '[VAT]', '[SHORT_DESCRIPTION]',
        '[PARAMETER "Výrobce"]', '[PARAMETER "Typ zařízení"]', '[PARAMETER "Model"]', '[PARAMETER "Kapacita"]', '[PARAMETER "Barva"]', '[PARAMETER "Stav"]',
        '[PARAMETER "Baterie"]', '[STOCK_STOCK "karlin"]', '[STOCK_STOCK "vaclavak"]', '[PARAMETER "RAM"]',
        '[PARAMETER "Výrobce procesoru"]', '[PARAMETER "Procesor"]',
        'PRIDANO', 'CPU_JADRA', 'GPU_JADRA', '[PARAMETER "Ročník"]', '[PARAMETER "Generace"]',
        'PCR_DATUM', 'PCR_VYSLEDEK', '[IMAGES]'];
}
