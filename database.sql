-- Crear base de datos si no existe
CREATE DATABASE IF NOT EXISTS parroquia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE parroquia;

-- Tabla tipodocumento
CREATE TABLE tipodocumento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Tabla cargo
CREATE TABLE cargo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Tabla banco
CREATE TABLE banco (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Tabla ciudad
CREATE TABLE ciudad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Tabla tipocuenta
CREATE TABLE tipocuenta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

-- Tabla empleado
CREATE TABLE empleado (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL, -- Hasheado
    id_tipodoc INT NOT NULL,
    documento VARCHAR(50) NOT NULL,
    id_cargo INT NOT NULL,
    id_banco INT NOT NULL,
    id_ciudad INT NOT NULL,
    id_tipcue INT NOT NULL,
    cuenta VARCHAR(50) NOT NULL,
    salario DECIMAL(10,2) DEFAULT 0.00, -- Agregado para nomina
    fechacreacion DATE DEFAULT (CURRENT_DATE),
    fechamodificacion DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (id_tipodoc) REFERENCES tipodocumento(id) ON DELETE RESTRICT,
    FOREIGN KEY (id_cargo) REFERENCES cargo(id) ON DELETE RESTRICT,
    FOREIGN KEY (id_banco) REFERENCES banco(id) ON DELETE RESTRICT,
    FOREIGN KEY (id_ciudad) REFERENCES ciudad(id) ON DELETE RESTRICT,
    FOREIGN KEY (id_tipcue) REFERENCES tipocuenta(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Tabla liquidacion
CREATE TABLE liquidacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado INT NOT NULL,
    mes INT NOT NULL,
    ano INT NOT NULL,
    dias_trabajados INT NOT NULL DEFAULT 30, -- Días trabajados (1-30)
    salario_base DECIMAL(10,2) NOT NULL,
    devengos DECIMAL(10,2) DEFAULT 0.00, -- Extras, etc.
    deducciones_total DECIMAL(10,2) DEFAULT 0.00,
    aportes_total DECIMAL(10,2) DEFAULT 0.00,
    total_neto DECIMAL(10,2) NOT NULL,
    tipo_liquidacion ENUM('mensual', 'prima') DEFAULT 'mensual',
    fecha_liquidacion DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (id_empleado) REFERENCES empleado(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla deducciones
CREATE TABLE deducciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_liquidacion INT NOT NULL,
    tipo VARCHAR(50) NOT NULL, -- salud, pension
    monto DECIMAL(10,2) NOT NULL,
    base DECIMAL(10,2) NOT NULL,
    porcentaje DECIMAL(5,2),
    FOREIGN KEY (id_liquidacion) REFERENCES liquidacion(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla aportes
CREATE TABLE aportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_liquidacion INT NOT NULL,
    tipo VARCHAR(50) NOT NULL, -- salud, pension, prima, cesantia
    monto DECIMAL(10,2) NOT NULL,
    base DECIMAL(10,2) NOT NULL,
    porcentaje DECIMAL(5,2),
    cobrado BOOLEAN DEFAULT FALSE, -- Para primas y cesantías: si ya fueron cobradas
    fecha_cobro DATE NULL, -- Fecha en que se cobró prima o cesantía
    FOREIGN KEY (id_liquidacion) REFERENCES liquidacion(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla prestaciones
CREATE TABLE prestaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado INT NOT NULL,
    tipo VARCHAR(50) NOT NULL, -- cesantias, prima, vacaciones
    monto DECIMAL(10,2) NOT NULL,
    base DECIMAL(10,2) NOT NULL,
    fecha DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (id_empleado) REFERENCES empleado(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla aprobacion
CREATE TABLE aprobacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_liquidacion INT NOT NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
    fecha_aprobacion DATE NULL,
    observaciones TEXT,
    FOREIGN KEY (id_liquidacion) REFERENCES liquidacion(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla comprobante
CREATE TABLE comprobante (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_liquidacion INT NOT NULL,
    contenido TEXT NOT NULL, -- HTML o path a PDF
    fecha_generacion DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (id_liquidacion) REFERENCES liquidacion(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla reporte eliminada por requerimiento (ya no se utiliza)

-- Datos iniciales para tablas auxiliares (sin acentos)
INSERT INTO tipodocumento (nombre) VALUES 
('Cedula de Ciudadania'), 
('Tarjeta de Identidad'), 
('Cedula de Extranjeria');

INSERT INTO cargo (nombre) VALUES 
('Administrador'), 
('Empleado RH'), 
('Empleado General');

INSERT INTO banco (nombre) VALUES 
('Bancolombia'), 
('Davivienda'), 
('BBVA'), 
('Banco de Bogota');

INSERT INTO ciudad (nombre) VALUES 
('Quibdo'),
('Bogota'), 
('Medellin'), 
('Cali'), 
('Barranquilla');

INSERT INTO tipocuenta (nombre) VALUES
('Ahorros'),
('Corriente');

-- Tabla configuracion
CREATE TABLE configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL DEFAULT 'Parroquia San Francisco de Asís',
    nit VARCHAR(50) NOT NULL DEFAULT '123456789-0',
    direccion VARCHAR(255) NOT NULL DEFAULT 'Calle Principal #123, Quibdó, Chocó',
    telefono VARCHAR(50) NOT NULL DEFAULT '(4) 123-4567',
    otro VARCHAR(255) NULL,
    email VARCHAR(255) NOT NULL DEFAULT 'parroquia.sanfrancisco@ejemplo.com',
    tasasalud INT NOT NULL DEFAULT 4,
    tasapension INT NOT NULL DEFAULT 4,
    factor_extras FLOAT NOT NULL DEFAULT 1.25,
    fechar_espaldo TIMESTAMP NULL DEFAULT NULL,
    fecha_creacion DATE DEFAULT CURRENT_DATE,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insertar datos iniciales en configuracion
INSERT INTO configuracion (id, nombre, nit, direccion, telefono, email, tasasalud, tasapension, factor_extras)
VALUES (1, 'Parroquia San Francisco de Asís', '123456789-0', 'Calle Principal #123, Quibdó, Chocó', '(4) 123-4567', 'parroquia.sanfrancisco@ejemplo.com', 4, 4, 1.25);