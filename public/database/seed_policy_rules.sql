INSERT INTO loan_product_rules
(loan_type, program_name, employment_type_allowed_json, min_cibil, min_income, max_foir, max_ltv, min_age, max_age, max_loan_amount, property_type_allowed_json, required_documents_json, deviation_possible, rule_notes)
VALUES
('personal_loan', 'Personal Loan - Salaried', '["salaried"]', 700, 25000, 50, NULL, 21, 60, 2500000, NULL, '["PAN","Aadhaar","salary_slip","bank_statement"]', 1, 'Standard salaried PL program'),
('personal_loan', 'Personal Loan - Self Employed', '["self_employed","business_owner","professional"]', 720, 40000, 45, NULL, 25, 60, 1500000, NULL, '["PAN","Aadhaar","ITR","bank_statement","GST"]', 0, 'Self-employed PL, stricter FOIR'),
('home_loan', 'Home Loan - Salaried', '["salaried"]', 700, 30000, 55, 80, 21, 65, 20000000, '["residential"]', '["PAN","Aadhaar","salary_slip","ITR","bank_statement","property_papers"]', 1, 'Standard salaried home loan'),
('home_loan', 'Home Loan - Self Employed ITR', '["self_employed","business_owner","professional"]', 720, 50000, 50, 75, 25, 65, 15000000, '["residential"]', '["PAN","Aadhaar","ITR","bank_statement","property_papers","GST"]', 1, 'Self-employed with ITR proof'),
('home_loan', 'Home Loan - Banking Surrogate', '["self_employed","business_owner"]', 700, 40000, 45, 70, 25, 60, 10000000, '["residential"]', '["PAN","Aadhaar","bank_statement","property_papers","Udyam"]', 1, 'For thin-ITR but strong banking customers'),
('mortgage_loan', 'LAP - Residential Property', '["salaried","self_employed","business_owner","professional"]', 680, 30000, 55, 65, 25, 65, 30000000, '["residential"]', '["PAN","Aadhaar","ITR","bank_statement","property_papers"]', 1, 'LAP against residential property'),
('mortgage_loan', 'LAP - Commercial Property', '["self_employed","business_owner"]', 700, 50000, 50, 60, 25, 60, 50000000, '["commercial"]', '["PAN","Aadhaar","ITR","bank_statement","property_papers","GST"]', 0, 'LAP against commercial property'),
('auto_loan', 'Auto Loan - Salaried', '["salaried"]', 680, 20000, 50, 85, 21, 60, 1500000, NULL, '["PAN","Aadhaar","salary_slip","bank_statement"]', 1, 'Standard auto loan salaried'),
('auto_loan', 'Auto Loan - Self Employed', '["self_employed","business_owner","professional"]', 700, 30000, 45, 80, 25, 60, 1200000, NULL, '["PAN","Aadhaar","ITR","bank_statement"]', 0, 'Self-employed auto loan');
