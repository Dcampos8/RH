USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_sync_config', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rh_sync_config (
        id INT NOT NULL CONSTRAINT PK_rh_sync_config PRIMARY KEY,
        device_ip NVARCHAR(64) NOT NULL,
        device_port INT NOT NULL CONSTRAINT DF_rh_sync_port DEFAULT 4370,
        device_password NVARCHAR(64) NOT NULL CONSTRAINT DF_rh_sync_password DEFAULT N'0',
        auto_enabled BIT NOT NULL CONSTRAINT DF_rh_sync_enabled DEFAULT 1,
        interval_minutes INT NOT NULL CONSTRAINT DF_rh_sync_interval DEFAULT 15,
        last_run_at DATETIME2(0) NULL,
        next_run_at DATETIME2(0) NULL,
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_rh_sync_updated DEFAULT SYSUTCDATETIME(),
        CONSTRAINT CK_rh_sync_id CHECK (id = 1),
        CONSTRAINT CK_rh_sync_port CHECK (device_port BETWEEN 1 AND 65535),
        CONSTRAINT CK_rh_sync_interval CHECK (interval_minutes IN (15, 30, 60))
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.rh_sync_config WHERE id = 1)
BEGIN
    INSERT INTO dbo.rh_sync_config (id, device_ip, device_port, device_password, auto_enabled, interval_minutes)
    VALUES (1, N'192.168.10.149', 4370, N'0', 1, 15);
END;
GO
