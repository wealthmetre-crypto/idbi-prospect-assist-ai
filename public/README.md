# Prospect Assist AI — Agentic Loan Readiness Engine

**IDBI Innovate 2026 · Track 02: Prospect Assist AI**

An agentic AI workflow that converts a raw retail or MSME loan enquiry into a verified, policy-matched, RM-ready recommendation within minutes — ending in a bank-format Credit Appraisal Memorandum (CAM).

**Live Demo:** https://idbi.wealthmetre.com

---

## Problem Statement (Track 02)

Bank retail lending relies on traditional metrics, resulting in low conversions and limited insight into customer intent. A data-driven approach is needed to identify eligible prospects with quantifiable repayment capacity using transaction and behavioral insights — targeting conversion above 30% and accurate assessment of borrowers' actual income.

## Core Philosophy

> **"Don't reject — route."**

Every prospect is classified into one of five actionable outcomes instead of a binary approve/reject:

| Outcome | Meaning |
|---|---|
| Proceed to Login | Strong profile, application-ready |
| Proceed with Rework | Eligible with specific fixes (reduce amount, add co-applicant) |
| Offer Alternate Product | Wrong product for profile (e.g., high-FOIR PL → LAP; thin-ITR HL → Banking Surrogate) |
| Nurture | Not ready today, revisit with an action plan |
| Do Not Proceed | Hard risk gates failed (CIBIL floor, DSCR < 1.0) |

## RM On-the-Spot Qualification (7-Step Wizard)

A walk-in lead is qualified in under 3 minutes through a guided RM workflow:

1. **Product Selection** — cascading taxonomy: Secured/Unsecured → Property/Non-Property → Home Loan (8 programs) / LAP (incl. Dropline OD, LRD) / Working Capital (CC, OD, BG, LC) / Car / Machinery / Gold / Personal — loan amount captured at selection
2. **KYC Capture** — PAN + Aadhaar validation with name-match scoring (DigiLocker / Karza / Signzy integration-ready)
3. **Instant CIBIL** — bureau pull with color-coded risk band and automatic co-applicant structuring suggestion for borderline scores
4. **Financial Analysis** — product-aware: retail flows use bank-statement analytics (Perfios / Setu Account Aggregator-ready); WC and business flows use the Business Financial Agent (ITR / GSTN-ready)
5. **Property Legal Analyser** — per-document hybrid intake: upload scan (OCR classification-ready) or mark received physically at branch
6. **Valuation Cross-Check** — RM estimate verified against the bank's own 12-month funding history in the same area (green ≤10% deviation / warning ≤25% / out-of-scope >25%)
7. **Final Agentic Assessment** — full pipeline with live timeline, ending in a printable CAM

## Dual Underwriting Engines

| Engine | Products | Method |
|---|---|---|
| Retail | HL, LAP, PL, Auto | Verified income, FOIR, safe EMI capacity |
| MSME / Business | WC (CC/OD/BG/LC), Business Loan | Nayak Committee turnover method, DSCR bands (≥1.5 green / ≥1.25 amber / <1.25 red), operating cycle analysis |

## Agent Pipeline

## CAM Report

Every assessed prospect generates a printable, RBI-style **Credit Appraisal Memorandum**: applicant profile, KYC, bureau report, financials with ratio analysis (DSCR, operating cycle), banking conduct, security section (legal scrutiny + valuation with market comparables), programs evaluated with pass/fail reasons, similar-case references, recommendation with sanction conditions, and signature blocks.

## Key Design Principle: Rule Engine Decides, LLM Explains

The LLM (Claude) **never makes credit decisions**. All eligibility, FOIR, DSCR and policy outcomes come from a deterministic rule engine evaluated against structured product rules. The LLM's role is strictly explanatory: policy-backed reasoning, RM next-best-action and phone script, and a customer-facing message. If the LLM is unavailable, the system degrades gracefully to templated explanations — decisions are never blocked.

## Explainability & Audit

- Every agent run persisted (`agent_runs`) with input, output, reason codes and timestamps
- Policy Fit records pass/fail for **every** program checked, not just the winner
- Deterministic mock layer (seeded by prospect ID) — consistent, repeatable demos
- Mandatory disclaimer on every output: final sanction rests with IDBI underwriting

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1, MySQL 8.0 |
| AI Explanation | Anthropic Claude (Haiku) |
| Frontend | Vanilla HTML/CSS/JS — zero build step |
| Hosting | Ubuntu VPS, Apache, Let's Encrypt SSL |
| Mock Data Layer | Deterministic simulators designed to swap with IDBI Sandbox APIs (CIBIL, DigiLocker/Karza, Perfios/Setu AA, GSTN) |

## Database Schema (8 tables)

`prospects` · `agent_runs` · `transactions` · `property_documents` · `property_valuations` · `loan_product_rules` · `past_cases` · `recommendations`

See [`database/schema.sql`](database/schema.sql) and seed files (13 product programs, 50 historical cases, 18 area valuation records).

## Setup

```bash
# 1. Create database and load schema + seeds
mysql -u <user> -p<pass> -e "CREATE DATABASE idbi_prospect_assist CHARACTER SET utf8mb4;"
mysql -u <user> -p<pass> idbi_prospect_assist < database/schema.sql
mysql -u <user> -p<pass> idbi_prospect_assist < database/seed_policy_rules.sql
mysql -u <user> -p<pass> idbi_prospect_assist < database/seed_past_cases.sql

# 2. Configure environment (Apache SetEnv or shell)
IDBI_DB_HOST, IDBI_DB_NAME, IDBI_DB_USER, IDBI_DB_PASS, WM_CLAUDE_KEY

# 3. Point Apache DocumentRoot to this folder — no build step required
```

## Roadmap (Refined Prototype — 31 July)

- IDBI Sandbox API integration replacing mock layer (CIBIL, DigiLocker, Perfios/Setu AA, GSTN)
- OCR document classification and data extraction on uploaded property papers
- Sub-product-level policy rule matrices (per-program LTV/FOIR)
- Vector similarity (Qdrant) alongside structured case matching
- Prospect intent scoring from behavioral signals

---

**Disclaimer:** This tool provides pre-screening and RM-assist recommendations only. Final credit decisions remain with IDBI Bank's underwriting team.

*Built for IDBI Innovate 2026 by Team WealthMetre.*
