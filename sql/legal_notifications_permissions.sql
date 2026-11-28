USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_user_permissions', N'U') IS NULL
BEGIN
    RAISERROR('Primero ejecuta sql/user_permissions.sql.', 16, 1);
    RETURN;
END;
GO

IF COL_LENGTH(N'dbo.rh_user_permissions', N'legal_notifications_view') IS NULL
BEGIN
    ALTER TABLE dbo.rh_user_permissions ADD legal_notifications_view BIT NOT NULL
        CONSTRAINT DF_rh_perm_legal_notifications DEFAULT 0;
END;
GO

IF COL_LENGTH(N'dbo.rh_user_permissions', N'legal_notifications_manage') IS NULL
BEGIN
    ALTER TABLE dbo.rh_user_permissions ADD legal_notifications_manage BIT NOT NULL
        CONSTRAINT DF_rh_perm_legal_manage DEFAULT 0;
END;
GO
