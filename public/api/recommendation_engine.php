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

// ── Latest run per agent (not stale duplicates) ──
$agentsStmt = $pdo->prepare("SELECT ar.* FROM agent_runs ar
    INNER JOIN (SELECT agent_name, MAX(id) AS max_id FROM agent_runs WHERE prospect_id=? GROUP BY agent_name) latest
    ON ar.id = latest.max_id");
$agentsStmt->execute([$prospectId]);
$agentMap = [];
foreach ($agentsStmt->fetchAll() as $a) {
    $agentMap[$a['agent_name']] = json_decode($a['output_json'], true) ?? [];
}

$cibil       = $agentMap['bureau']['cibil_score'] ?? 700;
$bureauRisk  = $agentMap['bureau']['bureau_risk_category'] ?? 'Medium';
$verifiedInc = $agentMap['income']['verified_monthly_income'] ?? (int)((float)$prospect['declared_monthly_income'] * 0.85);
$mismatch    = $agentMap['income']['income_mismatch_flag'] ?? false;
$foir        = $agentMap['transaction']['foir'] ?? 40;
$safeEmi     = $agentMap['transaction']['safe_emi_capacity'] ?? 20000;
$bounces     = $agentMap['transaction']['bounce_count_6m'] ?? 0;
$repScore    = $agentMap['transaction']['repayment_capacity_score'] ?? 70;
$pfStatus    = $agentMap['policy_fit']['eligibility_status'] ?? 'not_eligible';
$bestProgram = $agentMap['policy_fit']['best_program'] ?? null;
$altProduct  = $agentMap['policy_fit']['alternate_product'] ?? null;
$maxEligible = $agentMap['policy_fit']['max_eligible_loan_amount'] ?? 0;
$reworks     = $agentMap['policy_fit']['rework_suggestions'] ?? [];
$simInsight  = $agentMap['similar_case']['insight'] ?? null;
$legalApp    = $agentMap['legal']['applicable'] ?? false;
$legalScore  = $agentMap['legal']['legal_readiness_score'] ?? null;
$legalChain  = $agentMap['legal']['title_chain_status'] ?? null;
$valSignal   = $agentMap['valuation']['signal'] ?? null;
$valDev      = $agentMap['valuation']['deviation_pct'] ?? null;
$biz         = $agentMap['business_financials'] ?? null;
$requested   = (float)$prospect['loan_amount_required'];

// ── RULE ENGINE DECIDES (LLM never decides) ──
$finalRec = $pfStatus;
if (!in_array($finalRec, ['proceed_to_login','proceed_with_rework','offer_alternate_product','nurture'])) {
    $finalRec = 'nurture';
}
// Hard risk gates override everything: CIBIL floor + repayment capacity floor
if ($cibil < 660 || $repScore < 30) {
    $finalRec = 'do_not_proceed';
}

$qualityScore = 40;
$qualityScore += ($cibil >= 750 ? 20 : ($cibil >= 700 ? 12 : 0));
$qualityScore += ($foir <= 40 ? 20 : ($foir <= 50 ? 10 : 0));
$qualityScore += ($repScore >= 80 ? 10 : 5);
$qualityScore += ($bounces === 0 ? 5 : 0);
$qualityScore += ($finalRec === 'proceed_to_login' ? 5 : 0);
$qualityScore = min(100, $qualityScore);

// ── Legal & Valuation gates (secured products) ──
if ($legalApp && $legalScore !== null && $legalScore < 80 && $finalRec === 'proceed_to_login') {
    $finalRec = 'proceed_with_rework';
    $reworks[] = 'Complete legal documentation before login (readiness ' . $legalScore . '/100, title chain: ' . $legalChain . ')';
}
if (in_array($valSignal, ['warning', 'no_comparables']) && $finalRec === 'proceed_to_login') {
    $finalRec = 'proceed_with_rework';
    $reworks[] = 'External valuation team report required (' . ($valSignal === 'no_comparables' ? 'no funding history in area' : 'deviation ' . $valDev . '% from area benchmark') . ')';
}
if ($valSignal === 'out_of_scope') {
    if ($finalRec !== 'do_not_proceed') $finalRec = 'proceed_with_rework';
    $reworks[] = 'VALUATION OUT OF SCOPE (' . $valDev . '% deviation) — external valuation AND credit team recommendation mandatory';
    $qualityScore = min($qualityScore, 60);
}

// ── Business/WC gates: DSCR + Nayak limit override ──
if ($biz) {
    $dscrV = (float)($biz['dscr'] ?? 0);
    $nayakEligible = (float)($biz['net_new_limit_eligible'] ?? 0);
    if ($nayakEligible > 0) {
        $maxEligible = $maxEligible > 0 ? min($maxEligible, $nayakEligible) : $nayakEligible;
    }
    if ($dscrV < 1.0) {
        $finalRec = 'do_not_proceed';
        $reworks[] = 'DSCR ' . $dscrV . ' below 1.0 — cash accruals do not cover obligations. Not bankable on current financials.';
    } elseif ($dscrV < 1.25) {
        if ($finalRec === 'proceed_to_login') $finalRec = 'proceed_with_rework';
        $reworks[] = 'DSCR ' . $dscrV . ' below 1.25 norm — reduce term obligations, infuse margin, or add co-borrower cash accruals';
    }
    if (($biz['operating_cycle_days'] ?? 0) > 90 && $finalRec === 'proceed_to_login') {
        $finalRec = 'proceed_with_rework';
        $reworks[] = 'Operating cycle ' . $biz['operating_cycle_days'] . ' days — obtain stock statement and debtor ageing before limit setup';
    }
}

$convProb = match($finalRec) {
    'proceed_to_login' => '80-90%', 'proceed_with_rework' => '55-70%',
    'offer_alternate_product' => '60-75%', 'nurture' => '30-45%', default => '5-10%' };
$riskLevel = $bureauRisk === 'High' || $foir > 55 ? 'High' : ($foir > 45 || $mismatch ? 'Medium' : 'Low');

// ── LLM EXPLANATION LAYER (explains, never decides) ──
$facts = [
    'customer' => $prospect['full_name'], 'loan_type' => $prospect['loan_type'],
    'requested_amount' => (int)$requested, 'employment' => $prospect['employment_type'],
    'cibil_score' => $cibil, 'verified_monthly_income' => $verifiedInc,
    'income_mismatch' => $mismatch, 'foir_percent' => $foir,
    'safe_emi_capacity' => $safeEmi, 'bounces_6m' => $bounces,
    'best_program' => $bestProgram, 'alternate_product' => $altProduct,
    'max_eligible_amount' => (int)$maxEligible, 'rework_suggestions' => $reworks,
    'similar_case_insight' => $simInsight,
    'legal_readiness_score' => $legalScore, 'title_chain' => $legalChain,
    'valuation_signal' => $valSignal, 'valuation_deviation_pct' => $valDev,
    'business_assessment' => $biz ? ['method' => 'Nayak Committee 20% turnover', 'dscr' => $biz['dscr'], 'operating_cycle_days' => $biz['operating_cycle_days'], 'net_new_limit_eligible' => $biz['net_new_limit_eligible']] : null,
    'FINAL_DECISION_BY_RULE_ENGINE' => $finalRec
];

$systemPrompt = "You are an AI assistant for IDBI retail loan prospect assessment. You must NOT make final credit decisions — the rule engine has already decided; final credit decision belongs to IDBI underwriting team. Your role is to explain the rule engine output in clear banker language. Never fabricate policy rules. Respond ONLY with valid JSON, no markdown, with exactly these keys: {\"explanation\": \"3-4 sentence policy-backed explanation of why this decision\", \"rm_next_action\": \"one specific action for the RM today\", \"rm_script\": \"2-3 line phone script the RM can read to the customer, warm and specific with amounts\", \"customer_message\": \"short WhatsApp message to customer, professional English, with specific amounts, max 2 lines\"}";

$llmRaw = callClaude([
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => "Explain this assessment:\n" . json_encode($facts, JSON_PRETTY_PRINT)]
], 800, 0.3);

$llm = null;
if ($llmRaw) {
    $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($llmRaw));
    $llm = json_decode($clean, true);
}

// Fallback if LLM unavailable
$explanation = $llm['explanation'] ?? "Rule engine assessment: {$bestProgram} — CIBIL {$cibil}, FOIR {$foir}%, verified income Rs." . number_format($verifiedInc) . ". Eligible up to Rs." . number_format($maxEligible) . " against requested Rs." . number_format($requested) . ". Decision: {$finalRec}.";
$rmNextAction = $llm['rm_next_action'] ?? "Call customer today and discuss {$finalRec} path with specific numbers.";
$rmScript = $llm['rm_script'] ?? "Hi {$prospect['full_name']}, based on your profile you qualify under {$bestProgram} up to Rs." . number_format($maxEligible) . ". Let's discuss next steps.";
$customerMsg = $llm['customer_message'] ?? "Hi {$prospect['full_name']}, your loan eligibility of Rs." . number_format($maxEligible) . " has been confirmed. Our RM will call you today with next steps.";

$output = [
    'final_recommendation' => $finalRec,
    'prospect_quality_score' => $qualityScore,
    'conversion_probability' => $convProb,
    'underwriting_risk' => $riskLevel,
    'best_product' => $prospect['loan_type'],
    'best_program' => $bestProgram,
    'alternate_product' => $altProduct,
    'max_eligible_loan_amount' => (int)$maxEligible,
    'safe_emi_capacity' => (int)$safeEmi,
    'foir' => $foir, 'cibil_score' => $cibil, 'verified_income' => (int)$verifiedInc,
    'rework_actions' => $reworks,
    'similar_case_insight' => $simInsight,
    'rag_explanation' => $explanation,
    'rm_next_action' => $rmNextAction,
    'rm_script' => $rmScript,
    'customer_message' => $customerMsg,
    'llm_used' => $llm !== null,
    'disclaimer' => 'This is a pre-screening recommendation. Final sanction is subject to IDBI Bank underwriting.'
];

$pdo->prepare("INSERT INTO recommendations
    (prospect_id, final_recommendation, prospect_quality_score, conversion_probability, underwriting_risk,
     best_product, best_program, alternate_product, max_eligible_loan_amount, safe_emi_capacity, foir,
     rework_actions_json, similar_case_insight, rag_explanation, rm_next_action, rm_script, customer_message)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
     final_recommendation=VALUES(final_recommendation), prospect_quality_score=VALUES(prospect_quality_score),
     conversion_probability=VALUES(conversion_probability), underwriting_risk=VALUES(underwriting_risk),
     best_product=VALUES(best_product), best_program=VALUES(best_program), alternate_product=VALUES(alternate_product),
     max_eligible_loan_amount=VALUES(max_eligible_loan_amount), safe_emi_capacity=VALUES(safe_emi_capacity),
     foir=VALUES(foir), rework_actions_json=VALUES(rework_actions_json),
     similar_case_insight=VALUES(similar_case_insight), rag_explanation=VALUES(rag_explanation),
     rm_next_action=VALUES(rm_next_action), rm_script=VALUES(rm_script), customer_message=VALUES(customer_message)")
    ->execute([$prospectId, $finalRec, $qualityScore, $convProb, $riskLevel,
               $prospect['loan_type'], $bestProgram, $altProduct, $maxEligible, $safeEmi, $foir,
               json_encode($reworks), $simInsight, $explanation, $rmNextAction, $rmScript, $customerMsg]);

$pdo->prepare("INSERT INTO agent_runs (prospect_id, agent_name, status, output_json, reason_codes_json, started_at, completed_at)
    VALUES (?, 'recommendation', 'completed', ?, ?, NOW(), NOW())")
    ->execute([$prospectId, json_encode($output), json_encode([$finalRec, 'Score_' . $qualityScore, $llm ? 'LLM_Explained' : 'Fallback_Explanation'])]);

jsonOk(['recommendation' => $output]);
