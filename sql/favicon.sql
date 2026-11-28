USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_branding', N'U') IS NULL
BEGIN
    RAISERROR('No existe dbo.rh_branding. Ejecuta primero sql/branding.sql.', 16, 1);
    RETURN;
END;
GO

IF COL_LENGTH(N'dbo.rh_branding', N'favicon_data') IS NULL
    ALTER TABLE dbo.rh_branding ADD favicon_data VARBINARY(MAX) NULL;
GO
IF COL_LENGTH(N'dbo.rh_branding', N'favicon_mime') IS NULL
    ALTER TABLE dbo.rh_branding ADD favicon_mime NVARCHAR(64) NULL;
GO

