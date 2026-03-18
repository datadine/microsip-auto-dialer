-- FusionPBX Call Logs Table for Leads Lite
-- Stores synced call records from FusionPBX

CREATE TABLE IF NOT EXISTS fusionpbx_calls (
    id SERIAL PRIMARY KEY,
    xml_cdr_uuid UUID UNIQUE NOT NULL,
    extension VARCHAR(20),
    extension_uuid UUID,
    caller_id_number VARCHAR(50),
    destination_number VARCHAR(50),
    start_stamp TIMESTAMP WITH TIME ZONE,
    answer_stamp TIMESTAMP WITH TIME ZONE,
    end_stamp TIMESTAMP WITH TIME ZONE,
    duration INTEGER,
    billsec INTEGER,
    hangup_cause VARCHAR(50),
    direction VARCHAR(20),
    synced_at TIMESTAMP DEFAULT NOW()
);

-- Create indexes for faster queries
CREATE INDEX IF NOT EXISTS idx_fusionpbx_extension ON fusionpbx_calls (extension);
CREATE INDEX IF NOT EXISTS idx_fusionpbx_destination ON fusionpbx_calls (destination_number);
CREATE INDEX IF NOT EXISTS idx_fusionpbx_start_stamp ON fusionpbx_calls (start_stamp);
CREATE INDEX IF NOT EXISTS idx_fusionpbx_calls_date ON fusionpbx_calls (DATE(start_stamp AT TIME ZONE 'America/New_York'));

-- Last sync tracking table
CREATE TABLE IF NOT EXISTS fusionpbx_sync_log (
    id SERIAL PRIMARY KEY,
    last_sync_time TIMESTAMP WITH TIME ZONE,
    records_synced INTEGER,
    sync_status VARCHAR(20),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
