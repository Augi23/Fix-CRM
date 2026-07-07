<?php
/**
 * Import AppleFix repair orders from CSV.
 *
 * CLI only. Dry-run is the default; pass --apply to write to DB.
 * Expected CSV columns:
 * "Kód";"Zákazník";"Telefon";"Zařízení";"IMEI/SN";"Požadovaná oprava";"Stav zakázky"
 *
 * Customer matching is deterministic:
 *  1. normalized phone, with order/customer name tie-break for duplicated phones
 *  2. normalized customer name/company, only when it resolves to exactly one customer
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

require_once __DIR__ . '/import_customers_csv.php';

function import_order_normalize(?string $value): string
{
    $value = (string)($value ?? '');
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function import_order_header_key(string $value): string
{
    $value = mb_strtolower(import_order_normalize($value), 'UTF-8');
    $value = strtr($value, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
    ]);
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    return trim($value, '_');
}

function import_order_detect_delimiter(string $line): string
{
    $candidates = [';' => 0, ',' => 0, "\t" => 0];
    foreach (array_keys($candidates) as $delimiter) {
        $candidates[$delimiter] = count(str_getcsv($line, $delimiter));
    }
    arsort($candidates);
    return (string)array_key_first($candidates);
}

function import_order_truncate(?string $value, int $limit): ?string
{
    $value = import_order_normalize($value);
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, $limit, 'UTF-8');
}

function import_order_normalize_phone(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string)($value ?? '')) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    // Czech local mobile/landline numbers in imports are often missing +420.
    if (strlen($digits) === 9) {
        $digits = '420' . $digits;
    }
    return $digits;
}

function import_order_name_key(?string $value): string
{
    $value = mb_strtolower(import_order_normalize($value), 'UTF-8');
    $value = strtr($value, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
    ]);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
    return trim($value);
}

function import_order_header_map(array $headers): array
{
    $aliases = [
        'kod' => 'code',
        'zakaznik' => 'customer_name',
        'klient' => 'customer_name',
        'telefon' => 'phone',
        'telefonni_cislo' => 'phone',
        'zarizeni' => 'device',
        'imei_sn' => 'serial_number',
        'imei' => 'serial_number',
        'sn' => 'serial_number',
        'seriove_cislo' => 'serial_number',
        'pozadovana_oprava' => 'repair_request',
        'oprava' => 'repair_request',
        'problem' => 'repair_request',
        'stav_zakazky' => 'source_status',
        'stav' => 'source_status',
        'status' => 'source_status',
    ];

    $map = [];
    foreach ($headers as $index => $header) {
        $key = import_order_header_key((string)$header);
        if (isset($aliases[$key])) {
            $map[$index] = $aliases[$key];
        }
    }

    foreach (['code', 'customer_name', 'phone', 'device', 'serial_number', 'repair_request', 'source_status'] as $required) {
        if (!in_array($required, $map, true)) {
            throw new RuntimeException("Missing required CSV column for: {$required}");
        }
    }

    return $map;
}

function import_order_read_csv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("CSV file is not readable: {$path}");
    }

    $probe = fopen($path, 'rb');
    if ($probe === false) {
        throw new RuntimeException("Cannot open CSV file: {$path}");
    }
    $firstLine = fgets($probe) ?: '';
    fclose($probe);

    $delimiter = import_order_detect_delimiter($firstLine);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open CSV file: {$path}");
    }

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException('CSV file is empty.');
    }

    $headerMap = import_order_header_map($headers);
    $rows = [];
    $rowNo = 1;
    while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNo++;
        if ($csvRow === [null]) {
            continue;
        }

        $row = [
            'row_no' => $rowNo,
            'code' => '',
            'customer_name' => '',
            'phone' => '',
            'device' => '',
            'serial_number' => '',
            'repair_request' => '',
            'source_status' => '',
        ];
        foreach ($headerMap as $index => $field) {
            $row[$field] = import_order_normalize($csvRow[$index] ?? '');
        }
        if (implode('', array_map('strval', array_diff_key($row, ['row_no' => true]))) === '') {
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return [$delimiter, $rows];
}

function import_order_infer_device_type(string $device): string
{
    $key = import_order_name_key($device);
    if (preg_match('/\b(ipad|tablet|tab)\b/u', $key)) {
        return 'Tablet';
    }
    if (preg_match('/\b(macbook|notebook|laptop)\b/u', $key)) {
        return 'Notebook';
    }
    if (preg_match('/\b(hdd|ssd|disk)\b/u', $key)) {
        return 'HDD';
    }
    if (preg_match('/\b(pc|desktop|gpu|grafick)\b/u', $key)) {
        return 'PC';
    }
    if (preg_match('/\b(iphone|phone|telefon|samsung|sumsung|xiaomi|redmi|poco|motorola|huawei|honor|nokia|oneplus|sony|lg|htc|doogee|pixel)\b/u', $key)) {
        return 'Phone';
    }
    return 'Other';
}

function import_order_infer_brand(string $device): ?string
{
    $key = import_order_name_key($device);
    $brandRules = [
        'Apple' => '/\b(apple|iphone|ipad|macbook|imac|mac mini|apple tv)\b/u',
        'Samsung' => '/\b(samsung|sumsung)\b/u',
        'Asus' => '/\b(asus)\b/u',
        'Dell' => '/\b(dell)\b/u',
        'HP' => '/\b(hp|hewlett)\b/u',
        'Lenovo' => '/\b(lenovo)\b/u',
        'HUAWEI' => '/\b(huawei)\b/u',
        'Honor' => '/\b(honor)\b/u',
        'Xiaomi' => '/\b(xiaomi|redmi|poco)\b/u',
        'Motorola' => '/\b(motorola|moto)\b/u',
        'Nokia' => '/\b(nokia)\b/u',
        'Sony' => '/\b(sony)\b/u',
        'OnePlus' => '/\b(oneplus|one plus)\b/u',
        'LG' => '/\b(lg)\b/u',
        'HTC' => '/\b(htc)\b/u',
        'Acer' => '/\b(acer)\b/u',
        'Toshiba' => '/\b(toshiba)\b/u',
        'MSI' => '/\b(msi)\b/u',
        'ZTE' => '/\b(zte)\b/u',
        'Google' => '/\b(google|pixel)\b/u',
        'Garmin' => '/\b(garmin)\b/u',
    ];
    foreach ($brandRules as $brand => $regex) {
        if (preg_match($regex, $key)) {
            return $brand;
        }
    }
    return null;
}

/* ============================================================
   PRAVIDLA PŘEVODU ZE STARÉHO SYSTÉMU „ZAKÁZKOVÝ LIST"
   Staré značky se při importu VŽDY převádí na nový model CRM:
   - stav  -> import_order_status_map()
   - značka pobočky -> import_order_branch_code()
   Konkrétně: „černá růže" NENÍ stav, ale historická značka zakázky
   druhé pobočky (pasáž Černá růže) => stav 'Přijato' + pobočka
   'prikope' (Praha 1 - Na Příkopě). Původní hodnota se vždy ukládá
   do poznámek ("Původní stav: ..."), takže se nic neztrácí.
   Při dalším rozšíření mapování doplnit i self-test níže!
   ============================================================ */
function import_order_status_map(string $sourceStatus): string
{
    $key = import_order_name_key($sourceStatus);
    $map = [
        'prijato' => 'Přijato',
        'zaklada se' => 'Zakládá se',
        'v oprave' => 'V opravě',
        'v oprave zak desky' => 'V opravě zák. desky',
        'v externim servisu' => 'V externím servisu',
        'v aut servisu' => 'V aut. servisu',
        'ceka na dil' => 'Čeká na díl',
        'ceka na zakaznika' => 'Čeká na zákazníka',
        'ceka na platbu' => 'Čeká na platbu',
        'pripraveno k prevzeti' => 'Připraveno k převzetí',
        'nevyzvednuto' => 'Nevyzvednuto',
        'vydano' => 'Vydáno',
        'vydano cr' => 'Vydáno - ČR',
        'stornovano' => 'Stornováno',
        // Historická značka 2. pobočky (viz PRAVIDLA výše) — stav Přijato, pobočku řeší import_order_branch_code().
        'cerna ruze' => 'Přijato',
    ];
    return $map[$key] ?? 'Přijato';
}

/** Značka pobočky ze starého zakázkového listu -> kód pobočky v novém systému (null = výchozí). */
function import_order_branch_code(string $sourceStatus): ?string
{
    $key = import_order_name_key($sourceStatus);
    $map = [
        'cerna ruze' => 'prikope', // pasáž Černá růže = Praha 1 - Na Příkopě
    ];
    return $map[$key] ?? null;
}

function import_order_map_row(array $row, ?int $customerId = null): array
{
    $device = import_order_normalize($row['device'] ?? '');
    $code = import_order_normalize($row['code'] ?? '');
    $sourceStatus = import_order_normalize($row['source_status'] ?? '');
    $repair = import_order_normalize($row['repair_request'] ?? '');
    $noteParts = [];
    if ($sourceStatus !== '') {
        $noteParts[] = "Původní stav: {$sourceStatus}";
    }

    return [
        'customer_id' => $customerId,
        'order_code' => import_order_truncate($code, 32),
        'device_type' => import_order_infer_device_type($device),
        'order_type' => 'Non-Warranty',
        'device_model' => import_order_truncate($device !== '' ? $device : 'Neuvedeno', 100),
        'device_brand' => import_order_truncate(import_order_infer_brand($device) ?? 'Other', 100),
        'serial_number' => import_order_truncate($row['serial_number'] ?? '', 100),
        'serial_number_2' => null,
        'appearance' => null,
        'pin_code' => null,
        'priority' => 'Normal',
        'problem_description' => import_order_truncate($repair !== '' ? $repair : 'Neuvedeno', 65535),
        'technician_notes' => $noteParts ? implode("\n", $noteParts) : null,
        'estimated_cost' => null,
        'final_cost' => null,
        'extra_expenses' => '0.00',
        'status' => import_order_status_map($sourceStatus),
        'technician_id' => null,
        'work_duration_seconds' => 0,
        '_branch_code' => import_order_branch_code($sourceStatus),
    ];
}

function import_order_customer_label(array $customer): string
{
    $name = import_order_normalize(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    $company = import_order_normalize($customer['company'] ?? '');
    return $company !== '' && import_order_name_key($company) !== import_order_name_key($name)
        ? "{$name} ({$company})"
        : $name;
}

function import_order_build_customer_index(array $customers): array
{
    $records = [];
    $byPhone = [];
    $byName = [];

    foreach ($customers as $i => $customer) {
        $id = $customer['id'] ?? $i;
        $record = [
            'id' => $id,
            'first_name' => $customer['first_name'] ?? '',
            'last_name' => $customer['last_name'] ?? '',
            'company' => $customer['company'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'email' => $customer['email'] ?? null,
        ];
        $records[(string)$id] = $record;

        $phone = import_order_normalize_phone($record['phone']);
        if ($phone !== '') {
            $byPhone[$phone][(string)$id] = true;
        }

        $names = [
            trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')),
            (string)($record['company'] ?? ''),
        ];
        foreach ($names as $name) {
            $nameKey = import_order_name_key($name);
            if ($nameKey !== '') {
                $byName[$nameKey][(string)$id] = true;
            }
        }
    }

    return ['records' => $records, 'by_phone' => $byPhone, 'by_name' => $byName];
}

function import_order_resolve_customer(array $orderRow, array $customerIndex, array $overrides = []): array
{
    $code = import_order_normalize($orderRow['code'] ?? '');
    $overrideKey = $code !== '' ? 'code:' . $code : 'row:' . (string)($orderRow['row_no'] ?? '');
    if (isset($overrides[$overrideKey])) {
        $override = $overrides[$overrideKey];
        $customerId = (string)$override['customer_id'];
        if (isset($customerIndex['records'][$customerId])) {
            return [
                'ok' => true,
                'customer_id' => $customerId,
                'match' => 'override',
                'reason' => $override['rule'] ?? '',
            ];
        }
        return [
            'ok' => false,
            'customer_id' => null,
            'match' => 'override',
            'reason' => 'override_customer_missing',
            'candidates' => 0,
            'override_customer_id' => $customerId,
        ];
    }

    $orderPhone = import_order_normalize_phone($orderRow['phone'] ?? '');
    $orderNameKey = import_order_name_key($orderRow['customer_name'] ?? '');

    if ($orderPhone !== '' && isset($customerIndex['by_phone'][$orderPhone])) {
        $ids = array_keys($customerIndex['by_phone'][$orderPhone]);
        if (count($ids) > 1 && $orderNameKey !== '') {
            $idsByName = array_keys($customerIndex['by_name'][$orderNameKey] ?? []);
            $ids = array_values(array_intersect($ids, $idsByName));
        }
        if (count($ids) === 1) {
            return ['ok' => true, 'customer_id' => $ids[0], 'match' => 'phone', 'reason' => ''];
        }
        return ['ok' => false, 'customer_id' => null, 'match' => 'phone', 'reason' => 'ambiguous_phone', 'candidates' => count($ids)];
    }

    if ($orderNameKey !== '' && isset($customerIndex['by_name'][$orderNameKey])) {
        $ids = array_keys($customerIndex['by_name'][$orderNameKey]);
        if (count($ids) === 1) {
            return ['ok' => true, 'customer_id' => $ids[0], 'match' => 'name', 'reason' => ''];
        }
        return ['ok' => false, 'customer_id' => null, 'match' => 'name', 'reason' => 'ambiguous_name', 'candidates' => count($ids)];
    }

    return ['ok' => false, 'customer_id' => null, 'match' => 'none', 'reason' => 'not_found', 'candidates' => 0];
}

function import_order_customers_from_csv(string $path, ?string $extraPath = null): array
{
    [, $customerRows] = import_customer_read_csv($path);
    if ($extraPath !== null && $extraPath !== '') {
        [, $extraRows] = import_customer_read_csv($extraPath);
        $customerRows = array_merge($customerRows, $extraRows);
    }
    $mapped = [];
    foreach ($customerRows as $i => $row) {
        $customer = import_customer_map_row($row);
        $customer['id'] = $i + 1;
        $mapped[] = $customer;
    }
    return $mapped;
}

function import_order_customers_from_db(PDO $pdo): array
{
    return $pdo->query('SELECT id, first_name, last_name, company, phone, email FROM customers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function import_order_read_customer_map(?string $path): array
{
    if ($path === null || $path === '') {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Customer override map is not readable: {$path}");
    }

    $probe = fopen($path, 'rb');
    if ($probe === false) {
        throw new RuntimeException("Cannot open customer override map: {$path}");
    }
    $firstLine = fgets($probe) ?: '';
    fclose($probe);

    $delimiter = import_order_detect_delimiter($firstLine);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open customer override map: {$path}");
    }

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException('Customer override map is empty.');
    }

    $aliases = [
        'order_code' => 'order_code',
        'code' => 'order_code',
        'kod' => 'order_code',
        'row_no' => 'row_no',
        'radek' => 'row_no',
        'customer_id' => 'customer_id',
        'zakaznik_id' => 'customer_id',
        'customer_label' => 'customer_label',
        'rule' => 'rule',
        'reason' => 'rule',
        'duvod' => 'rule',
    ];
    $headerMap = [];
    foreach ($headers as $index => $header) {
        $key = import_order_header_key((string)$header);
        if (isset($aliases[$key])) {
            $headerMap[$index] = $aliases[$key];
        }
    }
    if (!in_array('customer_id', $headerMap, true)) {
        fclose($handle);
        throw new RuntimeException('Customer override map requires customer_id column.');
    }
    if (!in_array('order_code', $headerMap, true) && !in_array('row_no', $headerMap, true)) {
        fclose($handle);
        throw new RuntimeException('Customer override map requires order_code or row_no column.');
    }

    $overrides = [];
    $lineNo = 1;
    while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNo++;
        if ($csvRow === [null] || $csvRow === false) {
            continue;
        }
        $row = ['order_code' => '', 'row_no' => '', 'customer_id' => '', 'customer_label' => '', 'rule' => ''];
        foreach ($headerMap as $index => $field) {
            $row[$field] = import_order_normalize($csvRow[$index] ?? '');
        }
        if ($row['customer_id'] === '') {
            throw new RuntimeException("Customer override map line {$lineNo} has empty customer_id.");
        }
        if (!ctype_digit($row['customer_id']) || (int)$row['customer_id'] < 1) {
            throw new RuntimeException("Customer override map line {$lineNo} has invalid customer_id: {$row['customer_id']}");
        }
        $key = $row['order_code'] !== '' ? 'code:' . $row['order_code'] : 'row:' . $row['row_no'];
        if ($key === 'row:') {
            throw new RuntimeException("Customer override map line {$lineNo} has empty order_code and row_no.");
        }
        if (isset($overrides[$key])) {
            throw new RuntimeException("Duplicate customer override for {$key}.");
        }
        $overrides[$key] = [
            'customer_id' => (int)$row['customer_id'],
            'customer_label' => $row['customer_label'],
            'rule' => $row['rule'],
        ];
    }
    fclose($handle);

    return $overrides;
}

function import_order_validate(array $orderRows, array $customers, array $overrides = []): array
{
    $customerIndex = import_order_build_customer_index($customers);
    $resolved = [];
    $errors = [];
    $matchCounts = ['phone' => 0, 'name' => 0, 'override' => 0];
    $statusCounts = [];
    $deviceTypeCounts = [];
    $brandCounts = [];
    $sourceStatusMap = [];

    foreach ($orderRows as $row) {
        $resolution = import_order_resolve_customer($row, $customerIndex, $overrides);
        $mapped = import_order_map_row($row, $resolution['ok'] ? (int)$resolution['customer_id'] : null);
        $statusCounts[$mapped['status']] = ($statusCounts[$mapped['status']] ?? 0) + 1;
        $deviceTypeCounts[$mapped['device_type']] = ($deviceTypeCounts[$mapped['device_type']] ?? 0) + 1;
        $brandCounts[$mapped['device_brand'] ?? ''] = ($brandCounts[$mapped['device_brand'] ?? ''] ?? 0) + 1;
        $sourceKey = ($row['source_status'] ?? '') . ' => ' . $mapped['status'];
        $sourceStatusMap[$sourceKey] = ($sourceStatusMap[$sourceKey] ?? 0) + 1;

        if ($resolution['ok']) {
            $matchCounts[$resolution['match']]++;
            $resolved[] = ['row' => $row, 'order' => $mapped, 'resolution' => $resolution];
        } else {
            $errors[] = [
                'row_no' => $row['row_no'] ?? null,
                'code' => $row['code'] ?? '',
                'customer_name' => $row['customer_name'] ?? '',
                'phone' => $row['phone'] ?? '',
                'reason' => $resolution['reason'],
                'match' => $resolution['match'],
                'candidates' => $resolution['candidates'] ?? 0,
            ];
        }
    }

    arsort($statusCounts);
    arsort($deviceTypeCounts);
    arsort($brandCounts);
    arsort($sourceStatusMap);

    return [
        'ok' => count($errors) === 0,
        'resolved' => $resolved,
        'errors' => $errors,
        'match_counts' => $matchCounts,
        'status_counts' => $statusCounts,
        'device_type_counts' => $deviceTypeCounts,
        'brand_counts' => $brandCounts,
        'source_status_map' => $sourceStatusMap,
        'customer_index' => $customerIndex,
    ];
}

function import_order_insert(PDO $pdo, array $resolved): int
{
    $sql = 'INSERT INTO orders ('
        . 'customer_id, order_code, device_type, order_type, device_model, device_brand, serial_number, serial_number_2, appearance, pin_code, priority, '
        . 'problem_description, technician_notes, estimated_cost, final_cost, extra_expenses, status, technician_id, work_duration_seconds, branch_id'
        . ') VALUES ('
        . ':customer_id, :order_code, :device_type, :order_type, :device_model, :device_brand, :serial_number, :serial_number_2, :appearance, :pin_code, :priority, '
        . ':problem_description, :technician_notes, :estimated_cost, :final_cost, :extra_expenses, :status, :technician_id, :work_duration_seconds, :branch_id'
        . ')';
    // kódy poboček -> id (viz PRAVIDLA PŘEVODU u import_order_status_map)
    $branchIds = [];
    try {
        foreach ($pdo->query('SELECT id, code FROM branches')->fetchAll() as $b) {
            $branchIds[(string)$b['code']] = (int)$b['id'];
        }
    } catch (Throwable $e) { /* instalace bez poboček */ }
    $stmt = $pdo->prepare($sql);
    $count = 0;
    foreach ($resolved as $item) {
        $order = $item['order'];
        $branchCode = $order['_branch_code'] ?? null;
        unset($order['_branch_code']);
        $order['branch_id'] = ($branchCode !== null && isset($branchIds[$branchCode])) ? $branchIds[$branchCode] : null;
        $stmt->execute($order);
        $count++;
    }
    return $count;
}

function import_order_print_report(array $validation, int $rowCount, int $customerCount, string $delimiter): void
{
    printf("CSV delimiter: %s\n", $delimiter === "\t" ? 'TAB' : $delimiter);
    printf("Rows parsed: %d\n", $rowCount);
    printf("Customers available for matching: %d\n", $customerCount);
    printf("Matched by phone: %d\n", $validation['match_counts']['phone']);
    printf("Matched by name: %d\n", $validation['match_counts']['name']);
    printf("Matched by override: %d\n", $validation['match_counts']['override']);
    printf("Validation errors: %d\n", count($validation['errors']));

    echo "Status mapping counts:\n";
    foreach ($validation['source_status_map'] as $mapping => $count) {
        echo "  {$count}x {$mapping}\n";
    }

    echo "Device type counts:\n";
    foreach ($validation['device_type_counts'] as $type => $count) {
        echo "  {$count}x {$type}\n";
    }

    echo "Top brand counts:\n";
    foreach (array_slice($validation['brand_counts'], 0, 12, true) as $brand => $count) {
        echo "  {$count}x {$brand}\n";
    }

    echo "Samples:\n";
    foreach (array_slice($validation['resolved'], 0, 10) as $i => $item) {
        printf(
            "  #%d row %d %s: %s / %s -> customer #%s (%s), status %s, device %s %s\n",
            $i + 1,
            $item['row']['row_no'],
            $item['row']['code'],
            $item['row']['customer_name'],
            $item['row']['phone'],
            (string)$item['resolution']['customer_id'],
            $item['resolution']['match'],
            $item['order']['status'],
            $item['order']['device_type'],
            $item['order']['device_model']
        );
    }

    if ($validation['errors']) {
        echo "Validation error samples (max 50):\n";
        foreach (array_slice($validation['errors'], 0, 50) as $error) {
            printf(
                "  row %s code=%s name=%s phone=%s reason=%s match=%s candidates=%d\n",
                (string)$error['row_no'],
                $error['code'],
                $error['customer_name'],
                $error['phone'],
                $error['reason'],
                $error['match'],
                $error['candidates']
            );
        }
    }
}

function import_order_self_test(): void
{
    $statusExpected = [
        'Přijato' => 'Přijato',
        'Připraveno k převzetí' => 'Připraveno k převzetí',
        'Nevyzvednuto' => 'Nevyzvednuto',
        'Vydáno - ČR' => 'Vydáno - ČR',
        'V opravě zák. desky' => 'V opravě zák. desky',
        'Čeká na díl' => 'Čeká na díl',
        'černá růže' => 'Přijato',
    ];
    foreach ($statusExpected as $source => $expected) {
        $actual = import_order_status_map($source);
        if ($actual !== $expected) {
            throw new RuntimeException("Status self-test failed: {$source} expected {$expected}, got {$actual}");
        }
    }

    $branchExpected = [
        'černá růže' => 'prikope',
        'cerna ruze' => 'prikope',
        'Přijato' => null,
        'Vydáno' => null,
    ];
    foreach ($branchExpected as $source => $expected) {
        $actual = import_order_branch_code($source);
        if ($actual !== $expected) {
            throw new RuntimeException("Branch self-test failed: {$source} expected " . var_export($expected, true) . ", got " . var_export($actual, true));
        }
    }

    $deviceExpected = [
        'iPhone 16 Pro Max' => ['Phone', 'Apple'],
        'MacBook Air 13″ M2 (A2681)' => ['Notebook', 'Apple'],
        'iPad 8 (2020)' => ['Tablet', 'Apple'],
        'Sumsung S 10 E' => ['Phone', 'Samsung'],
        'ASUS ROG NoteBook' => ['Notebook', 'Asus'],
    ];
    foreach ($deviceExpected as $device => [$type, $brand]) {
        if (import_order_infer_device_type($device) !== $type || import_order_infer_brand($device) !== $brand) {
            throw new RuntimeException("Device self-test failed: {$device}");
        }
    }

    echo "Self-test OK\n";
}

function import_order_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/import_orders_csv.php --self-test\n";
    echo "  php scripts/import_orders_csv.php --file=/path/zakazky.csv --customers-file=/path/zakaznici.csv [--extra-customers-file=/path/extra_customers.csv] [--customer-map=/path/order_customer_overrides.csv] [--dry-run]\n";
    echo "  php scripts/import_orders_csv.php --file=/path/zakazky.csv [--customer-map=/path/order_customer_overrides.csv] --apply\n";
    echo "\n";
    echo "Dry-run validates against --customers-file. Apply validates against current DB customers and inserts orders.\n";
}

function import_order_main(array $argv): int
{
    $options = getopt('', ['file:', 'customers-file:', 'extra-customers-file:', 'customer-map:', 'dry-run', 'apply', 'self-test', 'help']);

    if (isset($options['help'])) {
        import_order_usage();
        return 0;
    }

    if (isset($options['self-test'])) {
        import_order_self_test();
        return 0;
    }

    $path = $options['file'] ?? null;
    if (!$path) {
        import_order_usage();
        return 1;
    }

    $apply = isset($options['apply']);
    [$delimiter, $orderRows] = import_order_read_csv((string)$path);

    if ($apply) {
        require __DIR__ . '/../includes/config.php';
        /** @var PDO $pdo */
        $customers = import_order_customers_from_db($pdo);
    } else {
        $customersFile = $options['customers-file'] ?? null;
        if (!$customersFile) {
            throw new RuntimeException('Dry-run requires --customers-file so order->customer links can be validated without DB changes.');
        }
        $customers = import_order_customers_from_csv((string)$customersFile, $options['extra-customers-file'] ?? null);
    }

    $overrides = import_order_read_customer_map($options['customer-map'] ?? null);
    $validation = import_order_validate($orderRows, $customers, $overrides);
    import_order_print_report($validation, count($orderRows), count($customers), $delimiter);

    if (!$validation['ok']) {
        echo "Validation failed; no DB changes were made.\n";
        return 2;
    }

    if (!$apply) {
        echo "Dry-run OK; no DB changes. Pass --apply after importing customers to insert orders.\n";
        return 0;
    }

    $pdo->beginTransaction();
    try {
        $inserted = import_order_insert($pdo, $validation['resolved']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    printf("Inserted orders: %d\n", $inserted);
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(import_order_main($argv));
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
