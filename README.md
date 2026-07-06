# 🎯 Prospect Assist AI — IDBI Innovate 2026 · Track 02

**AI-powered RM Decision-Support System for on-the-spot MSME + Retail loan qualification**

> _"From lead to bankable file in 90 seconds — with a CAM report the credit committee can act on."_

**IDBI Innovate 2026 · Track 02 · Live at [idbi.wealthmetre.com](https://idbi.wealthmetre.com)**

---

## Problem Statement

RM productivity is limited by manual lead qualification, subjective policy interpretation, and slow decision cycles. RMs need a decision-support layer that scores each prospect on the spot, matches them to correct programs, and produces a bank-quality Credit Appraisal Memorandum.

## Solution

A rule-engine + LLM RM workspace that runs a 7-step cascading wizard, executes an 11-agent assessment pipeline in real time, and issues a print-ready RBI-style CAM report — all while ensuring the RM captures the correct product, structure, and policy fit.

---

## The 7-Step Wizard

Cascading product taxonomy mirroring real bank programs:

1. Loan amount + broad category (Secured vs Unsecured)
2. Product subtype — HL (8 programs) / LAP (incl. DOD, LRD) / WC (CC/OD/BG/LC) / Car / Machinery / Gold / PL
3. Instant CIBIL + co-applicant suggestion if CIBIL < 700
4. Property specifics (for HL/LAP)
5. Income assessment (salary / self-employed / mixed)
6. KYC completeness check
7. Consent + trigger assessment pipeline

---

## The 11-Agent Pipeline

Live-animated agent execution:

1. **Consent Agent** — timestamp + purpose + revocation captured
2. **KYC Agent** — DigiLocker/Karza/Signzy-ready, masked PAN/Aadhaar
3. **Bureau Agent** — CIBIL rank, active loans, EMI burden, overdue
4. **Income Agent** (retail) — FOIR-based safe EMI on salary/self-employed
5. **Business Financial Agent** (MSME) — Nayak Committee (20% turnover) + DSCR bands + operating cycle
6. **Transaction Agent** — bank statement pattern analysis
7. **Legal Agent** — hybrid physical/digital doc intake, title chain scoring
8. **Valuation Agent** — RM value cross-checked vs bank's 12-month funding history (18 Jaipur area records)
9. **Policy Fit Agent** — matches profile against loan_product_rules
10. **Similar Case Agent** — retrieves past sanctioned cases with similar structure
11. **Recommendation Agent** — final routing decision

## Dual Underwriting Engines

- **Retail:** FOIR-based safe EMI on documented income
- **MSME:** Nayak Committee 20% turnover + DSCR (>=1.5 comfortable / 1.25 acceptable / <1.25 stretched) + operating cycle

## 5 Recommendation Outcomes

Rule engine picks ONE — not "approve/reject" but structured routing:

1. **Proceed to Login** — clean case, RM files the loan
2. **Proceed with Rework** — small structural fix (co-applicant, tenure, program)
3. **Offer Alternate Product** — route don't reject (e.g., PL rejected → LAP)
4. **Nurture** — 90-180 day nurture path
5. **Do Not Proceed** — hard risk gate hit

---

## 6 Demo Personas

| Prospect | Scenario |
|---|---|
| Amit Verma | Prime HL case — clean approve |
| Rajesh Khandelwal | PL requested → LAP recommended (alternate product) |
| Priya Saini | First-time homebuyer |
| Mohammed Farooq | Surrogate income program (informal income + bank pattern) |
| Sunita Agarwal | Legal pendency case (title issue detected) |
| Vikram Rathore | Declined — hard risk gate (CIBIL < 660) |

---

## Signature Deliverable — CAM Report

Endpoint `/report.php?prospect_id=X` produces a full RBI-style Credit Appraisal Memorandum:

- Georgia serif typography, numbered sections
- Applicant profile + KYC + bureau summary
- Income assessment (retail or MSME format)
- Property details + valuation reconciliation
- Legal report summary
- Policy fit matrix, similar case anchors
- Final recommendation + conditions
- Print-to-PDF ready

---

## Architecture

- **Stack:** PHP 8 · MySQL 8 · Apache · Ubuntu VPS · Let's Encrypt SSL
- **Database:** `idbi_prospect_assist` — 8 tables (prospects, agent_runs, transactions, property_documents, property_valuations, loan_product_rules, past_cases, recommendations)
- **LLM:** Anthropic Claude Haiku 4.5 — explanation only, forbidden from scoring/approving/rejecting
- **Deterministic mock layer** seeded per-prospect (`mt_srand($id * 7919)`) — repeatable, auditable

## Pages / Endpoints

- `index.html` — 7-step wizard + live agent pipeline UI
- `report.php` — CAM report (bank-format, print-ready)
- `api/prospect_intake.php` — entity creation
- `api/agents.php` — 11-agent pipeline runner
- `api/legal_valuation.php` — legal + valuation agents
- `api/similar_cases.php` — past case retrieval
- `api/recommendation_engine.php` — final routing
- `api/upload_document.php` — 25MB PDF/JPG/PNG upload

## Design Discipline

- Rule engine decides all scoring/routing/recommendations
- LLM generates plain-English narrative only
- Every recommendation traces to policy rule or reason code
- Bank-format CAM output for credit committee action
- Integration-ready framing (DigiLocker, Karza, Signzy, bureau)

---

## Team

**Saurabh Acharya** — Founder & CEO, WealthMetre (13+ yrs banking + advisory background)
**Location:** Jaipur, Rajasthan

## Companion Track Submission

Track 03: **MSME Arogya Card** — [github.com/wealthmetre-crypto/msme-arogya-card](https://github.com/wealthmetre-crypto/msme-arogya-card) · Live at [msme.wealthmetre.com](https://msme.wealthmetre.com)

---

**Live demo:** [idbi.wealthmetre.com](https://idbi.wealthmetre.com)
