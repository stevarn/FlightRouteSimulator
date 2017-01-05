<?php
include 'credentials.php';

try {
    $pdo = new PDO("mysql:host=$hostname;port=$port;", $username, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    //Create Database
    $pdo->query("CREATE DATABASE IF NOT EXISTS $database");
    $pdo->query("use $database");

    $pdo->query("DROP TABLE IF EXISTS aircraft_flight");
    $pdo->query("DROP TABLE IF EXISTS aircraft_flight_track");
    $pdo->query("DROP TABLE IF EXISTS aircraft");

    //Create aircraft table
    $sql = "CREATE TABLE IF NOT EXISTS aircraft(
                entity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                carrier_code VARCHAR(3) NOT NULL,
                iata_number VARCHAR(10) NOT NULL,
                icao_number VARCHAR(10) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (entity_id),
                UNIQUE KEY (iata_number)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $pdo->exec($sql);

    //Create aircraft_flight table
    $sql = "CREATE TABLE IF NOT EXISTS aircraft_flight(
                entity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                aircraft_iata_number VARCHAR(10) NOT NULL,
                departure_airport_code VARCHAR(4) NOT NULL,
                initial_departure_time DATETIME NOT NULL,
                estimated_departure_time DATETIME NOT NULL,
                actual_departure_time DATETIME,
                arrival_airport_code VARCHAR(4) NOT NULL,
                initial_arrival_time DATETIME NOT NULL,
                estimated_arrival_time DATETIME NOT NULL,
                actual_arrival_time DATETIME,
                flight_status VARCHAR(25) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (entity_id),
                UNIQUE KEY aircraft_iata_number (aircraft_iata_number, initial_departure_time),
                FOREIGN KEY (aircraft_iata_number) REFERENCES aircraft(iata_number)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $pdo->exec($sql);

    //Create aircraft_flight table
    $sql = "CREATE TABLE IF NOT EXISTS aircraft_flight_track(
                entity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                aircraft_iata_number VARCHAR(10) NOT NULL,
                initial_departure_time DATETIME NOT NULL,
                latitude DECIMAL(6, 4),
                longitude DECIMAL(7, 4),
                altitude SMALLINT UNSIGNED,
                heading SMALLINT UNSIGNED,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (entity_id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $pdo->exec($sql);

} catch (PDOException $e) {
    die("DB ERROR: ". $e->getMessage());
}
echo "02 Complete\n";
