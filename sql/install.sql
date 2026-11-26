USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_app_user', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rh_app_user (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_rh_app_user PRIMARY KEY,
        username NVARCHAR(100) NOT NULL,
        password_hash NVARCHAR(255) NOT NULL,
        role NVARCHAR(20) NOT NULL CONSTRAINT DF_rh_app_user_role DEFAULT N'user',
        is_active BIT NOT NULL CONSTRAINT DF_rh_app_user_active DEFAULT 1,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_rh_app_user_created DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UQ_rh_app_user_username UNIQUE (username),
        CONSTRAINT CK_rh_app_user_role CHECK (role IN (N'admin', N'user'))
    );
END;
GO

IF OBJECT_ID(N'dbo.rh_sync_log', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rh_sync_log (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_rh_sync_log PRIMARY KEY,
        status NVARCHAR(10) NOT NULL,
        result_text NVARCHAR(2000) NOT NULL,
        finished_at DATETIME2(0) NOT NULL,
        initiated_by INT NULL,
        CONSTRAINT CK_rh_sync_log_status CHECK (status IN (N'OK', N'ERROR')),
        CONSTRAINT FK_rh_sync_log_user FOREIGN KEY (initiated_by) REFERENCES dbo.rh_app_user(id)
    );
END;
GO
