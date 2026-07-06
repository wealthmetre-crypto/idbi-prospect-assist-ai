<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$body = getJsonBody();
$action = $_GET['action'] ?? ($body['action'] ?? '');
$prospectId = (int)($_GET['prospect_id'] ?? $body['prospect_id'] ?? 0);
$pdo = getDB();
if (!$prospectId) jsonErr('prospect_id required');

$prospectStmt = $pdo->prepare("SELECT * FROM prospects WHERE id=?");
$prospectStmt->execute([$prospectId]);
$prospect = $prospectStmt->fetch();
if (!$prospect) jsonErr('Prospect not found', 404);

function saveRun(PDO $pdo, int $pid, string $agent, array $output, array $codes, string $status = 'completed'): void {
    $pdo->prepare("INSERT INTO agent_runs (prospect_id, agent_name, status, output_json, reason_codes_json, started_at, completed_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([$pid, $agent, $status, json_encode($output), json_encode($codes)]);
}

// ══════════════════════════════════════════════════════════════
// LEGAL AGENT — document checklist, title chain, readiness score
// (ported from WealthMetre Documents Module checklist concept)
// ══════════════════════════════════════════════════════════════
if ($action === 'run_legal') {
    // received_docs: comma-separated in GET or array in POST body
    $received = $body['received_docs'] ?? explode(',', (string)($_GET['received_docs'] ?? ''));
    $received = array_values(array_filter(array_map('trim', (array)$received)));

    // Merge documents already UPLOADED (file on record) — upload counts as received
    $upStmt = $pdo->prepare("SELECT document_type FROM property_documents WHERE prospect_id=? AND file_path IS NOT NULL");
    $upStmt->execute([$prospectId]);
    $uploaded = array_column($upStmt->fetchAll(), 'document_type');
    $received = array_values(array_unique(array_merge($received, $uploaded)));

    $checklist = [
        'sale_deed'            => ['label' => 'Sale Deed (Registry)',        'weight' => 25, 'critical' => true],
        'previous_sale_deed'   => ['label' => 'Previous Chain Documents',    'weight' => 20, 'critical' => true],
        'mutation'             => ['label' => 'Mutation (Namantaran)',       'weight' => 15, 'critical' => false],
        'property_tax_receipt' => ['label' => 'Property Tax Receipt',        'weight' => 10, 'critical' => false],
        'approved_map'         => ['label' => 'Approved Map / Site Plan',    'weight' => 15, 'critical' => false],
        'patta'                => ['label' => 'Patta / Allotment Letter',    'weight' => 15, 'critical' => true],
    ];

    // Unsecured products skip legal entirely
    if (in_array($prospect['loan_type'], ['personal_loan', 'auto_loan'])) {
        $output = [
            'applicable' => false,
            'legal_readiness_score' => 100,
            'title_chain_status' => 'not_applicable',
            'recommendation' => 'Unsecured product — legal scrutiny not applicable.',
            'documents_received' => [], 'documents_missing' => [], 'risk_flags' => []
        ];
        saveRun($pdo, $prospectId, 'legal', $output, ['Legal_NA_Unsecured']);
        jsonOk(['agent' => 'legal', 'output' => $output]);
    }

    $score = 0; $missing = []; $got = []; $flags = [];
    foreach ($checklist as $key => $doc) {
        if (in_array($key, $received)) {
            $score += $doc['weight'];
            $got[] = $key;
        } else {
            $missing[] = $key;
            if ($doc['critical']) $flags[] = 'Critical document missing: ' . $doc['label'];
        }
    }

    $chainComplete = in_array('sale_deed', $got) && in_array('previous_sale_deed', $got);
    $titleChain = $chainComplete ? 'complete' : (in_array('sale_deed', $got) ? 'incomplete' : 'missing');

    if ($score >= 80 && $chainComplete)      $recommendation = 'Legal documents sufficient — clear to proceed to login.';
    elseif ($score >= 50)                    $recommendation = 'Proceed with rework — collect missing documents before login: ' . implode(', ', array_map(fn($m) => $checklist[$m]['label'], $missing));
    else                                     $recommendation = 'Hold login — title chain insufficient. Collect critical documents first.';

    // Persist received docs to property_documents (WealthMetre pattern)
    $pdo->prepare("DELETE FROM property_documents WHERE prospect_id=? AND file_path IS NULL")->execute([$prospectId]);
    foreach ($checklist as $key => $doc) {
        if (in_array($key, $uploaded)) continue; // uploaded rows preserved with file_path
        $pdo->prepare("INSERT INTO property_documents (prospect_id, document_type, status) VALUES (?, ?, ?)")
            ->execute([$prospectId, $key, in_array($key, $got) ? 'received' : 'missing']);
    }

    $output = [
        'applicable' => true,
        'legal_readiness_score' => $score,
        'title_chain_status' => $titleChain,
        'documents_received' => $got,
        'documents_uploaded' => $uploaded,
        'documents_missing' => $missing,
        'risk_flags' => $flags,
        'recommendation' => $recommendation,
        'disclaimer' => 'Preliminary legal scrutiny only — not a final legal title report.'
    ];
    saveRun($pdo, $prospectId, 'legal', $output, ['Legal_Score_' . $score, 'Chain_' . $titleChain], $flags ? 'warning' : 'completed');
    jsonOk(['agent' => 'legal', 'output' => $output]);
}

// ══════════════════════════════════════════════════════════════
// VALUATION AGENT — RM value vs bank's historical funding database
// Green ≤10% | Warning 10–25% | Out-of-scope >25% deviation
// ══════════════════════════════════════════════════════════════
if ($action === 'run_valuation') {
    $rmValue = (float)($_GET['rm_value'] ?? $body['rm_value'] ?? 0);
    $area = trim((string)($_GET['area'] ?? $body['area'] ?? ''));
    $sqft = (int)($_GET['sqft'] ?? $body['sqft'] ?? 0);
    $propType = trim((string)($_GET['property_type'] ?? $body['property_type'] ?? $prospect['property_type'] ?? 'residential'));

    if (in_array($prospect['loan_type'], ['personal_loan', 'auto_loan'])) {
        $output = ['applicable' => false, 'signal' => 'not_applicable', 'recommendation' => 'Unsecured product — property valuation not applicable.'];
        saveRun($pdo, $prospectId, 'valuation', $output, ['Valuation_NA_Unsecured']);
        jsonOk(['agent' => 'valuation', 'output' => $output]);
    }
    if (!$rmValue || !$area || !$sqft) jsonErr('rm_value, area and sqft are required for valuation check');

    $rmPerSqft = round($rmValue / $sqft, 2);

    // Bank's historical funding records: same area + type, last 12 months only
    $compStmt = $pdo->prepare("SELECT case_ref, carpet_sqft, valuation_amount, per_sqft_value, valuation_date,
        TIMESTAMPDIFF(MONTH, valuation_date, CURDATE()) AS months_ago
        FROM property_valuations
        WHERE area LIKE ? AND property_type = ? AND valuation_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        ORDER BY valuation_date DESC");
    $compStmt->execute(['%' . $area . '%', $propType]);
    $comparables = $compStmt->fetchAll();

    if (!$comparables) {
        $output = [
            'applicable' => true, 'signal' => 'no_comparables',
            'rm_entered_value' => $rmValue, 'rm_per_sqft' => $rmPerSqft,
            'area' => $area, 'comparables_found' => 0,
            'recommendation' => 'No funding history in this area within 12 months — external valuation team report required before login.'
        ];
        saveRun($pdo, $prospectId, 'valuation', $output, ['No_Comparables_' . $area], 'warning');
        jsonOk(['agent' => 'valuation', 'output' => $output]);
    }

    $avgPerSqft = round(array_sum(array_column($comparables, 'per_sqft_value')) / count($comparables), 2);
    $deviationPct = round((($rmPerSqft - $avgPerSqft) / $avgPerSqft) * 100, 1);
    $absDev = abs($deviationPct);

    if ($absDev <= 10) {
        $signal = 'green';
        $recommendation = 'RM valuation is within ±10% of bank\'s 12-month funding history for ' . $area . '. Proceed — internal valuation benchmark satisfied.';
        $status = 'completed';
    } elseif ($absDev <= 25) {
        $signal = 'warning';
        $recommendation = 'RM valuation deviates ' . $deviationPct . '% from area benchmark (Rs.' . number_format($avgPerSqft) . '/sqft). External valuation team report required before proceeding.';
        $status = 'warning';
    } else {
        $signal = 'out_of_scope';
        $recommendation = 'OUT OF SCOPE: RM valuation deviates ' . $deviationPct . '% from bank\'s funding history. Proceed only with external valuation report AND credit team recommendation.';
        $status = 'warning';
    }

    $output = [
        'applicable' => true,
        'signal' => $signal,
        'rm_entered_value' => $rmValue,
        'rm_per_sqft' => $rmPerSqft,
        'area' => $area, 'property_type' => $propType, 'sqft' => $sqft,
        'comparables_found' => count($comparables),
        'area_avg_per_sqft_12m' => $avgPerSqft,
        'deviation_pct' => $deviationPct,
        'comparables' => array_map(fn($c) => [
            'case_ref' => $c['case_ref'], 'sqft' => (int)$c['carpet_sqft'],
            'value' => (float)$c['valuation_amount'], 'per_sqft' => (float)$c['per_sqft_value'],
            'months_ago' => (int)$c['months_ago']
        ], array_slice($comparables, 0, 5)),
        'recommendation' => $recommendation
    ];
    saveRun($pdo, $prospectId, 'valuation', $output, ['Valuation_' . strtoupper($signal), 'Deviation_' . $deviationPct . '%'], $status);
    jsonOk(['agent' => 'valuation', 'output' => $output]);
}

jsonErr('Unknown action: ' . $action);
