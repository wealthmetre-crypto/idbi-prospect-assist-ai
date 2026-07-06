<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$body = getJsonBody();
$action = $_GET['action'] ?? ($body['action'] ?? '');
$pdo = getDB();

// ── CREATE PROSPECT ──
if ($action === 'create') {
    $mobile = preg_replace('/\D/', '', (string)($body['mobile'] ?? ''));
    if (strlen($mobile) < 10) jsonErr('Valid 10-digit mobile required');
    if (empty($body['full_name'])) jsonErr('full_name is required');
    if (empty($body['loan_type'])) jsonErr('loan_type is required');

    $maxNum = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(prospect_code, 4) AS UNSIGNED)), 0) FROM prospects")->fetchColumn();
    $code = 'PA-' . str_pad((string)($maxNum + 1), 4, '0', STR_PAD_LEFT);

    $docs = $body['documents_available'] ?? [];
    $consentGiven = !empty($body['consent']);

    $stmt = $pdo->prepare("INSERT INTO prospects
        (prospect_code, full_name, mobile, email, city, loan_type, loan_amount_required, loan_purpose,
         employment_type, declared_monthly_income, existing_emi_declared, cibil_declared, age,
         employer_or_business, property_ownership, property_value, property_type, down_payment_available,
         loan_timeline, documents_available_json, consent_status, lead_source, assigned_rm)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $stmt->execute([
        $code,
        sanitize((string)$body['full_name'], 150),
        $mobile,
        sanitize((string)($body['email'] ?? ''), 150) ?: null,
        sanitize((string)($body['city'] ?? ''), 100) ?: null,
        $body['loan_type'],
        $body['loan_amount_required'] ?? null,
        sanitize((string)($body['loan_purpose'] ?? ''), 255) ?: null,
        $body['employment_type'] ?? null,
        $body['declared_monthly_income'] ?? null,
        $body['existing_emi_declared'] ?? 0,
        $body['cibil_declared'] ?? null,
        $body['age'] ?? null,
        sanitize((string)($body['employer_or_business'] ?? ''), 150) ?: null,
        $body['property_ownership'] ?? 'no',
        $body['property_value'] ?? null,
        sanitize((string)($body['property_type'] ?? ''), 50) ?: null,
        $body['down_payment_available'] ?? null,
        $body['loan_timeline'] ?? null,
        json_encode($docs),
        $consentGiven ? 'granted' : 'missing',
        sanitize((string)($body['lead_source'] ?? 'manual'), 50),
        sanitize((string)($body['assigned_rm'] ?? 'Owner'), 100)
    ]);

    $id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO agent_runs (prospect_id, agent_name, status, output_json, started_at, completed_at)
        VALUES (?, 'consent', ?, ?, NOW(), NOW())")
        ->execute([$id, $consentGiven ? 'completed' : 'failed',
                   json_encode(['consent_status' => $consentGiven ? 'granted' : 'missing', 'timestamp' => date('c')])]);

    jsonOk(['prospect_id' => $id, 'prospect_code' => $code, 'message' => 'Prospect created']);
}

// ── UPDATE PROSPECT (wizard step 4: financial details) ──
if ($action === 'update') {
    $id = (int)($body['prospect_id'] ?? 0);
    if (!$id) jsonErr('prospect_id required');
    $stmt = $pdo->prepare("UPDATE prospects SET
        loan_amount_required = COALESCE(?, loan_amount_required),
        declared_monthly_income = COALESCE(?, declared_monthly_income),
        existing_emi_declared = COALESCE(?, existing_emi_declared),
        employment_type = COALESCE(?, employment_type),
        age = COALESCE(?, age),
        property_ownership = COALESCE(?, property_ownership),
        status = 'assessing'
        WHERE id = ?");
    $stmt->execute([
        $body['loan_amount_required'] ?? null,
        $body['declared_monthly_income'] ?? null,
        $body['existing_emi_declared'] ?? null,
        $body['employment_type'] ?? null,
        $body['age'] ?? null,
        $body['property_ownership'] ?? null,
        $id
    ]);
    jsonOk(['prospect_id' => $id, 'message' => 'Prospect updated']);
}

// ── LIST PROSPECTS ──
if ($action === 'list') {
    $search = trim($_GET['search'] ?? '');
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE p.full_name LIKE ? OR p.mobile LIKE ? OR p.prospect_code LIKE ?";
        $l = "%$search%";
        $params = [$l, $l, $l];
    }

    $stmt = $pdo->prepare("SELECT p.id, p.prospect_code, p.full_name, p.mobile, p.loan_type,
        p.loan_amount_required, p.city, p.status, p.created_at,
        r.final_recommendation, r.prospect_quality_score, r.conversion_probability
        FROM prospects p
        LEFT JOIN recommendations r ON r.prospect_id = p.id
        $where
        ORDER BY p.created_at DESC LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM prospects")->fetchColumn(),
        'proceed_to_login' => (int)$pdo->query("SELECT COUNT(*) FROM recommendations WHERE final_recommendation='proceed_to_login'")->fetchColumn(),
        'proceed_with_rework' => (int)$pdo->query("SELECT COUNT(*) FROM recommendations WHERE final_recommendation='proceed_with_rework'")->fetchColumn(),
        'alternate_product' => (int)$pdo->query("SELECT COUNT(*) FROM recommendations WHERE final_recommendation='offer_alternate_product'")->fetchColumn(),
        'nurture' => (int)$pdo->query("SELECT COUNT(*) FROM recommendations WHERE final_recommendation='nurture'")->fetchColumn(),
        'do_not_proceed' => (int)$pdo->query("SELECT COUNT(*) FROM recommendations WHERE final_recommendation='do_not_proceed'")->fetchColumn(),
    ];

    jsonOk(['prospects' => $rows, 'stats' => $stats]);
}

// ── GET SINGLE PROSPECT (full detail) ──
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonErr('id required');

    $stmt = $pdo->prepare("SELECT * FROM prospects WHERE id=?");
    $stmt->execute([$id]);
    $prospect = $stmt->fetch();
    if (!$prospect) jsonErr('Prospect not found', 404);

    $runs = $pdo->prepare("SELECT * FROM agent_runs WHERE prospect_id=? ORDER BY id ASC");
    $runs->execute([$id]);

    $rec = $pdo->prepare("SELECT * FROM recommendations WHERE prospect_id=?");
    $rec->execute([$id]);

    $txns = $pdo->prepare("SELECT * FROM transactions WHERE prospect_id=? ORDER BY txn_date ASC");
    $txns->execute([$id]);

    $docs = $pdo->prepare("SELECT * FROM property_documents WHERE prospect_id=?");
    $docs->execute([$id]);

    jsonOk([
        'prospect' => $prospect,
        'agent_runs' => $runs->fetchAll(),
        'recommendation' => $rec->fetch() ?: null,
        'transactions' => $txns->fetchAll(),
        'documents' => $docs->fetchAll()
    ]);
}

jsonErr('Unknown action: ' . $action);
