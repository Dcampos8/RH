USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_branding', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rh_branding (
        id INT NOT NULL CONSTRAINT PK_rh_branding PRIMARY KEY,
        brand_name NVARCHAR(128) NOT NULL,
        accent_color CHAR(7) NOT NULL,
        dark_color CHAR(7) NOT NULL,
        logo_data VARBINARY(MAX) NULL,
        logo_mime NVARCHAR(32) NULL,
        favicon_data VARBINARY(MAX) NULL,
        favicon_mime NVARCHAR(64) NULL,
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_rh_branding_updated DEFAULT SYSUTCDATETIME(),
        CONSTRAINT CK_rh_branding_singleton CHECK (id = 1)
    );
END;
GO

IF COL_LENGTH(N'dbo.rh_branding', N'favicon_data') IS NULL
    ALTER TABLE dbo.rh_branding ADD favicon_data VARBINARY(MAX) NULL;
GO
IF COL_LENGTH(N'dbo.rh_branding', N'favicon_mime') IS NULL
    ALTER TABLE dbo.rh_branding ADD favicon_mime NVARCHAR(64) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.rh_branding WHERE id = 1)
BEGIN
    INSERT INTO dbo.rh_branding (id, brand_name, accent_color, dark_color)
    VALUES (1, N'Transportes Aldez', '#ed2429', '#c91d22');
END;
GO
