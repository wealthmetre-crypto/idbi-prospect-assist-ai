<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$pdo = getDB();
$id = (int)($_GET['prospect_id'] ?? 0);
if (!$id) die('prospect_id required');

$ps = $pdo->prepare("SELECT * FROM prospects WHERE id=?"); $ps->execute([$id]);
$p = $ps->fetch(); if (!$p) die('Prospect not found');

$as = $pdo->prepare("SELECT ar.* FROM agent_runs ar INNER JOIN
    (SELECT agent_name, MAX(id) mid FROM agent_runs WHERE prospect_id=? GROUP BY agent_name) l ON ar.id=l.mid");
$as->execute([$id]);
$A = [];
foreach ($as->fetchAll() as $r) $A[$r['agent_name']] = json_decode($r['output_json'], true) ?? [];

$rs = $pdo->prepare("SELECT * FROM recommendations WHERE prospect_id=?"); $rs->execute([$id]);
$R = $rs->fetch() ?: [];

$ds = $pdo->prepare("SELECT * FROM property_documents WHERE prospect_id=?"); $ds->execute([$id]);
$docs = $ds->fetchAll();

function inr($v): string { return $v === null || $v === '' ? '—' : 'Rs. ' . number_format((float)$v); }
function e($v): string { return htmlspecialchars((string)($v ?? '—')); }
$kyc = $A['kyc'] ?? []; $bu = $A['bureau'] ?? []; $inc = $A['income'] ?? []; $biz = $A['business_financials'] ?? null;
$txn = $A['transaction'] ?? []; $leg = $A['legal'] ?? []; $val = $A['valuation'] ?? []; $pf = $A['policy_fit'] ?? []; $sim = $A['similar_case'] ?? [];
$recLabel = strtoupper(str_replace('_', ' ', (string)($R['final_recommendation'] ?? 'PENDING')));
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>CAM — <?=e($p['prospect_code'])?></title>
<style>
body{font-family:Georgia,'Times New Roman',serif;color:#111;max-width:900px;margin:0 auto;padding:30px;font-size:13.5px;line-height:1.55}
.hd{border-bottom:3px double #1e3a8a;padding-bottom:12px;margin-bottom:18px}
.hd h1{font-size:20px;margin:0;color:#1e3a8a}.hd .sub{font-size:12px;color:#555}
h2{font-size:14px;background:#1e3a8a;color:#fff;padding:6px 10px;margin:22px 0 8px}
table{width:100%;border-collapse:collapse;margin-bottom:6px}
td,th{border:1px solid #bbb;padding:5px 8px;text-align:left;vertical-align:top}
th{background:#eef2ff;font-size:12px;width:32%}
.rec{border:2px solid #1e3a8a;padding:14px;margin:16px 0;background:#f8fafc}
.rec b.big{font-size:16px;color:#1e3a8a}
.flag{color:#b45309}.ok{color:#166534}.bad{color:#991b1b}
.sig{display:flex;justify-content:space-between;margin-top:40px}
.sig div{width:30%;border-top:1px solid #333;padding-top:6px;font-size:12px;text-align:center}
.disc{font-size:11px;color:#555;border:1px solid #ccc;padding:8px;margin-top:14px;background:#fafafa}
.noprint{position:fixed;top:14px;right:14px}
.noprint button{padding:9px 18px;background:#1e3a8a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px}
@media print{.noprint{display:none}body{padding:0}}
</style></head><body>
<div class="noprint"><button onclick="window.print()">🖨 Print / Save as PDF</button></div>

<div class="hd">
  <h1>CREDIT APPRAISAL MEMORANDUM (CAM)</h1>
  <div class="sub">Prospect Assist AI — Agentic Pre-Screening Report · IDBI Innovate 2026 · Generated: <?=date('d-M-Y H:i')?> IST</div>
  <div class="sub">Reference: <?=e($p['prospect_code'])?> · Sourced by: <?=e($p['assigned_rm'])?> (<?=e($p['lead_source'])?>)</div>
</div>

<h2>1. APPLICANT PROFILE</h2>
<table>
<tr><th>Applicant Name</th><td><?=e($p['full_name'])?></td><th>Mobile</th><td><?=e($p['mobile'])?></td></tr>
<tr><th>City</th><td><?=e($p['city'])?></td><th>Age</th><td><?=e($p['age'])?></td></tr>
<tr><th>Employment Type</th><td><?=e($p['employment_type'])?></td><th>Employer / Business</th><td><?=e($p['employer_or_business'])?></td></tr>
<tr><th>Consent Status</th><td class="ok"><?=e($p['consent_status'])?></td><th>Application Date</th><td><?=e($p['created_at'])?></td></tr>
</table>

<h2>2. FACILITY REQUESTED</h2>
<table>
<tr><th>Product</th><td><?=e(str_replace('_',' ',$p['loan_type']))?></td><th>Sub-Product / Program</th><td><?=e($p['sub_product'])?></td></tr>
<tr><th>Product Path</th><td colspan="3"><?=e($p['product_path'])?></td></tr>
<tr><th>Amount Requested</th><td><?=inr($p['loan_amount_required'])?></td><th>Timeline</th><td><?=e($p['loan_timeline'])?></td></tr>
</table>

<h2>3. KYC VERIFICATION</h2>
<table>
<tr><th>Status</th><td class="<?=!empty($kyc['kyc_verified'])?'ok':'bad'?>"><?=!empty($kyc['kyc_verified'])?'VERIFIED':'PENDING / FAILED'?></td>
<th>Name Match Score</th><td><?=e($kyc['name_match_score'] ?? '—')?>%</td></tr>
<tr><th>PAN</th><td><?=e($kyc['pan_masked'] ?? '—')?></td><th>Aadhaar</th><td><?=e($kyc['aadhaar_masked'] ?? '—')?></td></tr>
<tr><th>Source</th><td colspan="3">Simulated verification — DigiLocker / Karza / Signzy API integration-ready (sandbox phase: live pull)</td></tr>
</table>

<h2>4. BUREAU REPORT (CIBIL)</h2>
<table>
<tr><th>CIBIL Score</th><td><b><?=e($bu['cibil_score'] ?? '—')?></b> (<?=e($bu['bureau_risk_category'] ?? '—')?> risk)</td>
<th>Active Loans</th><td><?=e($bu['active_loans_count'] ?? '—')?></td></tr>
<tr><th>Running EMI</th><td><?=inr($bu['total_active_emi'] ?? null)?></td><th>Enquiries (6m)</th><td><?=e($bu['recent_enquiries_6m'] ?? '—')?></td></tr>
<tr><th>Overdue</th><td class="<?=($bu['overdue_amount'] ?? 0)>0?'flag':'ok'?>"><?=inr($bu['overdue_amount'] ?? 0)?></td>
<th>DPD Flags</th><td><?=e(implode(', ', $bu['dpd_flags'] ?? []) ?: 'None')?></td></tr>
</table>

<?php if ($biz): ?>
<h2>5. BUSINESS FINANCIALS &amp; RATIO ANALYSIS</h2>
<table>
<tr><th>Annual Turnover</th><td><?=inr($biz['annual_turnover'])?></td><th>PAT</th><td><?=inr($biz['pat'])?></td></tr>
<tr><th>Depreciation</th><td><?=inr($biz['depreciation'])?></td><th>Interest Paid (p.a.)</th><td><?=inr($biz['interest_paid'])?></td></tr>
<tr><th>DSCR</th><td class="<?=$biz['dscr_band']==='green'?'ok':($biz['dscr_band']==='amber'?'flag':'bad')?>"><b><?=e($biz['dscr'])?></b> (<?=strtoupper(e($biz['dscr_band']))?>; norm ≥ 1.25)</td>
<th>Operating Cycle</th><td><?=e($biz['operating_cycle_days'])?> days (<?=e($biz['cycle_flag'])?>)</td></tr>
<tr><th>Debtor / Creditor / Inventory Days</th><td><?=e($biz['debtor_days'])?> / <?=e($biz['creditor_days'])?> / <?=e($biz['inventory_days'])?></td>
<th>Existing WC Limits</th><td><?=inr($biz['existing_wc_limits'])?></td></tr>
<tr><th>Nayak WC Requirement (25%)</th><td><?=inr($biz['nayak_wc_requirement_25pct'])?></td>
<th>Bank Finance Cap (20%)</th><td><?=inr($biz['bank_finance_eligible_20pct'])?></td></tr>
<tr><th>Net New Limit Eligible</th><td colspan="3"><b><?=inr($biz['net_new_limit_eligible'])?></b> — <?=e($biz['assessment_method'])?></td></tr>
</table>
<?php else: ?>
<h2>5. INCOME ASSESSMENT</h2>
<table>
<tr><th>Declared Monthly Income</th><td><?=inr($inc['declared_monthly_income'] ?? $p['declared_monthly_income'])?></td>
<th>Verified Monthly Income</th><td><b><?=inr($inc['verified_monthly_income'] ?? null)?></b></td></tr>
<tr><th>Confidence Score</th><td><?=e($inc['income_confidence_score'] ?? '—')?>/100</td>
<th>Mismatch Flag</th><td class="<?=!empty($inc['income_mismatch_flag'])?'flag':'ok'?>"><?=!empty($inc['income_mismatch_flag'])?'YES — verified income used for FOIR':'No'?></td></tr>
</table>
<?php endif; ?>

<h2>6. BANKING CONDUCT &amp; REPAYMENT CAPACITY</h2>
<table>
<tr><th>Est. Actual Income (monthly)</th><td><?=inr($txn['estimated_actual_income'] ?? null)?></td>
<th>Monthly Surplus</th><td><?=inr($txn['monthly_surplus'] ?? null)?></td></tr>
<tr><th>FOIR</th><td><?=e($txn['foir'] ?? '—')?>%</td><th>Safe EMI Capacity</th><td><b><?=inr($txn['safe_emi_capacity'] ?? null)?></b></td></tr>
<tr><th>Bounces (6m)</th><td class="<?=($txn['bounce_count_6m'] ?? 0)>0?'flag':'ok'?>"><?=e($txn['bounce_count_6m'] ?? 0)?></td>
<th>Cashflow Volatility</th><td><?=e($txn['cashflow_volatility'] ?? '—')?></td></tr>
<tr><th>Source</th><td colspan="3">Analytics on simulated statement — Account Aggregator (Perfios / Setu) integration-ready</td></tr>
</table>

<?php if (!empty($leg['applicable'])): ?>
<h2>7. SECURITY — LEGAL SCRUTINY &amp; VALUATION</h2>
<table>
<tr><th>Legal Readiness Score</th><td class="<?=($leg['legal_readiness_score']??0)>=80?'ok':'flag'?>"><b><?=e($leg['legal_readiness_score'] ?? '—')?>/100</b></td>
<th>Title Chain</th><td><?=e($leg['title_chain_status'] ?? '—')?></td></tr>
<tr><th>Documents on Record</th><td colspan="3">
<?php foreach ($docs as $d): ?><?=e(str_replace('_',' ',$d['document_type']))?> — <?=$d['status']==='received'?($d['file_path']?'<span class="ok">uploaded</span>':'<span class="ok">received (physical)</span>'):'<span class="bad">missing</span>'?><br><?php endforeach; ?>
</td></tr>
<tr><th>Legal Remarks</th><td colspan="3"><?=e($leg['recommendation'] ?? '—')?> <i>(Preliminary scrutiny — not a final legal title report)</i></td></tr>
<?php if (!empty($val['applicable'])): ?>
<tr><th>RM Estimated Value</th><td><?=inr($val['rm_entered_value'] ?? null)?> (<?=e($val['area'] ?? '')?>, <?=e($val['sqft'] ?? '')?> sqft)</td>
<th>Valuation Signal</th><td class="<?=($val['signal']??'')==='green'?'ok':(($val['signal']??'')==='out_of_scope'?'bad':'flag')?>"><b><?=strtoupper(str_replace('_',' ',e($val['signal'] ?? '—')))?></b></td></tr>
<tr><th>Bank 12-mo Funding Benchmark</th><td><?=inr($val['area_avg_per_sqft_12m'] ?? null)?>/sqft (<?=e($val['comparables_found'] ?? 0)?> comparables)</td>
<th>Deviation</th><td><?=e($val['deviation_pct'] ?? '—')?>%</td></tr>
<tr><th>Valuation Remarks</th><td colspan="3"><?=e($val['recommendation'] ?? '—')?></td></tr>
<?php endif; ?>
</table>
<?php endif; ?>

<h2>8. POLICY FIT — PROGRAM ASSESSMENT</h2>
<table>
<tr><th>Best Program Matched</th><td><b><?=e($pf['best_program'] ?? '—')?></b></td>
<th>Alternate Product</th><td><?=e($pf['alternate_product'] ?? 'None')?></td></tr>
<tr><th>Max Eligible Amount</th><td><b><?=inr($R['max_eligible_loan_amount'] ?? $pf['max_eligible_loan_amount'] ?? null)?></b></td>
<th>Requested</th><td><?=inr($p['loan_amount_required'])?></td></tr>
<tr><th>Programs Evaluated</th><td colspan="3">
<?php foreach (($pf['programs_checked'] ?? []) as $pc): ?><?=e($pc['program'])?> — <?=$pc['result']==='pass'?'<span class="ok">PASS</span>':'<span class="bad">FAIL</span> ('.e(implode(', ',$pc['fails'])).')'?><br><?php endforeach; ?>
</td></tr>
</table>

<h2>9. SIMILAR CASE REFERENCES (INSTITUTIONAL MEMORY)</h2>
<table><tr><td><?=e($sim['insight'] ?? 'No similar case analysis on record.')?></td></tr></table>

<div class="rec">
<b class="big">10. RECOMMENDATION: <?=e($recLabel)?></b><br><br>
<b>Prospect Quality Score:</b> <?=e($R['prospect_quality_score'] ?? '—')?>/100 &nbsp;·&nbsp;
<b>Conversion Probability:</b> <?=e($R['conversion_probability'] ?? '—')?> &nbsp;·&nbsp;
<b>Underwriting Risk:</b> <?=e($R['underwriting_risk'] ?? '—')?><br><br>
<b>AI Explanation:</b> <?=e($R['rag_explanation'] ?? '—')?><br><br>
<b>Conditions / Rework Actions:</b><br>
<?php $rw = json_decode((string)($R['rework_actions_json'] ?? '[]'), true) ?: []; ?>
<?php if ($rw): foreach ($rw as $i => $r): ?><?=($i+1)?>. <?=e($r)?><br><?php endforeach; else: ?>None — clear to proceed to login.<?php endif; ?>
<br><b>RM Next Action:</b> <?=e($R['rm_next_action'] ?? '—')?>
</div>

<div class="sig"><div>Relationship Manager</div><div>Credit Analyst</div><div>Sanctioning Authority</div></div>

<div class="disc"><b>Disclaimer:</b> This CAM is an AI-assisted pre-screening document generated by Prospect Assist AI. All eligibility outcomes are produced by a deterministic rule engine; the AI layer provides explanations only. KYC, bureau, income and transaction data are simulated in this prototype (production integrations: DigiLocker/Karza, CIBIL, Perfios/Setu AA, GSTN). This document does not constitute loan sanction. Final credit decision rests solely with IDBI Bank's underwriting team and credit committee.</div>
</body></html>
