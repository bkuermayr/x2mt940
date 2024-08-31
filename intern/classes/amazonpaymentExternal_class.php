<?php

class amazonPayExternal {

    private $mt940param;
    private $data;
    private $inFile;
    private $ppHeader;
    private $amountTotal;
    private $dataPos;
    private $dataCount;
    private $mapping;

    public function __construct($fileName) {
        include './intern/config.php';

        // Initialize variables
        $this->mt940param = $amazon ?? []; // Ensure $amazon is an array
        $this->data = [];
        $this->amountTotal = 0;
        $this->dataPos = 0;
        $this->dataCount = 0;

        $this->inFile = new myfile($fileName); // Changed to use inFile property

        if (file_exists("./intern/mapping/".$mapping_prefix."amazonpayExternal.json")) {
            $mapping = new myfile("./intern/mapping/".$mapping_prefix."amazonpayExternal.json", "readfull");
        } else {
            $mapping = new myfile("./intern/mapping/amazonpayExternal.json", "readfull");
        }
        $this->mapping = $mapping->readJson();

        $this->mt940param['startdate'] = null;
        $this->mt940param['enddate'] = null;

        // Read the CSV header
        $headerRead = false;
        while (($row = $this->inFile->readCSV(',')) !== false) { // Use inFile property
            if (!$headerRead) {
                // Remove BOM and quotes
                if (!empty($row[0])) {
                    $row[0] = str_replace("\xEF\xBB\xBF", '', $row[0]);
                    $row[0] = trim($row[0], "\"");
                }

                // Set header and print for debugging
                $this->ppHeader = $row;
                echo "<pre>Header Row: " . json_encode($this->ppHeader, JSON_PRETTY_PRINT) . "</pre>";
                $headerRead = true;
                continue;  // Continue to the next loop to read data rows
            }

            // Print each data row for debugging
            echo "<pre>Data Row: " . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";
        }

        // Output the final header after setting it
        echo "<pre>Final Header: " . json_encode($this->ppHeader, JSON_PRETTY_PRINT) . "</pre>";
    }

    public function importData() {
        
        while (($row = $this->inFile->readCSV(',')) !== false) { // Use inFile property
            echo "<pre>Row Import Data: " . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";

            $rowdata = [];
            $rowdata = array_combine($this->ppHeader, $row);
            if ($this->mt940param['startdate'] == null) {
                $this->mt940param['startdate'] = date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']] ?? '')); // Added null coalescing operator
            }
            $rowdata[$this->mapping['TRANSACTION_AMOUNT']] = str_replace(".", "", $rowdata[$this->mapping['TRANSACTION_AMOUNT']] ?? '0'); // Added null coalescing operator
            $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = str_replace(".", "", $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] ?? '0'); // Added null coalescing operator

            $rowdata[$this->mapping['TRANSACTION_AMOUNT']] = str_replace(",", ".", $rowdata[$this->mapping['TRANSACTION_AMOUNT']] ?? '0'); // Added null coalescing operator
            $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = str_replace(",", ".", $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] ?? '0'); // Added null coalescing operator

            if (!empty($rowdata[$this->mapping['TRANSACTION_EVENTCODE']]) && !in_array($rowdata[$this->mapping['TRANSACTION_EVENTCODE']], $this->mapping['CHECK_EXCLUDECODE'])) {
                // Check if TRANSACTION_EVENTCODE exists and is not in CHECK_EXCLUDECODE

                if ($rowdata[$this->mapping['TRANSACTION_AMOUNT']] > 0) {
                    $transactionType = "C";
                    $transactionChargeType = "D";
                    $this->amountTotal += $rowdata[$this->mapping['TRANSACTION_AMOUNT']];
                    $this->amountTotal += $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']];

                    $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);
                } else {
                    $transactionType = "D";
                    $transactionChargeType = "C";
                    $rowdata[$this->mapping['TRANSACTION_AMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
                    $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);

                    $this->amountTotal -= $rowdata[$this->mapping['TRANSACTION_AMOUNT']];
                    $this->amountTotal -= $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']];
                }

                $name = strtoupper(preg_replace('/[^a-z0-9 ]/i', '_', $rowdata[$this->mapping['TRANSACTION_SELLER_NAME']] ?? 'UNKNOWN')); // Added null coalescing operator

                $event = substr($rowdata[$this->mapping['TRANSACTION_EVENTCODE']], 0, 30);
                if ($event == $this->mapping['TRANSACTION_EVENTCODE']) {
                    $event .= " PAYOUT";
                }

                // Create MT940 transaction entry
                $mt940 = [];

                if ($rowdata[$this->mapping['TRANSACTION_AMOUNT']] <> 0) {
                    echo "<pre>Mapping Transaction amount not zero: " . json_encode($rowdata, JSON_PRETTY_PRINT) . "</pre>";

                    $mt940 = [
                        'PAYMENT_DATE' => date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']] ?? '')), // Added null coalescing operator
                        'PAYMENT_TYPE' => $transactionType,
                        'PAYMENT_AMOUNT' => str_replace(".", ",", sprintf("%01.2f", $rowdata[$this->mapping['TRANSACTION_AMOUNT']])),
                        'PAYMENT_NDDT' => 'NONREF',
                        'PAYMENT_TEXT00' => 'AMAZON',
                        'PAYMENT_TEXT20' => 'AMAZON AMAZON_CUSTOMER',
                        'PAYMENT_TEXT21' => 'NONREF',
                        'PAYMENT_TEXT22' => $rowdata[$this->mapping['TRANSACTION_CODE']] ?? '', // Added null coalescing operator
                        'PAYMENT_TEXT23' => $event . " " . strtoupper($name),
                        'PAYMENT_CODE' => $event,
                        'CHARGE_DATE' => date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']] ?? '')), // Added null coalescing operator
                        'CHARGE_TYPE' => $transactionChargeType,
                        'CHARGE_AMOUNT' => str_replace(".", ",", sprintf("%01.2f", $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']])),
                        'CHARGE_NDDT' => 'NONREF',
                        'CHARGE_TEXT00' => 'AMAZON',
                        'CHARGE_TEXT20' => 'AMAZON GEB.',
                        'CHARGE_TEXT21' => $rowdata[$this->mapping['TRANSACTION_CODE']] ?? '', // Added null coalescing operator
                        'CHARGE_TEXT22' => strtoupper($name),
                        'PAYMENT_STATE' => 'S'
                    ];

                    $this->data[] = $mt940;
                    $this->dataCount++;
                }
            }

            $this->mt940param['enddate'] = date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']] ?? '')); // Added null coalescing operator
        }

        // Check if payout is defined before using it
        if (!empty($this->mt940param['payout'])) {
            $this->createPayoutData($this->amountTotal, $this->mt940param['enddate']);
        }

        if ($this->amountTotal < 0) {
            $SH = "D";
            $this->amountTotal = $this->amountTotal * (-1);
        } else {
            $SH = "C";
        }
        $this->mt940param["TotalAmount"] = $this->amountTotal;
        $this->mt940param["TotalSH"] = $SH;
    }

    public function getAllData() {
        return $this->data;
    }

    public function getNext() {
        if ($this->dataPos < $this->dataCount) {
            return $this->data[$this->dataPos++];
        } else {
            return false;
        }
    }

    public function getParameter() {
        return $this->mt940param;
    }

    private function createPayoutData($sumOfDay, $payoutdate) {
        if ($sumOfDay < 0) {
            $sumOfDay = abs($sumOfDay);
            $type = 'D';
        } else {
            $type = 'C';
        }

        $mt940 = [
            'PAYMENT_DATE' => date("ymd", strtotime($payoutdate)),
            'PAYMENT_TYPE' => $type,
            'PAYMENT_AMOUNT' => str_replace(".", ",", sprintf("%01.2f", $sumOfDay)),
            'PAYMENT_NDDT' => '',
            'PAYMENT_TEXT00' => 'AMAZON',
            'PAYMENT_TEXT20' => 'AMAZON PAYOUT ' . $payoutdate,
            'PAYMENT_TEXT21' => '',
            'PAYMENT_TEXT22' => '',
            'PAYMENT_TEXT23' => '',
            'PAYMENT_CODE' => 'PayOut',
            'CHARGE_DATE' => '',
            'CHARGE_TYPE' => '',
            'CHARGE_AMOUNT' => '',
            'CHARGE_NDDT' => '',
            'CHARGE_TEXT00' => '',
            'CHARGE_TEXT20' => '',
            'CHARGE_TEXT21' => '',
            'CHARGE_TEXT22' => '',
            'PAYMENT_STATE' => 'S'
        ];
        $this->data[] = $mt940;
    }

    public function generateMT940() {
        $mt940 = [];

        // Set initial balance
        $mt940[] = ":20:" . "AMZ" . date("ymdHis");
        $mt940[] = ":25:" . $this->mt940param['blz'] . "/" . $this->mt940param['konto'];
        $mt940[] = ":28C:00000";
        $mt940[] = ":60F:" . $this->mt940param['TotalSH'] . $this->mt940param['startdate'] . $this->mt940param['currency'] . "0,00";

        foreach ($this->data as $transaction) {
            $transactionEntry = ":61:" . $transaction['PAYMENT_DATE'] . $transaction['PAYMENT_TYPE'] . $transaction['PAYMENT_AMOUNT'] . "NMSC" . $transaction['PAYMENT_CODE'];
            $descriptionEntry = ":86:" . $transaction['PAYMENT_TEXT00'] . " " . $transaction['PAYMENT_TEXT20'] . " " . $transaction['PAYMENT_TEXT22'];

            // Debug: Output each transaction and description entry
            echo "<pre>Transaction Entry: $transactionEntry</pre>";
            echo "<pre>Description Entry: $descriptionEntry</pre>";

            $mt940[] = $transactionEntry;
            $mt940[] = $descriptionEntry;
        }

        // Add end balance
        $mt940[] = ":62F:" . $this->mt940param['TotalSH'] . $this->mt940param['enddate'] . $this->mt940param['currency'] . $this->mt940param['TotalAmount'];

        // Debug: Output MT940 footer
        echo "<pre>MT940 Footer: " . end($mt940) . "</pre>";

        return implode("\r\n", $mt940);
    }
}

?>
