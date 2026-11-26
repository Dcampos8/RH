USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.sys_user', N'U') IS NULL
    THROW 50001, 'No existe dbo.sys_user en CHECADOR.', 1;
GO

DECLARE @nullable NVARCHAR(10);
DECLARE @type_name SYSNAME;
DECLARE @max_length SMALLINT;

SELECT
    @nullable = CASE WHEN c.is_nullable = 1 THEN N'NULL' ELSE N'NOT NULL' END,
    @type_name = TYPE_NAME(c.user_type_id),
    @max_length = c.max_length
FROM sys.columns AS c
WHERE c.object_id = OBJECT_ID(N'dbo.sys_user')
  AND c.name = N'user_pwd';

IF @type_name IS NULL
    THROW 50002, 'No existe la columna dbo.sys_user.user_pwd.', 1;

IF @type_name <> N'nvarchar' OR (@max_length <> -1 AND @max_length < 510)
BEGIN
    DECLARE @sql NVARCHAR(MAX) =
        N'ALTER TABLE dbo.sys_user ALTER COLUMN user_pwd NVARCHAR(255) ' + @nullable + N';';
    EXEC sys.sp_executesql @sql;
END;
GO

SELECT c.name, TYPE_NAME(c.user_type_id) AS tipo,
       CASE WHEN c.max_length = -1 THEN -1 ELSE c.max_length / 2 END AS caracteres_maximos
FROM sys.columns AS c
WHERE c.object_id = OBJECT_ID(N'dbo.sys_user')
  AND c.name = N'user_pwd';
GO
