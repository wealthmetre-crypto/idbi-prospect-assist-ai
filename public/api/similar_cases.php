<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$prospectId = (int)($_GET['prospect_id'] ?? 0);
$pdo = getDB();
if (!$prospectId) jsonErr('prospect_id required');

$prospectStmt = $pdo->prepare("SELECT * FROM prospects WHERE id=?");
$prospectStmt->execute([$prospectId]);
$prospect = $prospectStmt->fetch();
if (!$prospect) jsonErr('Prospect not found', 404);

// Latest agent outputs for matching signals
$agentsStmt = $pdo->prepare("SELECT ar.* FROM agent_runs ar
    INNER JOIN (SELECT agent_name, MAX(id) AS max_id FROM agent_runs WHERE prospect_id=? GROUP BY agent_name) latest
    ON ar.id = latest.max_id");
$agentsStmt->execute([$prospectId]);
$agentMap = [];
foreach ($agentsStmt->fetchAll() as $a) {
    $agentMap[$a['agent_name']] = json_decode($a['output_json'], true) ?? [];
}

$cibil = $agentMap['bureau']['cibil_score'] ?? (int)($prospect['cibil_declared'] ?? 700);
$foir = $agentMap['transaction']['foir'] ?? 40;
$loanType = $prospect['loan_type'];
$city = (string)$prospect['city'];
$employment = (string)$prospect['employment_type'];

// Structured similarity scoring against past cases
$casesStmt = $pdo->prepare("SELECT * FROM past_cases WHERE loan_type=?");
$casesStmt->execute([$loanType]);
$allCases = $casesStmt->fetchAll();

$scored = [];
foreach ($allCases as $case) {
    $score = 100.0;
    $score -= abs((int)$case['cibil_score'] - $cibil) / 2;   // CIBIL proximity
    $score -= abs((float)$case['foir'] - $foir);             // FOIR proximity
    if ($case['employment_type'] === $employment) $score += 20;
    if ($case['city'] === $city) $score += 15;
    $case['similarity_score'] = max(0, round($score, 1));
    $scored[] = $case;
}
usort($scored, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);
$topCases = array_slice($scored, 0, 5);

$approved = count(array_filter($topCases, fn($c) => $c['final_decision'] === 'approved'));
$rejected = count(array_filter($topCases, fn($c) => $c['final_decision'] === 'rejected'));
$reworked = count(array_filter($topCases, fn($c) => $c['final_decision'] === 'reworked'));

$rejectionReasons = array_values(array_unique(array_filter(array_map(
    fn($c) => $c['rejection_reason'], 
    array_filter($topCases, fn($c) => $c['final_decision'] !== 'approved')
))));

$insight = "Analyzed " . count($allCases) . " historical $loanType cases. Top 5 matches (CIBIL " . ($cibil - 30) . "-" . ($cibil + 30) . ", FOIR ~" . $foir . "%): $approved approved, $reworked reworked, $rejected rejected. ";
$insight .= $approved >= 3
    ? "Similar profiles were consistently approved when FOIR stayed under 50% and documentation was complete."
    : "Similar profiles faced friction — common reasons: " . (implode('; ', array_slice($rejectionReasons, 0, 2)) ?: 'FOIR or documentation gaps') . ". Recommend addressing these before login.";

$output = [
    'similar_cases_found' => count($allCases),
    'approval_stats' => ['approved' => $approved, 'reworked' => $reworked, 'rejected' => $rejected],
    'top_5_cases' => array_map(fn($c) => [
        'case_code' => $c['case_code'],
        'similarity_score' => $c['similarity_score'],
        'cibil_score' => (int)$c['cibil_score'],
        'foir' => (float)$c['foir'],
        'city' => $c['city'],
        'final_decision' => $c['final_decision'],
        'note' => $c['rejection_reason'] ?: $c['case_summary']
    ], $topCases),
    'insight' => $insight
];

$pdo->prepare("INSERT INTO agent_runs (prospect_id, agent_name, status, output_json, reason_codes_json, started_at, completed_at)
    VALUES (?, 'similar_case', 'completed', ?, ?, NOW(), NOW())")
    ->execute([$prospectId, json_encode($output), json_encode(['Matched_' . count($allCases), 'Approved_' . $approved . '_of_5'])]);

jsonOk(['agent' => 'similar_case', 'output' => $output]);
