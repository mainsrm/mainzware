-- Receipt scanning foundation for the Budgeteer receipt-itemization feature.
-- A receipt is one uploaded image/photo; receipt_items are the line items OCR/parsing
-- extracts from it. OCR processing is asynchronous, so receipts start as 'pending'.
CREATE TABLE IF NOT EXISTS receipts (
    id SERIAL PRIMARY KEY,
    budget_id INTEGER NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
    uploaded_by INTEGER NOT NULL REFERENCES users(id),
    original_filename TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'processed', 'failed')),
    merchant TEXT,
    purchase_date DATE,
    total_amount NUMERIC(12,2),
    -- Raw OCR/parser output kept alongside structured columns so receipts can be re-parsed later.
    ocr_raw JSONB,
    transaction_id INTEGER REFERENCES budget_transactions(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS receipt_items (
    id SERIAL PRIMARY KEY,
    receipt_id INTEGER NOT NULL REFERENCES receipts(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    quantity NUMERIC(10,2) NOT NULL DEFAULT 1,
    unit_price NUMERIC(12,2),
    amount NUMERIC(12,2) NOT NULL,
    budget_category TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_receipts_budget_id ON receipts (budget_id);
CREATE INDEX IF NOT EXISTS idx_receipts_status ON receipts (status);
CREATE INDEX IF NOT EXISTS idx_receipt_items_receipt_id ON receipt_items (receipt_id);
