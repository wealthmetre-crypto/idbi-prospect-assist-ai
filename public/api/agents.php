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

// ── DETERMINISTIC SEED: same prospect = same mock results, always ──
mt_srand($prospectId * 7919);

function saveRun(PDO $pdo, int $pid, string $agent, array $output, array $codes, string $status = 'completed'): void {
    $stmt = $pdo->prepare("INSERT INTO agent_runs (prospect_id, agent_name, status, output_json, reason_codes_json, started_at, completed_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$pid, $agent, $status, json_encode($output), json_encode($codes)]);
}

// ── KYC AGENT (mock, DigiLocker/Karza integration-ready) ──
if ($action === 'run_kyc') {
    $pan = strtoupper(trim((string)($_GET['pan'] ?? $body['pan'] ?? '')));
    $aadhaar = preg_replace('/\D/', '', (string)($_GET['aadhaar'] ?? $body['aadhaar'] ?? ''));

    $panValid = (bool)preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan);
    $aadhaarValid = strlen($aadhaar) === 12;

    // Deterministic name-match score from prospect_id
    $nameMatch = $panValid ? (88 + (($prospectId * 7) % 11)) : 0;   // 88-98
    $kycVerified = $panValid && $aadhaarValid && $nameMatch >= 85;

    $output = [
        'kyc_verified' => $kycVerified,
        'pan_masked' => $panValid ? substr($pan, 0, 3) . 'XXXX' . substr($pan, -2) : null,
        'aadhaar_masked' => $aadhaarValid ? 'XXXX-XXXX-' . substr($aadhaar, -4) : null,
        'pan_format_valid' => $panValid,
        'aadhaar_format_valid' => $aadhaarValid,
        'name_match_score' => $nameMatch,
        'risk_flags' => $kycVerified ? [] : ['KYC verification incomplete — manual check required'],
        'integration_ready' => ['DigiLocker', 'Karza', 'Signzy'],
        'remarks' => $kycVerified
            ? 'KYC verified (simulated). Name match ' . $nameMatch . '%. Production: DigiLocker/Karza API plug-in ready.'
            : 'KYC formats invalid or match below threshold.'
    ];
    saveRun($pdo, $prospectId, 'kyc', $output,
        [$kycVerified ? 'KYC_Verified' : 'KYC_Failed', 'NameMatch_' . $nameMatch],
        $kycVerified ? 'completed' : 'warning');
    jsonOk(['agent' => 'kyc', 'output' => $output]);
}

// ── BUREAU AGENT (deterministic mock) ──
if ($action === 'run_bureau') {
    $declared = (int)($prospect['cibil_declared'] ?? 700);
    // Deterministic variance: derived from prospect_id, stable across runs
    $variance = (($prospectId * 13) % 21) - 10;   // -10 to +10
    $cibilScore = max(650, min(820, $declared + $variance));
    $overdue = ($prospectId % 4 === 0) ? (($prospectId * 37) % 8000) : 0;
    $recentEnquiries = ($prospectId * 3) % 5;
    $activeLoans = ($prospect['existing_emi_declared'] > 0) ? 1 + ($prospectId % 2) : 0;
    $riskCategory = $cibilScore < 700 ? 'High' : ($cibilScore < 750 ? 'Medium' : 'Low');

    $output = [
        'cibil_score' => $cibilScore,
        'active_loans_count' => $activeLoans,
        'total_active_emi' => (int)($prospect['existing_emi_declared'] ?? 0),
        'overdue_amount' => $overdue,
        'recent_enquiries_6m' => $recentEnquiries,
        'dpd_flags' => $overdue > 0 ? ['Minor_Overdue_' . $overdue] : [],
        'bureau_risk_category' => $riskCategory,
        'reason_codes' => ['CIBIL_' . $cibilScore, $overdue === 0 ? 'No_Overdue' : 'Has_Overdue', 'Enquiries_' . $recentEnquiries]
    ];
    saveRun($pdo, $prospectId, 'bureau', $output, ['CIBIL_' . $cibilScore, $riskCategory]);
    jsonOk(['agent' => 'bureau', 'output' => $output, 'cibil_score' => $cibilScore]);
}

// ── INCOME AGENT (deterministic mock) ──
if ($action === 'run_income') {
    $declared = (float)($prospect['declared_monthly_income'] ?? 50000);
    // Verification rate derived from profile, not random
    $baseRate = ($prospect['employment_type'] === 'salaried') ? 0.92 : 0.78;
    $rateAdj = (($prospectId * 11) % 9) / 100;    // 0 to 0.08 deterministic
    $verificationRate = $baseRate - $rateAdj;
    $verified = (int)($declared * $verificationRate);
    $mismatch = (1 - $verificationRate) > 0.15;
    $confidence = (int)(65 + $verificationRate * 30);

    $output = [
        'declared_monthly_income' => (int)$declared,
        'verified_monthly_income' => $verified,
        'itr_annual_income' => $verified * 12,
        'income_confidence_score' => $confidence,
        'income_mismatch_flag' => $mismatch,
        'remarks' => $mismatch
            ? 'Declared income higher than transaction-verified income. Using verified amount for FOIR.'
            : 'Income verified against salary credits / ITR pattern.'
    ];
    saveRun($pdo, $prospectId, 'income', $output, ['Verified_' . (int)($verificationRate * 100) . '%', $mismatch ? 'Mismatch' : 'Clean']);
    jsonOk(['agent' => 'income', 'output' => $output, 'verified_income' => $verified]);
}

// ── BUSINESS FINANCIAL AGENT (WC / Business Loan: Nayak method + DSCR + operating cycle) ──
if ($action === 'run_business_financials') {
    $turnover   = (float)($_GET['turnover'] ?? $body['turnover'] ?? 0);          // annual, as per GST/ITR
    $pat        = (float)($_GET['pat'] ?? $body['pat'] ?? 0);
    $dep        = (float)($_GET['depreciation'] ?? $body['depreciation'] ?? 0);
    $interest   = (float)($_GET['interest_paid'] ?? $body['interest_paid'] ?? 0);
    $termObl    = (float)($_GET['term_obligations'] ?? $body['term_obligations'] ?? 0); // annual principal+interest on term loans
    $debtorDays   = (int)($_GET['debtor_days'] ?? $body['debtor_days'] ?? 60);
    $creditorDays = (int)($_GET['creditor_days'] ?? $body['creditor_days'] ?? 30);
    $invDays      = (int)($_GET['inventory_days'] ?? $body['inventory_days'] ?? 45);
    $existingWC   = (float)($_GET['existing_wc'] ?? $body['existing_wc'] ?? 0);

    if ($turnover <= 0) jsonErr('Annual turnover is required for business financial assessment');

    // Nayak Committee method (limits up to Rs.5 Cr): WC requirement = 25% of projected
    // turnover; bank finance = 20% of turnover (5% promoter margin)
    $nayakRequirement = round($turnover * 0.25, 2);
    $bankFinanceCap   = round($turnover * 0.20, 2);
    $netNewEligible   = max(0, $bankFinanceCap - $existingWC);

    // DSCR = (PAT + Depreciation + Interest) / (Interest + Term obligations)
    $denominator = $interest + $termObl;
    $dscr = $denominator > 0 ? round(($pat + $dep + $interest) / $denominator, 2) : 9.99;
    $dscrBand = $dscr >= 1.5 ? 'green' : ($dscr >= 1.25 ? 'amber' : 'red');

    // Operating cycle
    $cycle = $debtorDays + $invDays - $creditorDays;
    $cycleFlag = $cycle > 90 ? 'stretched' : ($cycle > 60 ? 'moderate' : 'comfortable');

    // Cashflow-equivalent monthly income for downstream FOIR/transaction analytics
    $monthlyEquivalent = (int)round(($pat + $dep) / 12);

    $flags = [];
    if ($dscr < 1.25) $flags[] = 'DSCR below 1.25 — repayment capacity insufficient per standard norms';
    if ($cycle > 90) $flags[] = 'Operating cycle ' . $cycle . ' days — stock statement & drawing power scrutiny required';
    if ($existingWC >= $bankFinanceCap) $flags[] = 'Existing WC limits already at/above assessed cap — enhancement not supportable on current turnover';

    $output = [
        'annual_turnover' => $turnover,
        'pat' => $pat, 'depreciation' => $dep, 'interest_paid' => $interest,
        'annual_term_obligations' => $termObl,
        'nayak_wc_requirement_25pct' => $nayakRequirement,
        'bank_finance_eligible_20pct' => $bankFinanceCap,
        'existing_wc_limits' => $existingWC,
        'net_new_limit_eligible' => $netNewEligible,
        'dscr' => $dscr, 'dscr_band' => $dscrBand,
        'debtor_days' => $debtorDays, 'creditor_days' => $creditorDays, 'inventory_days' => $invDays,
        'operating_cycle_days' => $cycle, 'cycle_flag' => $cycleFlag,
        'monthly_income_equivalent' => $monthlyEquivalent,
        'risk_flags' => $flags,
        'assessment_method' => 'Nayak Committee (turnover method) + DSCR + operating cycle',
        'integration_ready' => ['Karza ITR Pull', 'Setu ITR API', 'GSTN Returns API'],
        'remarks' => 'Turnover/PAT simulated from RM inputs. Production: auto-pulled from ITR/GST via integration-ready APIs; assessment formulas unchanged.'
    ];
    saveRun($pdo, $prospectId, 'business_financials', $output,
        ['DSCR_' . $dscr, 'Nayak_Eligible_' . (int)$netNewEligible, 'Cycle_' . $cycle . 'd', strtoupper($dscrBand)],
        $dscrBand === 'red' ? 'warning' : 'completed');
    jsonOk(['agent' => 'business_financials', 'output' => $output, 'monthly_income_equivalent' => $monthlyEquivalent, 'dscr' => $dscr]);
}

// ── TRANSACTION AGENT (deterministic FOIR/EMI analytics) ──
if ($action === 'run_transaction') {
    $verifiedIncome = (int)($_GET['verified_income'] ?? (float)$prospect['declared_monthly_income'] * 0.85);
    if ($verifiedIncome <= 0) $verifiedIncome = 50000;
    $existingEmi = (int)($prospect['existing_emi_declared'] ?? 0);
    $livingExpenses = (int)($verifiedIncome * 0.30);
    $monthlySurplus = max(0, $verifiedIncome - $existingEmi - $livingExpenses);
    $foir = (int)round(($existingEmi / $verifiedIncome) * 100);
    $safeEmiCapacity = (int)($monthlySurplus * 0.60);
    $bounces = ($prospectId % 5 === 0) ? 1 : 0;   // deterministic
    $volatility = ($prospect['employment_type'] === 'salaried') ? 'low' : 'medium';
    $repayScore = max(0, min(100, (int)(100 - $foir - $bounces * 10)));

    $output = [
        'estimated_actual_income' => $verifiedIncome,
        'average_monthly_credit' => $verifiedIncome,
        'existing_emi_detected' => $existingEmi,
        'total_monthly_obligations' => $existingEmi + $livingExpenses,
        'monthly_surplus' => $monthlySurplus,
        'safe_emi_capacity' => $safeEmiCapacity,
        'foir' => $foir,
        'bounce_count_6m' => $bounces,
        'cashflow_volatility' => $volatility,
        'repayment_capacity_score' => $repayScore,
        'risk_flags' => $bounces > 0 ? ['EMI bounce detected in last 6 months'] : []
    ];
    saveRun($pdo, $prospectId, 'transaction', $output, ['FOIR_' . $foir . '%', 'SafeEMI_' . $safeEmiCapacity, $bounces ? 'Bounces_' . $bounces : 'No_Bounces']);
    jsonOk(['agent' => 'transaction', 'output' => $output, 'foir' => $foir, 'safe_emi' => $safeEmiCapacity]);
}

// ── POLICY FIT AGENT v2 (ALL programs + alternate routing: "Reject mat karo, route karo") ──
if ($action === 'run_policy_fit') {
    $loanType = $prospect['loan_type'];
    $subProduct = (string)($prospect['sub_product'] ?? '');
    $employment = (string)$prospect['employment_type'];
    $cibil = (int)($_GET['cibil_score'] ?? $prospect['cibil_declared'] ?? 700);
    $verifiedIncome = (int)($_GET['verified_income'] ?? (float)$prospect['declared_monthly_income'] * 0.85);
    $foir = (int)($_GET['foir'] ?? 40);
    $safeEmi = (int)($_GET['safe_emi'] ?? 20000);
    $requestedAmount = (float)($prospect['loan_amount_required'] ?? 2000000);

    // Loop through ALL programs of this loan type
    $programsStmt = $pdo->prepare("SELECT * FROM loan_product_rules WHERE loan_type=? ORDER BY id ASC");
    $programsStmt->execute([$loanType]);
    $programs = $programsStmt->fetchAll();

    // Sub-product-aware ordering: programs matching the chosen sub-product are
    // evaluated FIRST (e.g. 'Cash Credit (CC)' prospect must match the CC program,
    // not the first LAP program of the same loan_type)
    if ($subProduct !== '') {
        $kw = [];
        foreach (preg_split('/[^A-Za-z]+/', $subProduct) as $w) {
            if (strlen($w) >= 2 && !in_array(strtolower($w), ['loan','the','and'])) $kw[] = strtolower($w);
        }
        usort($programs, function($a, $b) use ($kw) {
            $score = function($p) use ($kw) {
                $name = strtolower($p['program_name']);
                $s = 0;
                foreach ($kw as $w) if (strpos($name, $w) !== false) $s++;
                return $s;
            };
            return $score($b) <=> $score($a);
        });
    }

    $bestProgram = null;
    $failReasons = [];
    $checkedPrograms = [];

    foreach ($programs as $prog) {
        $allowed = json_decode($prog['employment_type_allowed_json'] ?? '[]', true) ?: [];
        $fails = [];
        if (!in_array($employment, $allowed)) $fails[] = 'employment_not_allowed';
        if ($cibil < (int)$prog['min_cibil']) $fails[] = 'cibil_below_' . $prog['min_cibil'];
        if ($verifiedIncome < (float)$prog['min_income']) $fails[] = 'income_below_' . (int)$prog['min_income'];
        if ($foir > (float)$prog['max_foir']) $fails[] = 'foir_above_' . (int)$prog['max_foir'];

        $checkedPrograms[] = ['program' => $prog['program_name'], 'result' => $fails ? 'fail' : 'pass', 'fails' => $fails];
        if (!$fails && !$bestProgram) {
            $bestProgram = $prog;
        }
        if ($fails) $failReasons = array_merge($failReasons, $fails);
    }

    // Max eligible from safe EMI capacity (rough 15yr @ 9.5% => factor ~95 per 1000 EMI)
    $emiBasedEligible = (int)($safeEmi * 95000 / 1000);
    $programCap = $bestProgram ? (float)$bestProgram['max_loan_amount'] : 0;
    $maxEligible = $bestProgram ? min($programCap, max(500000, $emiBasedEligible)) : 0;

    // Determine status + alternate routing
    $reworkSuggestions = [];
    $alternateProduct = null;

    if ($bestProgram && $requestedAmount <= $maxEligible) {
        $eligibilityStatus = 'proceed_to_login';
    } elseif ($bestProgram) {
        $eligibilityStatus = 'proceed_with_rework';
        $reworkSuggestions[] = 'Reduce loan amount to Rs.' . number_format($maxEligible) . ' or add co-applicant income';
    } else {
        // No program passed — try routing instead of rejecting
        $eligibilityStatus = 'not_eligible';
        if ($loanType === 'personal_loan' && $prospect['property_ownership'] === 'yes') {
            $alternateProduct = 'LAP (Loan Against Property)';
            $eligibilityStatus = 'offer_alternate_product';
            $reworkSuggestions[] = 'PL FOIR too high, but customer owns property — LAP offers higher eligibility at lower ROI';
        } elseif ($loanType === 'home_loan' && in_array($employment, ['self_employed', 'business_owner'])) {
            $alternateProduct = 'Home Loan - Banking Surrogate Program';
            $eligibilityStatus = 'offer_alternate_product';
            $reworkSuggestions[] = 'ITR-based programs failed, but banking credits strong — route to Banking Surrogate';
        } elseif ($foir > 55) {
            $eligibilityStatus = 'nurture';
            $reworkSuggestions[] = 'FOIR too high across all programs. Close 1-2 small EMIs, revisit in 60-90 days';
        }
    }

    $output = [
        'eligibility_status' => $eligibilityStatus,
        'best_product' => $loanType,
        'best_program' => $bestProgram['program_name'] ?? null,
        'alternate_product' => $alternateProduct,
        'programs_checked' => $checkedPrograms,
        'max_eligible_loan_amount' => (int)$maxEligible,
        'requested_amount' => (int)$requestedAmount,
        'foir' => $foir,
        'cibil_score' => $cibil,
        'rework_suggestions' => $reworkSuggestions,
        'reason_codes' => $bestProgram ? ['Program_Match'] : array_slice(array_unique($failReasons), 0, 4)
    ];
    saveRun($pdo, $prospectId, 'policy_fit', $output, [$eligibilityStatus, 'Checked_' . count($programs) . '_programs']);
    jsonOk(['agent' => 'policy_fit', 'output' => $output]);
}

jsonErr('Unknown action: ' . $action);
