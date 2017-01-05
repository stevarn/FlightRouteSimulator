<?php
include 'credentials.php';

try {
    $pdo = new PDO("mysql:host=$hostname;port=$port;", $username, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    //Create Database
    $pdo->query("CREATE DATABASE IF NOT EXISTS $database");
    $pdo->query("use $database");

    $pdo->query("DROP TABLE IF EXISTS flight");

    //Create flight table
    $sql = "CREATE TABLE IF NOT EXISTS flight(
                entity_id INT NOT NULL AUTO_INCREMENT,
                flight_number VARCHAR(25) NOT NULL,
                flight_carrier VARCHAR(4) NOT NULL,
                flight_status VARCHAR(25) NOT NULL,
                departure_airport_code VARCHAR(4) NOT NULL,
                initial_departure_time DATETIME NOT NULL,
                estimated_departure_time DATETIME NOT NULL,
                actual_departure_time DATETIME,
                arrival_airport_code VARCHAR(4) NOT NULL,
                initial_arrival_time DATETIME NOT NULL,
                estimated_arrival_time DATETIME NOT NULL,
                actual_arrival_time DATETIME,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (entity_id),
                UNIQUE KEY flight_number (flight_number, initial_departure_time)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $pdo->exec($sql);

} catch (PDOException $e) {
    die("DB ERROR: ". $e->getMessage());
}
echo "01 Complete\n";
