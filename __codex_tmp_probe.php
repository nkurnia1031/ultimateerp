<?php
$e = parse_ini_file('.env');
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $e['DB_HOST'], $e['DB_PORT'], $e['DB_DATABASE']), $e['DB_USERNAME'], $e['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$businessId = 2;
$queries = [
    'all_payments' => "SELECT COUNT(*) FROM transaction_payments WHERE business_id = {$businessId}",
    'root_payments' => "SELECT COUNT(*) FROM transaction_payments WHERE business_id = {$businessId} AND parent_id IS NULL",
    'direct_sell_like' => "SELECT COUNT(*) FROM transaction_payments tp JOIN transactions t ON tp.transaction_id = t.id WHERE tp.business_id = {$businessId} AND tp.parent_id IS NULL AND t.business_id = {$businessId} AND t.type IN ('sell','opening_balance')",
    'parent_only' => "SELECT COUNT(*) FROM transaction_payments WHERE business_id = {$businessId} AND transaction_id IS NULL AND parent_id IS NULL",
    'child_sell_like' => "SELECT COUNT(*) FROM transaction_payments tp JOIN transactions t ON tp.transaction_id = t.id WHERE tp.business_id = {$businessId} AND tp.parent_id IS NOT NULL AND t.business_id = {$businessId} AND t.type IN ('sell','opening_balance')",
];
foreach ($queries as $label => $sql) {
    $start = microtime(true);
    $count = $pdo->query($sql)->fetchColumn();
    $ms = round((microtime(true) - $start) * 1000, 2);
    echo $label . '=' . $count . ' ms=' . $ms . PHP_EOL;
}
$stmt = $pdo->query('SHOW INDEX FROM transactions');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo 'transactions_index=' . $row['Key_name'] . '|' . $row['Seq_in_index'] . '|' . $row['Column_name'] . PHP_EOL;
}
