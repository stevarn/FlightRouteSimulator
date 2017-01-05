<?php
include 'credentials.php';

//Get flights 
$url = "https://api.laminardata.aero/v1/aerodromes/KSFO/destinations/KLAX/flights?user_key=$user_key";
$url2 = "https://api.laminardata.aero/v1/aerodromes/KLAX/destinations/KSFO/flights?user_key=$user_key";

$pdo = new PDO("mysql:host=$hostname;port=$port;", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->query("use $database");

callApi($url, $pdo);
callApi($url2, $pdo);

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
    $dataVals = array();
    foreach ($result["fx_Flight"] as $flight) {

        $flight_number = $flight["fx_flightIdentification"]["@attributes"]["iataFlightNumber"];
        $flight_carrier = $flight["fx_flightIdentification"]["@attributes"]["majorCarrierIdentifier"];
        $flight_status = $flight["fx_flightStatus"]["@attributes"]["flightCycle"];
        $departure_airport_code = $flight["fx_departure"]["fx_departureAerodrome"]["@attributes"]["code"];

        $initial_departure_time = date("Y-m-d H:i:s", strtotime($flight["fx_departure"]["fx_departureFixTime"]["fb_initial"]["@attributes"]["timestamp"]));
        $estimated_departure_time = date("Y-m-d H:i:s", strtotime($flight["fx_departure"]["fx_departureFixTime"]["fb_estimated"]["@attributes"]["timestamp"]));
        //Because flights in air use fx_arrivalAerodromeOriginal instead
        if ($flight["fx_arrival"]["fx_arrivalAerodromeOriginal"]) {
            $arrival_airport_code = $flight["fx_arrival"]["fx_arrivalAerodromeOriginal"]["@attributes"]["code"];
        } else {
            $arrival_airport_code = $flight["fx_arrival"]["fx_arrivalAerodrome"]["@attributes"]["code"];
        }
        $initial_arrival_time = date("Y-m-d H:i:s", strtotime($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_initial"]["@attributes"]["timestamp"]));
        $estimated_arrival_time = date("Y-m-d H:i:s", strtotime($flight["fx_arrival"]["fx_arrivalFixTime"]["fb_estimated"]["@attributes"]["timestamp"]));

        $dataVals[] = array(
            "flight_number" => $flight_number,
            "flight_carrier" => $flight_carrier,
            "flight_status" => $flight_status,
            "departure_airport_code" => $departure_airport_code,
            "initial_departure_time" => $initial_departure_time,
            "estimated_departure_time" => $estimated_departure_time,
            "arrival_airport_code" => $arrival_airport_code,
            "initial_arrival_time" => $initial_arrival_time,
            "estimated_arrival_time" => $estimated_arrival_time
        );
    }
    echo $hostname;

    $tblName = "flight";
    $colNames = array(
        "flight_number",
        "flight_carrier",
        "flight_status",
        "departure_airport_code",
        "initial_departure_time",
        "estimated_departure_time",
        "arrival_airport_code",
        "initial_arrival_time",
        "estimated_arrival_time"
    );

    try {
        $pdo->beginTransaction();

        // setup data values for PDO
        // memory warning: this is creating a copy all of $dataVals
        $dataToInsert = array();

        foreach ($dataVals as $row => $data) {
            foreach($data as $val) {
                $dataToInsert[] = $val;
            }
        }

        // (optional) setup the ON DUPLICATE column names
        $updateCols = array();

        foreach ($colNames as $curCol) {
            $updateCols[] = $curCol . " = VALUES($curCol)";
        }

        $onDup = implode(', ', $updateCols);

        // setup the placeholders - a fancy way to make the long "(?, ?, ?)..." string
        $rowPlaces = '(' . implode(', ', array_fill(0, count($colNames), '?')) . ')';
        $allPlaces = implode(', ', array_fill(0, count($dataVals), $rowPlaces));

        $sql = "INSERT INTO $tblName (" . implode(', ', $colNames) . 
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

}

