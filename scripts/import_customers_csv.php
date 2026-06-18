<?php
/**
 * Import customers from AppleFix CSV.
 *
 * CLI only. Dry-run is the default; pass --apply to write to DB.
 * Expected CSV columns: "Jméno a příjmení";"Firma";"Telefonní číslo";"E-mailová adresa"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

function import_customer_normalize(?string $value): string
{
    $value = (string)($value ?? '');
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function import_customer_header_key(string $value): string
{
    $value = mb_strtolower(import_customer_normalize($value), 'UTF-8');
    $value = strtr($value, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
        'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
    ]);
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    return trim($value, '_');
}

function import_customer_detect_delimiter(string $line): string
{
    $candidates = [';' => 0, ',' => 0, "\t" => 0];
    foreach (array_keys($candidates) as $delimiter) {
        $candidates[$delimiter] = count(str_getcsv($line, $delimiter));
    }
    arsort($candidates);
    return (string)array_key_first($candidates);
}

function import_customer_is_same_name(string $a, string $b): bool
{
    $normalize = static function (string $value): string {
        $value = mb_strtolower(import_customer_normalize($value), 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;
    };

    return $normalize($a) !== '' && $normalize($a) === $normalize($b);
}

function import_customer_strip_company_suffix(string $fullName, string $company): string
{
    $name = import_customer_normalize($fullName);
    $company = import_customer_normalize($company);

    if ($company === '') {
        return $name;
    }

    // Company CSV rows often have a personal/contact name followed by "(Company s.r.o.)".
    // Remove complete trailing parenthetical groups only; never split inside them.
    while (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $name, $match)) {
        $candidate = import_customer_normalize($match[1]);
        if ($candidate === '') {
            break;
        }
        $name = $candidate;
    }

    return $name !== '' ? $name : $company;
}

function import_customer_split_top_level_name(string $name): array
{
    $name = import_customer_normalize($name);
    if ($name === '') {
        return ['', ''];
    }

    $length = mb_strlen($name, 'UTF-8');
    $depth = 0;
    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($name, $i, 1, 'UTF-8');
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')' && $depth > 0) {
            $depth--;
            continue;
        }
        if ($depth === 0 && preg_match('/\s/u', $char)) {
            $first = import_customer_normalize(mb_substr($name, 0, $i, 'UTF-8'));
            $last = import_customer_normalize(mb_substr($name, $i + 1, null, 'UTF-8'));
            return [$first, $last !== '' ? $last : '-'];
        }
    }

    return [$name, '-'];
}

function import_customer_truncate(string $value, int $limit): string
{
    return mb_substr(import_customer_normalize($value), 0, $limit, 'UTF-8');
}

function import_customer_map_row(array $row): array
{
    $fullName = import_customer_normalize($row['full_name'] ?? '');
    $firstNameInput = import_customer_normalize($row['first_name'] ?? '');
    $lastNameInput = import_customer_normalize($row['last_name'] ?? '');
    $company = import_customer_normalize($row['company'] ?? '');
    $phone = import_customer_normalize($row['phone'] ?? '');
    $email = import_customer_normalize($row['email'] ?? '');

    $customerType = $company !== '' ? 'company' : 'private';

    if ($firstNameInput !== '' || $lastNameInput !== '') {
        $firstName = $firstNameInput;
        $lastName = $lastNameInput;
    } else {
        $nameForSplit = $customerType === 'company'
            ? import_customer_strip_company_suffix($fullName, $company)
            : $fullName;

        if ($customerType === 'company' && import_customer_is_same_name($nameForSplit, $company)) {
            $firstName = 'Firma';
            $lastName = $company;
        } else {
            [$firstName, $lastName] = import_customer_split_top_level_name($nameForSplit);
        }
    }

    if ($firstName === '') {
        $firstName = $customerType === 'company' ? 'Firma' : '-';
    }
    if ($lastName === '' || $lastName === '-') {
        $lastName = $customerType === 'company' && $company !== '' ? $company : '-';
    }

    return [
        'customer_type' => $customerType,
        'first_name' => import_customer_truncate($firstName, 50),
        'last_name' => import_customer_truncate($lastName, 50),
        'ico' => null,
        'dic' => null,
        'company' => $company !== '' ? import_customer_truncate($company, 100) : null,
        'phone' => $phone !== '' ? import_customer_truncate($phone, 20) : null,
        'email' => $email !== '' ? import_customer_truncate($email, 100) : null,
        'address' => null,
    ];
}

function import_customer_header_map(array $headers): array
{
    $aliases = [
        'jmeno_a_prijmeni' => 'full_name',
        'jmeno_prijmeni' => 'full_name',
        'full_name' => 'full_name',
        'name' => 'full_name',
        'klient' => 'full_name',
        'jmeno' => 'first_name',
        'first_name' => 'first_name',
        'krestni_jmeno' => 'first_name',
        'prijmeni' => 'last_name',
        'last_name' => 'last_name',
        'surname' => 'last_name',
        'firma' => 'company',
        'company' => 'company',
        'spolecnost' => 'company',
        'telefonni_cislo' => 'phone',
        'telefon' => 'phone',
        'phone' => 'phone',
        'emailova_adresa' => 'email',
        'e_mailova_adresa' => 'email',
        'e_mail' => 'email',
        'email' => 'email',
    ];

    $map = [];
    foreach ($headers as $index => $header) {
        $key = import_customer_header_key((string)$header);
        if (isset($aliases[$key])) {
            $map[$index] = $aliases[$key];
        }
    }

    foreach (['company', 'phone', 'email'] as $required) {
        if (!in_array($required, $map, true)) {
            throw new RuntimeException("Missing required CSV column for: {$required}");
        }
    }

    $hasFullName = in_array('full_name', $map, true);
    $hasSplitName = in_array('first_name', $map, true) || in_array('last_name', $map, true);
    if (!$hasFullName && !$hasSplitName) {
        throw new RuntimeException('Missing name columns: provide full_name or first_name/last_name.');
    }

    return $map;
}

function import_customer_read_csv(string $path): array
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

    $delimiter = import_customer_detect_delimiter($firstLine);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open CSV file: {$path}");
    }

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException('CSV file is empty.');
    }

    $headerMap = import_customer_header_map($headers);
    $rows = [];
    $rowNo = 1;
    while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNo++;
        if ($csvRow === [null] || $csvRow === false) {
            continue;
        }

        $row = ['row_no' => $rowNo, 'full_name' => '', 'first_name' => '', 'last_name' => '', 'company' => '', 'phone' => '', 'email' => ''];
        foreach ($headerMap as $index => $field) {
            $row[$field] = import_customer_normalize($csvRow[$index] ?? '');
        }
        if ($row['full_name'] === '' && $row['first_name'] === '' && $row['last_name'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return [$delimiter, $rows];
}

function import_customer_self_test(): void
{
    $samples = [
        [
            'input' => ['full_name' => 'Linda Šwarzová (CBDROOMZ s.r.o.)', 'company' => 'CBDROOMZ s.r.o.', 'phone' => '420 737 041 063', 'email' => 'nora@cannaroomz.com'],
            'expected' => ['customer_type' => 'company', 'first_name' => 'Linda', 'last_name' => 'Šwarzová', 'company' => 'CBDROOMZ s.r.o.'],
        ],
        [
            'input' => ['full_name' => 'Jose Ruano', 'company' => '', 'phone' => '', 'email' => 'jlruano@yahoo.com'],
            'expected' => ['customer_type' => 'private', 'first_name' => 'Jose', 'last_name' => 'Ruano', 'company' => null],
        ],
        [
            'input' => ['full_name' => 'Pavel Kraft (Eurocom Company Group, s.r.o.)', 'company' => 'Eurocom Company Group, s.r.o.', 'phone' => '420 602 214 700', 'email' => 'eurocomcompany@seznam.cz'],
            'expected' => ['customer_type' => 'company', 'first_name' => 'Pavel', 'last_name' => 'Kraft', 'company' => 'Eurocom Company Group, s.r.o.'],
        ],
        [
            'input' => ['full_name' => 'HelpServis Brno s.r.o. (HelpServis Brno s.r.o.)', 'company' => 'HelpServis Brno s.r.o.', 'phone' => '420 776 505 050', 'email' => ''],
            'expected' => ['customer_type' => 'company', 'first_name' => 'Firma', 'last_name' => 'HelpServis Brno s.r.o.', 'company' => 'HelpServis Brno s.r.o.'],
        ],
        [
            'input' => ['full_name' => 'Soukromá Osoba (poznámka s.r.o.)', 'company' => '', 'phone' => '', 'email' => ''],
            'expected' => ['customer_type' => 'private', 'first_name' => 'Soukromá', 'last_name' => 'Osoba (poznámka s.r.o.)', 'company' => null],
        ],
    ];

    foreach ($samples as $sample) {
        $actual = import_customer_map_row($sample['input']);
        foreach ($sample['expected'] as $key => $expectedValue) {
            if ($actual[$key] !== $expectedValue) {
                throw new RuntimeException(sprintf(
                    'Self-test failed for "%s": %s expected "%s", got "%s"',
                    $sample['input']['full_name'],
                    $key,
                    (string)$expectedValue,
                    (string)$actual[$key]
                ));
            }
        }
        printf(
            "OK: %s => [%s] %s | %s | company=%s\n",
            $sample['input']['full_name'],
            $actual['customer_type'],
            $actual['first_name'],
            $actual['last_name'],
            $actual['company'] ?? ''
        );
    }
}

function import_customer_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/import_customers_csv.php --self-test\n";
    echo "  php scripts/import_customers_csv.php --file=/path/zakaznici.csv [--extra-file=/path/extra_customers.csv] [--dry-run|--apply]\n";
}

function import_customer_main(array $argv): int
{
    $options = getopt('', ['file:', 'extra-file:', 'dry-run', 'apply', 'self-test', 'help']);

    if (isset($options['help'])) {
        import_customer_usage();
        return 0;
    }

    if (isset($options['self-test'])) {
        import_customer_self_test();
        return 0;
    }

    $path = $options['file'] ?? null;
    if (!$path) {
        import_customer_usage();
        return 1;
    }

    $apply = isset($options['apply']);
    [$delimiter, $rows] = import_customer_read_csv((string)$path);

    $extraCount = 0;
    if (!empty($options['extra-file'])) {
        [, $extraRows] = import_customer_read_csv((string)$options['extra-file']);
        $extraCount = count($extraRows);
        $rows = array_merge($rows, $extraRows);
    }

    $mapped = array_map('import_customer_map_row', $rows);

    if ($apply) {
        require __DIR__ . '/../includes/config.php';
    }

    $companyCount = count(array_filter($mapped, static fn(array $row): bool => $row['customer_type'] === 'company'));
    printf("CSV delimiter: %s\n", $delimiter === "\t" ? 'TAB' : $delimiter);
    printf("Rows parsed: %d\n", count($mapped));
    if ($extraCount > 0) {
        printf("Extra rows appended: %d\n", $extraCount);
    }
    printf("Company rows: %d\n", $companyCount);
    printf("Private rows: %d\n", count($mapped) - $companyCount);

    foreach (array_slice($mapped, 0, 8) as $index => $row) {
        printf(
            "Sample #%d: [%s] %s | %s | company=%s | phone=%s | email=%s\n",
            $index + 1,
            $row['customer_type'],
            $row['first_name'],
            $row['last_name'],
            $row['company'] ?? '',
            $row['phone'] ?? '',
            $row['email'] ?? ''
        );
    }

    if (!$apply) {
        echo "Dry-run only; no DB changes. Pass --apply to insert.\n";
        return 0;
    }

    /** @var PDO $pdo */
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO customers (customer_type, first_name, last_name, ico, dic, company, phone, email, address) '
            . 'VALUES (:customer_type, :first_name, :last_name, :ico, :dic, :company, :phone, :email, :address)'
        );
        foreach ($mapped as $row) {
            $stmt->execute($row);
        }
        $pdo->commit();
        printf("Inserted customers: %d\n", count($mapped));
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(import_customer_main($argv));
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
