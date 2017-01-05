<?php
ini_set('memory_limit', '2048M');
include 'credentials.php';

//Get flights 
$url = "https://api.laminardata.aero/v1/airlines/ETD/flights?user_key=$user_key&status=completed";
$pdo = new PDO("mysql:host=$hostname;port=$port;", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->query("use $database");

callApi($url, $pdo);

echo "Done\n";
exit;

function callApi($path, $pdo) {
    //Gets XML
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/xml"));
    curl_setopt($ch, CURLOPT_FAILONERROR,1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $xml = curl_exec($ch);          
    curl_close($ch);
    //Because XML has namespaces, this is a cheap workaround
    $xml = preg_replace('~(</?|\s)([a-z0-9_]+):~is', '$1$2_', $xml);
    $xml = simplexml_load_string($xml);
    $result = json_decode(json_encode($xml), 1);

    //Build data to insert into DB
    $aircraftVals = array();
    $flightInfoVals = array();
    $trackInfoVals = array();
    foreach ($result["fx_Flight"] as $flight) {

        /*** Flight Info ***/
        $flight_carrier = $flight["fx_flightIdentification"]["@attributes"]["majorCarrierIdentifier"];
        $flight_iata_number = $flight["fx_flightIdentification"]["@attributes"]["iataFlightNumber"];
        //skip if no iata num
        if (!$flight_iata_number) { continue; }
        //If flight has ICAO code
        if ($flight["fx_flightIdentification"]["@attributes"]["aircraftIdentification"]) {
            $flight_icao_number = $flight["fx_flightIdentification"]["@attributes"]["aircraftIdentification"];
        } else {
            //Create icao from Carrier + number from iata
            $flight_icao_number = $flight_carrier . filter_var($flight_iata_number, FILTER_SANITIZE_NUMBER_INT);;
        }

        $flight_status = $flight["fx_flightStatus"]["@attributes"]["flightCycle"];

        /*** Departure Info ***/
        $departure_airport_code = $flight["fx_departure"]["fx_departureAerodrome"]["@attributes"]["code"];

        $initial_departure_time = date("Y-m-d H:i:s", strtotime($flight["fx_departure"]["fx_departureFixTime"]["fb_initial"]["@attributes"]["timestamp"]));
        $estimated_departure_time = date("Y-m-d H:i:s", strtotime($flight["fx_departure"]["fx_departureFixTime"]["fb_estimated"]["@attributes"]["timestamp"]));
        //if flight status airborne, get actual time
        if ($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_actual"]["@attributes"]["timestamp"]) {
            $actual_departure_time = date("Y-m-d H:i:s", strtotime($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_initial"]["@attributes"]["timestamp"]));
        } else {
            $actual_departure_time = NULL;
        }

        /*** Arrival Info ***/
        //Because flights in air use fx_arrivalAerodromeOriginal instead
        if ($flight["fx_arrival"]["fx_arrivalAerodromeOriginal"]) {
            $arrival_airport_code = $flight["fx_arrival"]["fx_arrivalAerodromeOriginal"]["@attributes"]["code"];
        } else {
            $arrival_airport_code = $flight["fx_arrival"]["fx_arrivalAerodrome"]["@attributes"]["code"];
        }
        $initial_arrival_time = date("Y-m-d H:i:s", strtotime($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_initial"]["@attributes"]["timestamp"]));
        $estimated_arrival_time = date("Y-m-d H:i:s", strtotime($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_estimated"]["@attributes"]["timestamp"]));
        //if flight status completed, get actual time
        if ($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_actual"]["@attributes"]["timestamp"]) {
            $actual_arrival_time = date("Y-m-d H:i:s", strtotime($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_initial"]["@attributes"]["timestamp"]));
        } else {
            $actual_arrival_time = NULL;
        }

        // set flight track if airborne
        if ($flight["fx_enRoute"]) {
            $pos = explode(" ", $flight["fx_enRoute"]["fx_position"]["fx_position"]["fb_location"]["ff_pos"]);
            $latitude = $pos[0];
            $longitude = $pos[1];
            $altitude = $flight["fx_enRoute"]["fx_position"]["fx_altitude"];
            $heading = $flight["fx_enRoute"]["fx_position"]["fx_track"];
            $trackInfoVals[] = array(
                "aircraft_iata_number" => $flight_iata_number,
                "initial_departure_time" => $initial_departure_time,
                "latitude" => $latitude,
                "longitude" => $longitude,
                "altitude" => $altitude,
                "heading" => $heading,
            );
        } 

        $aircraftVals[] = array(
            "carrier_code" => $flight_carrier,
            "iata_number" => $flight_iata_number,
            "icao_number" => $flight_icao_number  
        );

        $flightInfoVals[] = array(
            "aircraft_iata_number" => $flight_iata_number,
            "departure_airport_code" => $departure_airport_code,
            "initial_departure_time" => $initial_departure_time,
            "estimated_departure_time" => $estimated_departure_time,
            "actual_departure_time" => $actual_departure_time,
            "arrival_airport_code" => $arrival_airport_code,
            "initial_arrival_time" => $initial_arrival_time,
            "estimated_arrival_time" => $estimated_arrival_time,
            "actual_arrival_time" => $actual_arrival_time,
            "flight_status" => $flight_status
        );
    }

    $tblName1 = "aircraft";
    $colNames1 = array(
        "carrier_code",
        "iata_number",
        "icao_number"
    );

    $tblName2 = "aircraft_flight";
    $colNames2 = array(
        "aircraft_iata_number",
        "departure_airport_code",
        "initial_departure_time",
        "estimated_departure_time",
        "actual_departure_time",
        "arrival_airport_code",
        "initial_arrival_time",
        "estimated_arrival_time",
        "actual_arrival_time",      
        "flight_status"
    );

    $tblName3 = "aircraft_flight_track";
    $colNames3 = array(
        "aircraft_iata_number",
        "initial_departure_time",
        "latitude",
        "longitude",
        "altitude",
        "heading",        
    );

    //Insert into aircraft table
    try {
        $pdo->beginTransaction();

        // setup data values for PDO
        // memory warning: this is creating a copy all of $aircraftVals
        $dataToInsert = array();

        foreach ($aircraftVals as $row => $data) {
            foreach($data as $val) {
                $dataToInsert[] = $val;
            }
        }

        // (optional) setup the ON DUPLICATE column names
        $updateCols = array();

        foreach ($colNames1 as $curCol) {
            $updateCols[] = $curCol . " = VALUES($curCol)";
        }

        $onDup = implode(', ', $updateCols);

        // setup the placeholders - a fancy way to make the long "(?, ?, ?)..." string
        $rowPlaces = '(' . implode(', ', array_fill(0, count($colNames1), '?')) . ')';
        $allPlaces = implode(', ', array_fill(0, count($aircraftVals), $rowPlaces));

        $sql = "INSERT IGNORE INTO $tblName1 (" . implode(', ', $colNames1) . 
            ") VALUES " . $allPlaces;

        // and then the PHP PDO boilerplate
        $stmt = $pdo->prepare ($sql);

        try {
           $stmt->execute($dataToInsert);
        } catch (PDOException $e){
           echo $e->getMessage();
        }

        $pdo->commit();

    } catch (PDOException $e) {
        die("DB ERROR: ". $e->getMessage());
    }

    //insert into aircraft_flight
    try {
        $pdo->beginTransaction();

        // setup data values for PDO
        // memory warning: this is creating a copy all of $flightInfoVals
        $dataToInsert = array();

        foreach ($flightInfoVals as $row => $data) {
            foreach($data as $val) {
                $dataToInsert[] = $val;
            }
        }

        // (optional) setup the ON DUPLICATE column names
        $updateCols = array();

        foreach ($colNames2 as $curCol) {
            $updateCols[] = $curCol . " = VALUES($curCol)";
        }

        $onDup = implode(', ', $updateCols);

        // setup the placeholders - a fancy way to make the long "(?, ?, ?)..." string
        $rowPlaces = '(' . implode(', ', array_fill(0, count($colNames2), '?')) . ')';
        $allPlaces = implode(', ', array_fill(0, count($flightInfoVals), $rowPlaces));

        $sql = "INSERT INTO $tblName2 (" . implode(', ', $colNames2) . 
            ") VALUES " . $allPlaces . " ON DUPLICATE KEY UPDATE $onDup";

        // and then the PHP PDO boilerplate
        $stmt = $pdo->prepare ($sql);

        try {
           $stmt->execute($dataToInsert);
        } catch (PDOException $e){
           echo $e->getMessage();
        }

        $pdo->commit();

    } catch (PDOException $e) {
        die("DB ERROR: ". $e->getMessage());
    }

    //Insert into aircraft_flight_track table
    try {
        $pdo->beginTransaction();

        // setup data values for PDO
        // memory warning: this is creating a copy all of $aircraftVals
        $dataToInsert = array();

        foreach ($trackInfoVals as $row => $data) {
            foreach($data as $val) {
                $dataToInsert[] = $val;
            }
        }

        // (optional) setup the ON DUPLICATE column names
        $updateCols = array();

        foreach ($colNames3 as $curCol) {
            $updateCols[] = $curCol . " = VALUES($curCol)";
        }

        $onDup = implode(', ', $updateCols);

        // setup the placeholders - a fancy way to make the long "(?, ?, ?)..." string
        $rowPlaces = '(' . implode(', ', array_fill(0, count($colNames3), '?')) . ')';
        $allPlaces = implode(', ', array_fill(0, count($trackInfoVals), $rowPlaces));

        $sql = "INSERT IGNORE INTO $tblName3 (" . implode(', ', $colNames3) . 
            ") VALUES " . $allPlaces;

        // and then the PHP PDO boilerplate
        $stmt = $pdo->prepare ($sql);

        try {
           $stmt->execute($dataToInsert);
        } catch (PDOException $e){
           echo $e->getMessage();
        }

        $pdo->commit();

    } catch (PDOException $e) {
        die("DB ERROR: ". $e->getMessage());
    }

}