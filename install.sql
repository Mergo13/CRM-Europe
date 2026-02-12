
USE crm_app;

CREATE TABLE clients (
                         id INT AUTO_INCREMENT PRIMARY KEY,
                         kundennummer VARCHAR(20) NOT NULL UNIQUE,
                         name VARCHAR(100) NOT NULL,
                         email VARCHAR(100),
                         adresse TEXT,
                         plz VARCHAR(10),
                         ort VARCHAR(100),
                         telefon VARCHAR(50)
);

CREATE TABLE rechnungen (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            client_id INT NOT NULL,
                            rechnungsnummer VARCHAR(20) NOT NULL,
                            datum DATE,
                            betrag DECIMAL(10,2),
                            faelligkeit DATE,
                            status ENUM('offen','bezahlt','mahnung') DEFAULT 'offen',
                            mahn_stufe INT DEFAULT 0,
                            pdf_path VARCHAR(255),
                            FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE angebote (
                          id INT AUTO_INCREMENT PRIMARY KEY,
                          client_id INT NOT NULL,
                          angebotsnummer VARCHAR(20) NOT NULL,
                          datum DATE,
                          betrag DECIMAL(10,2),
                          gueltig_bis DATE,
                          status ENUM('offen','angenommen','abgelehnt') DEFAULT 'offen',
                          pdf_path VARCHAR(255),
                          FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE mahnungen (
                           id INT AUTO_INCREMENT PRIMARY KEY,
                           rechnung_id INT NOT NULL,
                           stufe INT DEFAULT 1,
                           datum DATE,
                           text TEXT,
                           sent_email TINYINT(1) DEFAULT 0,
                           pdf_path VARCHAR(255),
                           UNIQUE KEY unique_mahnung_per_stage (rechnung_id, stufe),
                           FOREIGN KEY (rechnung_id) REFERENCES rechnungen(id)
);
