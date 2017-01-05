<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// Routes

$app->get('/', function ($request, $response, $args) {
    // Render index view (GAME)
    return $this->renderer->render($response, 'carriermap.phtml', $args);
});

$app->get('/airport', function ($request, $response, $args) {
    // Render index view (GAME)
    return $this->renderer->render($response, 'phaser.phtml', $args);
});

$app->get('/api/flights', function ($request, $response, $args) {
    $params = $request->getQueryParams();
    $dest1 = filter_var($params["dest1"], FILTER_SANITIZE_STRING);
    $dest2 = filter_var($params["dest2"], FILTER_SANITIZE_STRING);
    $timestamp = filter_var($params["timestamp"], FILTER_SANITIZE_NUMBER_INT);

    if (!$dest1 || !$dest2 || !$timestamp) {
        $newResponse = $response->withStatus(400);
        return $newResponse;
    }

    $fromDate = date("Y-m-d H:i:s", strtotime('-30 min', $timestamp)); 
    $toDate = date("Y-m-d H:i:s", strtotime('+30 min', $timestamp)); 

    $result = $this->db->prepare("SELECT * FROM flight WHERE
        (departure_airport_code = :port1 AND 
        arrival_airport_code = :port2 AND 
        estimated_departure_time BETWEEN :date1 AND :date2) 
        OR
        (departure_airport_code = :port2 AND 
        arrival_airport_code = :port1 AND 
        estimated_departure_time BETWEEN :date1 AND :date2);
    "); 
    $result->bindParam(':port1', $dest1);
    $result->bindParam(':port2', $dest2);
    $result->bindParam(':date1', $fromDate);
    $result->bindParam(':date2', $toDate);
    $departures = array();
    $departures[$dest1] = array();
    $departures[$dest2] = array();
    if ($result->execute()) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $departures[$row["departure_airport_code"]][] = $row;
        }
    }

    $result = $this->db->prepare("SELECT * FROM flight WHERE
        (departure_airport_code = :port1 AND 
        arrival_airport_code = :port2 AND 
        estimated_arrival_time BETWEEN :date1 AND :date2) 
        OR
        (departure_airport_code = :port2 AND 
        arrival_airport_code = :port1 AND 
        estimated_arrival_time BETWEEN :date1 AND :date2);
    "); 
    $result->bindParam(':port1', $dest1);
    $result->bindParam(':port2', $dest2);
    $result->bindParam(':date1', $fromDate);
    $result->bindParam(':date2', $toDate);
    $arrivals = array();
    $arrivals[$dest1] = array();
    $arrivals[$dest2] = array();
    if ($result->execute()) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $arrivals[$row["arrival_airport_code"]][] = $row;
        }
    }
    $flights = array("departures" => $departures, "arrivals" => $arrivals);
    
    $newResponse = $response->withJson($flights);
    return $newResponse;
});

$app->get('/api/carrier/{code}', function ($request, $response, $args) {
    $params = $request->getQueryParams();
    $code = filter_var($request->getAttribute('code'), FILTER_SANITIZE_STRING);

    if (!$code) {
        $newResponse = $response->withStatus(400);
        return $newResponse;
    }

    $result = $this->db->prepare("SELECT * FROM aircraft INNER JOIN aircraft_flight
        ON aircraft.iata_number = aircraft_flight.aircraft_iata_number INNER JOIN aircraft_flight_track ON aircraft_flight.aircraft_iata_number = aircraft_flight_track.aircraft_iata_number AND aircraft_flight.initial_departure_time = aircraft_flight_track.initial_departure_time WHERE
        carrier_code = :code AND 
        flight_status = 'AIRBORNE'
        ;
    "); 
    $result->bindParam(':code', $code);
    $airborne = array();
    if ($result->execute()) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $airborne[$row["iata_number"]] = $row;
        }
    }

    $result = $this->db->prepare("SELECT * FROM aircraft INNER JOIN aircraft_flight
        ON aircraft.iata_number = aircraft_flight.aircraft_iata_number INNER JOIN aircraft_flight_track ON aircraft_flight.aircraft_iata_number = aircraft_flight_track.aircraft_iata_number AND aircraft_flight.initial_departure_time = aircraft_flight_track.initial_departure_time WHERE
        carrier_code = :code AND 
        flight_status != 'AIRBORNE' 
        ORDER BY estimated_departure_time
        ;
    "); 
    $result->bindParam(':code', $code);
    $schedule = array();

    if ($result->execute()) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($row["flight_status"] == "COMPLETED") {
                $schedule[$row["iata_number"]]["completed"][] = $row;
            } else if ($row["flight_status"] == "SCHEDULED" || $row["flight_status"] == "FILED" ) {
                $schedule[$row["iata_number"]]["scheduled"][] = $row;
            }
        }
    }
    $flights = array("airborne" => $airborne, "schedules" => $schedule);
    $newResponse = $response->withJson($flights);
    return $newResponse;
});


$app->get('/api/flight/track', function ($request, $response, $args) {
    $params = $request->getQueryParams();
    $aircraft_iata_number = filter_var($params["aircraft_iata_number"], FILTER_SANITIZE_STRING);
    $initial_departure_time = filter_var($params["initial_departure_time"], FILTER_SANITIZE_STRING);

    if (!$aircraft_iata_number || !$initial_departure_time) {
        $newResponse = $response->withStatus(400);
        return $newResponse;
    }

    $result = $this->db->prepare("SELECT * FROM aircraft_flight_track WHERE
        aircraft_iata_number = :aircraft_iata_number AND 
        initial_departure_time = :initial_departure_time
        ;
    "); 
    $result->bindParam(':aircraft_iata_number', $aircraft_iata_number);
    $result->bindParam(':initial_departure_time', $initial_departure_time);

    $track = array();
    if ($result->execute()) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $track[] = $row;
        }
    }
    $newResponse = $response->withJson($track);
    return $newResponse;
});
