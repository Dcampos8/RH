USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_user_permissions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rh_user_permissions (
        user_id INT NOT NULL CONSTRAINT PK_rh_user_permissions PRIMARY KEY,
        report_view BIT NOT NULL CONSTRAINT DF_rh_perm_report DEFAULT 1,
        employee_view BIT NOT NULL CONSTRAINT DF_rh_perm_employee_view DEFAULT 0,
        employee_create BIT NOT NULL CONSTRAINT DF_rh_perm_employee_create DEFAULT 0,
        schedule_view BIT NOT NULL CONSTRAINT DF_rh_perm_schedule_view DEFAULT 0,
        schedule_manage BIT NOT NULL CONSTRAINT DF_rh_perm_schedule_manage DEFAULT 0,
        platform_users_manage BIT NOT NULL CONSTRAINT DF_rh_perm_users DEFAULT 0,
        settings_manage BIT NOT NULL CONSTRAINT DF_rh_perm_settings DEFAULT 0,
        sync_run BIT NOT NULL CONSTRAINT DF_rh_perm_sync DEFAULT 0,
        updated_by INT NULL,
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_rh_perm_updated DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_rh_user_permissions_user FOREIGN KEY (user_id) REFERENCES dbo.sys_user(id) ON DELETE CASCADE,
        CONSTRAINT FK_rh_user_permissions_updated_by FOREIGN KEY (updated_by) REFERENCES dbo.sys_user(id)
    );
END;
GO

-- Existing accounts start with report access only; admin always has all permissions.
INSERT INTO dbo.rh_user_permissions (user_id, report_view)
SELECT u.id, 1
FROM dbo.sys_user AS u
WHERE LOWER(u.username) <> N'admin'
  AND NOT EXISTS (SELECT 1 FROM dbo.rh_user_permissions AS p WHERE p.user_id = u.id);
GO
