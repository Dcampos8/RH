USE [ADMP24];
GO

IF OBJECT_ID(N'dbo.notificaciones_legales', N'U') IS NULL
BEGIN
    RAISERROR('No existe dbo.notificaciones_legales en ADMP24.', 16, 1);
    RETURN;
END;
GO

IF OBJECT_ID(N'dbo.notificaciones_legales_historial', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.notificaciones_legales_historial (
        id_historial BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_notificaciones_legales_historial PRIMARY KEY,
        id_notificacion INT NOT NULL,
        version INT NOT NULL,
        accion VARCHAR(10) NOT NULL,
        fecha_cambio_utc DATETIME2(3) NOT NULL CONSTRAINT DF_notificaciones_historial_fecha DEFAULT SYSUTCDATETIME(),
        usuario_id INT NULL,
        usuario NVARCHAR(128) NULL,
        motivo_cambio NVARCHAR(1000) NULL,
        datos_anteriores NVARCHAR(MAX) NULL,
        datos_nuevos NVARCHAR(MAX) NULL,
        CONSTRAINT UQ_notificaciones_historial_version UNIQUE (id_notificacion, version),
        CONSTRAINT CK_notificaciones_historial_accion CHECK (accion IN ('CREAR', 'EDITAR', 'ELIMINAR'))
    );
END;
GO

-- El historial conserva versiones exactas y no permite ediciones ni borrados.
CREATE OR ALTER TRIGGER dbo.tr_notificaciones_legales_historial_inmutable
ON dbo.notificaciones_legales_historial
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 51000, 'El historial de notificaciones es inmutable.', 1;
END;
GO
