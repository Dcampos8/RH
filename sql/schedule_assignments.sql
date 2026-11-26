USE [CHECADOR];
GO

IF OBJECT_ID(N'dbo.rh_employee_schedule', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.rh_employee_schedule (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_rh_employee_schedule PRIMARY KEY,
        employee_id INT NOT NULL,
        shift_id INT NOT NULL,
        weekday TINYINT NOT NULL,
        valid_from DATE NOT NULL,
        valid_to DATE NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_rh_employee_schedule_created DEFAULT SYSUTCDATETIME(),
        CONSTRAINT CK_rh_employee_schedule_weekday CHECK (weekday BETWEEN 1 AND 7),
        CONSTRAINT CK_rh_employee_schedule_dates CHECK (valid_to IS NULL OR valid_to >= valid_from),
        CONSTRAINT FK_rh_employee_schedule_employee FOREIGN KEY (employee_id) REFERENCES dbo.hr_employee(id),
        CONSTRAINT FK_rh_employee_schedule_shift FOREIGN KEY (shift_id) REFERENCES dbo.att_shift(id)
    );
    CREATE INDEX IX_rh_employee_schedule_lookup
        ON dbo.rh_employee_schedule(employee_id, weekday, valid_from, valid_to);
END;
GO
