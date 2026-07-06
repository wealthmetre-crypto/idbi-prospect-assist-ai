CREATE TABLE prospects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_code VARCHAR(20) UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    email VARCHAR(150),
    city VARCHAR(100),
    loan_type ENUM('personal_loan','home_loan','mortgage_loan','auto_loan') NOT NULL,
    loan_amount_required DECIMAL(14,2),
    loan_purpose VARCHAR(255),
    employment_type ENUM('salaried','self_employed','business_owner','professional'),
    declared_monthly_income DECIMAL(12,2),
    existing_emi_declared DECIMAL(12,2) DEFAULT 0,
    cibil_declared INT,
    age INT,
    employer_or_business VARCHAR(150),
    property_ownership ENUM('yes','no') DEFAULT 'no',
    property_value DECIMAL(14,2),
    property_type VARCHAR(50),
    down_payment_available DECIMAL(14,2),
    loan_timeline ENUM('immediate','30_days','3_months','exploring'),
    documents_available_json JSON,
    consent_status ENUM('granted','missing') DEFAULT 'missing',
    lead_source VARCHAR(50) DEFAULT 'manual',
    assigned_rm VARCHAR(100) DEFAULT 'Owner',
    status ENUM('new','assessing','completed') DEFAULT 'new',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE agent_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_id INT NOT NULL,
    agent_name ENUM('consent','bureau','income','transaction','legal','policy_fit','similar_case','recommendation') NOT NULL,
    status ENUM('pending','running','completed','warning','failed') DEFAULT 'pending',
    input_json JSON,
    output_json JSON,
    reason_codes_json JSON,
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prospect (prospect_id),
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
);

CREATE TABLE transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_id INT NOT NULL,
    txn_date DATE,
    narration VARCHAR(255),
    debit DECIMAL(12,2) DEFAULT 0,
    credit DECIMAL(12,2) DEFAULT 0,
    balance DECIMAL(12,2),
    detected_type ENUM('salary','business_credit','emi','nach','bounce','rent','cash_withdrawal','upi','insurance','investment','utility','credit_card_payment','other') DEFAULT 'other',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prospect (prospect_id),
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
);

CREATE TABLE property_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_id INT NOT NULL,
    document_type VARCHAR(100),
    file_path VARCHAR(255),
    status ENUM('received','missing') DEFAULT 'received',
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
);

CREATE TABLE loan_product_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_type VARCHAR(50),
    program_name VARCHAR(150),
    employment_type_allowed_json JSON,
    min_cibil INT,
    min_income DECIMAL(12,2),
    max_foir DECIMAL(5,2),
    max_ltv DECIMAL(5,2),
    min_age INT,
    max_age INT,
    max_loan_amount DECIMAL(14,2),
    property_type_allowed_json JSON,
    required_documents_json JSON,
    deviation_possible TINYINT(1) DEFAULT 0,
    rule_notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE past_cases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_code VARCHAR(20),
    loan_type VARCHAR(50),
    city VARCHAR(100),
    employment_type VARCHAR(50),
    cibil_score INT,
    verified_income DECIMAL(12,2),
    foir DECIMAL(5,2),
    ltv DECIMAL(5,2),
    property_type VARCHAR(50),
    legal_status VARCHAR(50),
    requested_loan_amount DECIMAL(14,2),
    approved_loan_amount DECIMAL(14,2),
    final_decision ENUM('approved','rejected','reworked','alternate_product'),
    rejection_reason VARCHAR(255),
    case_summary TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prospect_id INT NOT NULL UNIQUE,
    final_recommendation ENUM('proceed_to_login','proceed_with_rework','offer_alternate_product','nurture','do_not_proceed'),
    prospect_quality_score INT,
    conversion_probability VARCHAR(10),
    underwriting_risk ENUM('Low','Medium','High'),
    best_product VARCHAR(100),
    best_program VARCHAR(150),
    alternate_product VARCHAR(100),
    max_eligible_loan_amount DECIMAL(14,2),
    safe_emi_capacity DECIMAL(12,2),
    foir DECIMAL(5,2),
    missing_documents_json JSON,
    rework_actions_json JSON,
    similar_case_insight TEXT,
    rm_next_action TEXT,
    rm_script TEXT,
    customer_message TEXT,
    rag_explanation TEXT,
    disclaimer VARCHAR(255) DEFAULT 'This is a pre-screening recommendation. Final sanction is subject to IDBI underwriting.',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prospect_id) REFERENCES prospects(id) ON DELETE CASCADE
);
